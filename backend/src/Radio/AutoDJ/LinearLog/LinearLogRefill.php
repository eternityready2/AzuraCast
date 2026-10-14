<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\AiredLength;
use App\Radio\AutoDJ\AirTimePerSong;
use App\Radio\AutoDJ\LinearLogPreviewContext;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\AutoDJ\StrictProgrammeClock;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Refills the saved log where a line was dropped, as FM automation does: the
 * slot gets another line in the log, instead of a hole the AutoDJ fills only
 * once the queue reaches it (or a shorter hour that pulls everything after it
 * forward).
 *
 * - A dropped song is replaced by a song of about the same length from
 *   the playlist the AutoDJ would play at that time, so the rest of the hour
 *   keeps its timing; what difference is left moves the hour's later lines.
 * - A dropped short item (promo, liner) that cannot be replaced closes up: the
 *   hour's later lines move up, as they do on air.
 *
 * Only the hour the drop is in changes. Locked lines, programmes and anything
 * already handed to the queue stay where they are, and each dropped line is
 * handled once.
 *
 * The song for a slot, and the line it is written as, are also what
 * LinearLogHourFit keeps the hours ahead fitted to their Station ID with.
 */
final class LinearLogRefill
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    /** A line due sooner than this is left alone: the queue is already on it. */
    private const int MIN_LEAD_SECONDS = 120;

    /** How far ahead dropped lines are looked for. */
    private const int HORIZON_SECONDS = 50 * 3600;

    /** Lines handled per pass; the rest wait for the next minute. */
    private const int MAX_PER_PASS = 10;

    /** Shorter than this is a short item (promo, liner, imaging), not a song. */
    public const float MIN_SONG_SECONDS = 90.0;

    /**
     * At least this long is a programme: never refilled, never moved. The
     * station's shows run 25 minutes and up; live worship songs reach 11.
     */
    public const float PROGRAMME_MIN_SECONDS = 1200.0;

    /** How far a replacement's length may differ from the dropped line's before it counts as a poor match. */
    private const float LENGTH_MATCH_SECONDS = 20.0;

    /** Closest-length candidates the replacement is drawn from, so it does not always pick the same song. */
    private const int CANDIDATE_POOL_SIZE = 3;

    /** Candidates a caller's own check is run on, at most, per search. */
    private const int MAX_ACCEPT_CHECKS = 30;

    /** A slot starting closer than this to the ID is left to the Top-of-Hour hold and filler. */
    private const int MIN_ROOM_BEFORE_ID_SECONDS = 60;

    /** How far past the ID a planned line may end: the swap's tolerance. */
    public const int ID_GRACE_SECONDS = 5;

    /** StationMedia::$type of an ordinary song. */
    public const string MUSIC_TYPE = 'music';

    /** An hour whose lines end at least this far short of the ID has room for another song. */
    public const int MIN_TOP_UP_SECONDS = 120;

    /** About one song: a longer shortfall is filled a song at a time. */
    private const float TYPICAL_SONG_SECONDS = 240.0;

    /** More top-up lines than an hour can hold, so only a runaway stops here. */
    private const int MAX_TOP_UPS_PER_HOUR = 20;

    /** Drop reasons that leave nothing to refill. */
    private const array NOT_REFILLED = [
        // The hour already ended or overran; its time has gone.
        'Dropped: its hour ran long',
        // The hour is full without it.
        LinearLogHourFit::NO_ROOM_NOTE,
        // A strict programme's own source airs in that time.
        'Dropped: a strict programme owns this air time',
        // The slot is in the past: it was handed to Liquidsoap and never aired.
        'Dropped: marked played but never aired',
        'Dropped: removed from the queue before it aired',
        // A show's own part before it, in the same airing, still holds the air.
        LinearLogRemovedFiles::NO_PART_NOTE,
    ];

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LinearLogPreviewContext $previewContext,
        private readonly Scheduler $scheduler,
        private readonly StrictProgrammeClock $strictProgrammeClock,
        private readonly AirTimePerSong $airTimePerSong,
        private readonly AiredLength $airedLength,
    ) {
    }

    public static function isEnabled(Station $station): bool
    {
        return LinearLogPlayout::isPlayoutEnabled($station)
            && $station->backend_config->linear_log_rule_refill_dropped;
    }

    /**
     * Refill dropped lines in the station's future log.
     *
     * @return int lines refilled
     */
    public function refill(Station $station): int
    {
        if (!self::isEnabled($station)) {
            return 0;
        }

        $stationId = $station->id;
        $now = time();
        $conn = $this->em->getConnection();

        /** @var list<array<string, mixed>> $candidates */
        $candidates = $conn->fetchAllAssociative(
            'SELECT id, planned_at, duration, note, payload, media_id, playlist_id
            FROM station_log_entries
            WHERE station_id = ? AND status = ? AND is_locked = 0
            AND planned_at >= ? AND planned_at <= ?
            ORDER BY planned_at ASC, sequence ASC',
            [
                $stationId,
                StationLogEntry::STATUS_DROPPED,
                $now + self::MIN_LEAD_SECONDS,
                $now + self::HORIZON_SECONDS,
            ]
        );

        $done = 0;
        $handled = 0;
        foreach ($candidates as $line) {
            $payload = self::decodePayload($line['payload']);
            if (!empty($payload['refill'])) {
                continue;
            }
            if ($handled >= self::MAX_PER_PASS) {
                break;
            }
            $handled++;

            try {
                $outcome = $this->handle($stationId, $line, $payload);
            } catch (Throwable $e) {
                $this->logger->error('Linear Log refill failed for a dropped line.', [
                    'log_entry_id' => (int)$line['id'],
                    'error' => $e->getMessage(),
                ]);
                $outcome = 'failed';
            }

            if (in_array($outcome, ['refilled', 'closed_up'], true)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Add a song to the end of the hour the live queue is in when that hour's
     * lines end short of the Top-of-Hour ID, as FM automation fills a short hour
     * from the log instead of leaving it to whatever is picked at air time.
     *
     * An hour comes up short inside the queue's reach, where a dropped line can
     * no longer be refilled in place: a repeat or a hand removal the queue
     * closed up around, or the hour's first song given up to a pre-ID spot. The
     * AutoDJ then picked the songs for the end of the hour as the queue got
     * there (22:50 and 22:57, Wed 2026-10-07). Written here first, they are log
     * lines: on the page an hour ahead, open to hand edits, from the playlist
     * the AutoDJ would play then, checked against the log for repeats and never
     * longer than the room before the ID.
     *
     * One line per pass; the next pass measures again. The last two minutes
     * before the ID stay the Top-of-Hour swap's to fit. An hour that carries a
     * stream or strict-lane programme is left alone.
     *
     * @return int lines added
     */
    public function topUp(Station $station): int
    {
        if (!self::isEnabled($station)) {
            return 0;
        }

        // An earlier refill in this pass may have cleared the entity manager.
        $stationId = $station->id;
        $station = $this->em->find(Station::class, $stationId);
        if (!$station instanceof Station) {
            return 0;
        }

        $conn = $this->em->getConnection();

        // Where the live queue ends: the next slot playout is asked to fill.
        $tail = $conn->fetchAssociative(
            'SELECT MAX(UNIX_TIMESTAMP(timestamp_played)) AS starts_at,
                MAX(UNIX_TIMESTAMP(timestamp_played) + duration) AS ends_at
            FROM station_queue
            WHERE station_id = ? AND is_played = 0 AND top_of_hour_legal_id = 0',
            [$stationId]
        ) ?: [];
        $queueEnd = max(time(), (int)ceil((float)($tail['ends_at'] ?? 0)));

        // The hour the queue is in is the hour its last item starts in. That
        // item is usually an hour's last song, landed on the ID, and it ends a
        // few seconds past it; judged from there, the next hour's lines ran on
        // from :00 with no room for its ID and News Hour, and a song was added
        // to hours that were already full (12pm, 1pm and 2pm, Mon 2026-10-12).
        $hourStart = CarbonImmutable::createFromTimestamp(
            max(time(), (int)($tail['starts_at'] ?? 0)),
            $station->getTimezoneObject()
        )
            ->startOfHour()
            ->getTimestamp();
        $hourEnd = $hourStart + 3600;
        $idAt = $hourEnd - 1;

        $strictLane = [];
        foreach ($station->playlists as $playlist) {
            if ($this->strictProgrammeClock->isPlayedByStrictLane($playlist)) {
                $strictLane[$playlist->id] = true;
            }
        }

        // The hour's lines still to be queued air one after another from there,
        // each taking what a song really takes in this hour beyond its file.
        $perSong = $this->airTimePerSong->secondsBeyondFile($station, $hourStart);
        $plannedSeconds = 0;
        $lastPlannedAt = $hourStart;
        foreach (
            $conn->fetchAllAssociative(
                'SELECT status, planned_at, duration, media_id, playlist_id FROM station_log_entries
                WHERE station_id = ? AND status IN (?, ?) AND planned_at < ? AND planned_at + duration > ?',
                [$stationId, StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED, $hourEnd, $hourStart]
            ) as $line
        ) {
            if (null === $line['media_id'] || isset($strictLane[(int)$line['playlist_id']])) {
                return 0;
            }
            if (StationLogEntry::STATUS_PLANNED === $line['status'] && (int)$line['planned_at'] >= $hourStart) {
                $plannedSeconds += (int)ceil((float)$line['duration'] + $perSong);
                $lastPlannedAt = max($lastPlannedAt, (int)$line['planned_at']);
            }
        }

        $endsAt = $queueEnd + $plannedSeconds;
        $shortBy = $idAt - $endsAt;
        if ($shortBy < self::MIN_TOP_UP_SECONDS) {
            return 0;
        }

        return $this->addSong($station, $hourStart, $idAt, $endsAt, $shortBy, $lastPlannedAt, false);
    }

    /**
     * Write a song as the last line of the hour starting at $hourStart, whose
     * lines end at $endsAt, $shortBy seconds short of the ID that starts at
     * $idAt: what topUp() adds to the hour the queue is in, and
     * LinearLogHourFit to an hour ahead of it.
     *
     * @param int $lastPlannedAt planned time of the hour's last planned line
     * @param bool $landsOnId the song that fills the hour is picked to end on
     *     the ID (LinearLogSongMatch::OnId)
     * @return int lines added
     */
    public function addSong(
        Station $station,
        int $hourStart,
        int $idAt,
        int $endsAt,
        int $shortBy,
        int $lastPlannedAt,
        bool $landsOnId,
    ): int {
        // A top-up line that was dropped means something takes lines out of this
        // hour; adding more would only feed it. That holds for one the hour
        // turned out to have no room for (LinearLogHourFit) as well: an hour
        // given a song and then left without room for it is not given another.
        $topUps = $this->em->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS total, COALESCE(SUM(status = ?), 0) AS dropped FROM station_log_entries
            WHERE station_id = ? AND planned_at >= ? AND planned_at < ? AND payload LIKE ?',
            [StationLogEntry::STATUS_DROPPED, $station->id, $hourStart, $hourStart + 3600, '%"top_up":true%']
        );
        if (
            false === $topUps
            || (int)$topUps['dropped'] > 0
            || (int)$topUps['total'] >= self::MAX_TOP_UPS_PER_HOUR
        ) {
            return 0;
        }

        // A song's file is that much shorter than the time it takes on air
        // here. One that ends on the ID has nothing after it to make room for.
        $songs = max(1, (int)floor($shortBy / self::TYPICAL_SONG_SECONDS));
        $landsOnId = $landsOnId && 1 === $songs;
        $perSong = $landsOnId ? 0.0 : $this->airTimePerSong->secondsBeyondFile($station, $hourStart);
        $pick = $this->pickReplacement(
            $station,
            CarbonImmutable::createFromTimestamp($endsAt, 'UTC')->toDateTimeImmutable(),
            $shortBy / $songs - $perSong,
            $shortBy + self::ID_GRACE_SECONDS - $perSong,
            null,
            null,
            $landsOnId ? LinearLogSongMatch::OnId : LinearLogSongMatch::About
        );
        if (null === $pick) {
            return 0;
        }

        [$media, $playlist] = $pick;

        // After the hour's last planned line, so playout takes it last.
        $plannedAt = min(max($endsAt, $lastPlannedAt + 1), $idAt - 1);
        $newId = $this->writeLine(
            $station,
            $media,
            $playlist,
            $plannedAt,
            'Filled: the hour was running short',
            ['top_up' => true]
        );

        $this->logger->notice('Linear Log: topped up an hour that was running short.', [
            'log_entry_id' => $newId,
            'planned_at' => $plannedAt,
            'short_by' => $shortBy,
            'song' => $media->text,
        ]);

        return 1;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $payload
     */
    private function handle(int $stationId, array $line, array $payload): string
    {
        $id = (int)$line['id'];
        $plannedAt = (int)$line['planned_at'];
        $duration = (float)$line['duration'];
        $note = (string)($line['note'] ?? '');

        foreach (self::NOT_REFILLED as $reason) {
            if (str_starts_with($note, $reason)) {
                return $this->markHandled($id, $payload, 'not_refilled');
            }
        }

        // A line with no file is a stream or a strict-lane programme's marker,
        // unless its file was removed from the library (LinearLogRemovedFiles):
        // that slot is a song's and is refilled like any other.
        if (
            $duration >= self::PROGRAMME_MIN_SECONDS
            || 'scheduled_programme' === ($payload['source_type'] ?? null)
            || (null === $line['media_id'] && LinearLogRemovedFiles::REMOVED_NOTE !== $note)
        ) {
            return $this->markHandled($id, $payload, 'programme');
        }

        // A line written to refill another is not refilled again: if it was
        // dropped too, whatever drops lines there would only drop the next one.
        if (!empty($payload['refill_of'])) {
            return $this->markHandled($id, $payload, 'not_refilled');
        }

        $station = $this->em->find(Station::class, $stationId);
        if (!$station instanceof Station) {
            return 'failed';
        }

        // The end of the hour belongs to the Top-of-Hour swap and hold: a line
        // that ran into the ID, or a slot in its last minute, is theirs to fill.
        // These are mostly the final songs a build re-planned around the ID.
        $idAt = CarbonImmutable::createFromTimestamp($plannedAt, $station->getTimezoneObject())
            ->startOfHour()
            ->addHour()
            ->getTimestamp() - 1;
        $roomToId = $idAt - $plannedAt;
        if ($roomToId < self::MIN_ROOM_BEFORE_ID_SECONDS || $plannedAt + $duration > $idAt + self::ID_GRACE_SECONDS) {
            return $this->markHandled($id, $payload, 'top_of_hour');
        }

        if ($this->isSlotCovered($stationId, $id, $plannedAt, $duration)) {
            return $this->markHandled($id, $payload, 'covered');
        }

        // The live queue already holds lines past this slot: a line written here
        // now would air after them, out of order and past its hour. The queue
        // closes up around the drop instead, as it does on air.
        if ($this->isPastQueueReach($stationId, $plannedAt)) {
            return $this->markHandled($id, $payload, 'queue_reached');
        }

        $at = CarbonImmutable::createFromTimestamp($plannedAt, 'UTC')->toDateTimeImmutable();
        $playlist = null !== $line['playlist_id']
            ? $this->em->find(StationPlaylist::class, (int)$line['playlist_id'])
            : null;

        // A line whose file was removed has none to leave out.
        $excludeMediaId = (int)$line['media_id'];
        $pick = $this->pickReplacement(
            $station,
            $at,
            $duration,
            $roomToId + self::ID_GRACE_SECONDS,
            $playlist,
            $excludeMediaId
        );

        if (null === $pick) {
            if ($duration < self::MIN_SONG_SECONDS) {
                // A short item with nothing to take its place: the hour closes up.
                $this->shiftHour($stationId, $plannedAt, -(int)round($duration));
                $this->markHandled($id, $payload, 'closed_up');
                return 'closed_up';
            }

            $this->logger->notice('Linear Log: no replacement fits a dropped line; the AutoDJ fills it on air.', [
                'log_entry_id' => $id,
                'planned_at' => $plannedAt,
            ]);
            return $this->markHandled($id, $payload, 'no_fit');
        }

        [$media, $pickPlaylist] = $pick;
        $newId = $this->writeLine(
            $station,
            $media,
            $pickPlaylist,
            $plannedAt,
            'Refilled: replaces a dropped line',
            ['refill_of' => $id]
        );

        $delta = (int)round($media->getCalculatedLength() - $duration);
        if (0 !== $delta) {
            $this->shiftHour($stationId, $plannedAt, $delta, $newId);
        }

        $this->markHandled($id, $payload, 'refilled', $newId);

        $this->logger->notice('Linear Log: refilled a dropped line.', [
            'log_entry_id' => $id,
            'new_log_entry_id' => $newId,
            'planned_at' => $plannedAt,
            'song' => $media->text,
            'length_change' => $delta,
        ]);

        return 'refilled';
    }

    /**
     * A song for $at from the playlist the AutoDJ would play then, as close to
     * $duration as possible and not repeating a song or artist the log already
     * has near that time.
     *
     * Finding that playlist clears the entity manager (autoDjPlaylistAt()):
     * entities the caller read before are detached afterwards.
     *
     * @param LinearLogSongMatch $match how close to $duration the song has to
     *     be, and by which length
     * @return array{StationMedia, StationPlaylist}|null
     */
    public function pickReplacement(
        Station $station,
        DateTimeImmutable $at,
        float $duration,
        float $maxLength,
        ?StationPlaylist $ownPlaylist,
        ?int $excludeMediaId,
        LinearLogSongMatch $match = LinearLogSongMatch::About,
        ?Closure $accept = null,
    ): ?array {
        $wantShort = $duration < self::MIN_SONG_SECONDS;

        $playlist = null;
        if (
            $ownPlaylist instanceof StationPlaylist
            && $this->isRefillSource($ownPlaylist, $wantShort)
            && $this->scheduler->isPlaylistAllowedAt($ownPlaylist, $at)
        ) {
            $playlist = $ownPlaylist;
        } elseif (!$wantShort) {
            $playlist = $this->autoDjPlaylistAt($station, $at);
        }

        if (!$playlist instanceof StationPlaylist) {
            return null;
        }

        $range = $station->backend_config->duplicate_prevention_time_range;
        [$nearSongIds, $nearArtists, $nearTitles] = $this->logNear($station, $at->getTimestamp(), max(60, $range) * 60);

        $window = $wantShort ? $duration * 0.5 : max(self::LENGTH_MATCH_SECONDS, $duration * 0.15);

        /** @var StationPlaylistMedia[] $rows */
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT spm, m FROM App\Entity\StationPlaylistMedia spm
                JOIN spm.media m
                WHERE spm.playlist = :playlist
                AND m.length > 0
                AND m.length < :programme
                AND m.type NOT IN (:idTypes)
                ORDER BY spm.last_played ASC
            DQL
        )->setParameter('playlist', $playlist)
            ->setParameter('programme', self::PROGRAMME_MIN_SECONDS)
            ->setParameter('idTypes', StationMediaTypes::stationIdTypeValues())
            ->getResult();

        $scored = [];
        foreach ($rows as $spm) {
            $media = $spm->media;
            if ($media->id === $excludeMediaId) {
                continue;
            }
            if (isset($nearSongIds[$media->song_id])) {
                continue;
            }
            $artist = mb_strtolower(trim((string)$media->artist));
            if ('' !== $artist && isset($nearArtists[$artist])) {
                continue;
            }
            $title = mb_strtolower(trim((string)$media->title));
            if ('' !== $title && isset($nearTitles[$title])) {
                continue;
            }

            $length = LinearLogSongMatch::OnId === $match
                ? $this->airedLength->lengthOf($media)
                : $media->getCalculatedLength();
            if ($length > $maxLength) {
                // Never planned to run into the ID.
                continue;
            }
            $diff = abs($length - $duration);
            if ($wantShort && $diff > $window) {
                continue;
            }
            $scored[] = ['diff' => $diff, 'media' => $media];
        }

        if ([] === $scored) {
            return null;
        }

        // Closest length first; among equally close, least recently played
        // (the query order, kept by the stable sort).
        usort($scored, static fn(array $a, array $b): int => $a['diff'] <=> $b['diff']);

        if (null !== $accept) {
            $scored = self::firstAccepted($scored, $playlist, $accept);
            if ([] === $scored) {
                return null;
            }
        }

        $drawWithin = LinearLogSongMatch::About === $match ? $window : (float)self::ID_GRACE_SECONDS;
        $pool = array_values(array_filter(
            array_slice($scored, 0, self::CANDIDATE_POOL_SIZE),
            static fn(array $c): bool => $c['diff'] <= $drawWithin,
        ));
        $chosen = [] !== $pool ? $pool[random_int(0, count($pool) - 1)] : $scored[0];

        return [$chosen['media'], $playlist];
    }

    /**
     * The best candidates, in order, that $accept lets through: as many as
     * the draw takes, from at most MAX_ACCEPT_CHECKS looked at.
     *
     * @param list<array{diff: float, media: StationMedia}> $scored
     * @param Closure(StationMedia, StationPlaylist): bool $accept
     * @return list<array{diff: float, media: StationMedia}>
     */
    private static function firstAccepted(array $scored, StationPlaylist $playlist, Closure $accept): array
    {
        $accepted = [];
        foreach (array_slice($scored, 0, self::MAX_ACCEPT_CHECKS) as $candidate) {
            if ($accept($candidate['media'], $playlist)) {
                $accepted[] = $candidate;
                if (count($accepted) >= self::CANDIDATE_POOL_SIZE) {
                    break;
                }
            }
        }

        return $accepted;
    }

    /**
     * The playlist the AutoDJ itself would pick from at $at: one pick from the
     * real pipeline (schedules, weights, groups, holiday overrides, validators),
     * made in the preview context inside a transaction that is rolled back, as
     * the log builder makes its plan.
     */
    private function autoDjPlaylistAt(Station $station, DateTimeImmutable $at): ?StationPlaylist
    {
        $stationId = $station->id;
        $playlistId = null;

        $conn = $this->em->getConnection();
        $outerLevel = $conn->getTransactionNestingLevel();
        $this->previewContext->begin();
        try {
            $conn->beginTransaction();

            for ($attempt = 0; $attempt < 3 && null === $playlistId; $attempt++) {
                $event = new BuildQueue($station, $at, $at);
                $this->dispatcher->dispatch($event);

                foreach ($event->getNextSongs() as $row) {
                    $media = $row->media;
                    if (
                        $media instanceof StationMedia
                        && self::MUSIC_TYPE === $media->type
                        && $row->playlist instanceof StationPlaylist
                        && null === $row->request
                    ) {
                        $playlistId = $row->playlist->id;
                    }
                }
            }
        } finally {
            // Undo only the preview's own pick, never a caller's transaction.
            while ($conn->getTransactionNestingLevel() > $outerLevel) {
                try {
                    $conn->rollBack();
                } catch (Throwable) {
                    $conn->close();
                    break;
                }
            }
            $this->previewContext->end();
            if (!$this->em->isOpen()) {
                $this->em->open();
            }
            $this->em->clear();
        }

        if (null === $playlistId) {
            return null;
        }

        $playlist = $this->em->find(StationPlaylist::class, $playlistId);
        if (!$playlist instanceof StationPlaylist || $playlist->station->id !== $stationId) {
            return null;
        }

        return $this->isRefillSource($playlist, false) ? $playlist : null;
    }

    private function isRefillSource(StationPlaylist $playlist, bool $short): bool
    {
        if (!$playlist->is_enabled || $this->strictProgrammeClock->isPlayedByStrictLane($playlist)) {
            return false;
        }

        // Short items come back from their own (jingle/promo) playlist only.
        return $short || !$playlist->is_jingle;
    }

    /**
     * Song ids, artists and titles of the log's lines within $seconds of $at.
     * A title counts on its own, as the AutoDJ's DuplicatePrevention counts it:
     * another artist's recording of a song is still the same song to a listener.
     *
     * @return array{array<string, true>, array<string, true>, array<string, true>}
     */
    private function logNear(Station $station, int $at, int $seconds): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT e.payload, m.song_id, m.artist, m.title
            FROM station_log_entries e
            LEFT JOIN station_media m ON m.id = e.media_id
            WHERE e.station_id = ? AND e.status <> ?
            AND e.planned_at BETWEEN ? AND ?',
            [$station->id, StationLogEntry::STATUS_DROPPED, $at - $seconds, $at + $seconds]
        );

        $songIds = [];
        $artists = [];
        $titles = [];
        foreach ($rows as $row) {
            if (!empty($row['song_id'])) {
                $songIds[(string)$row['song_id']] = true;
            }
            $artist = mb_strtolower(trim((string)($row['artist'] ?? '')));
            if ('' !== $artist) {
                $artists[$artist] = true;
            }
            $title = mb_strtolower(trim((string)($row['title'] ?? '')));
            if ('' !== $title) {
                $titles[$title] = true;
            }
        }

        return [$songIds, $artists, $titles];
    }

    /** True when a line planned after this slot has already been handed to the queue. */
    private function isPastQueueReach(int $stationId, int $plannedAt): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT id FROM station_log_entries
            WHERE station_id = ? AND status = ? AND planned_at > ? LIMIT 1',
            [$stationId, StationLogEntry::STATUS_QUEUED, $plannedAt]
        );
    }

    /** True when an open line already holds most of this slot. */
    private function isSlotCovered(int $stationId, int $id, int $plannedAt, float $duration): bool
    {
        $end = $plannedAt + $duration;
        $overlap = (float)$this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(GREATEST(0, LEAST(planned_at + duration, ?) - GREATEST(planned_at, ?))), 0)
            FROM station_log_entries
            WHERE station_id = ? AND id <> ? AND status IN (?, ?)
            AND planned_at < ? AND planned_at + duration > ?',
            [
                $end,
                $plannedAt,
                $stationId,
                $id,
                StationLogEntry::STATUS_PLANNED,
                StationLogEntry::STATUS_QUEUED,
                $end,
                $plannedAt,
            ]
        );

        return $overlap >= $duration / 2;
    }

    /** @param array<string, mixed> $payload what the line is, beyond the song: refill_of or top_up */
    public function writeLine(
        Station $station,
        StationMedia $media,
        StationPlaylist $playlist,
        int $plannedAt,
        string $note,
        array $payload,
    ): int {
        $conn = $this->em->getConnection();
        $sequence = 1 + (int)$conn->fetchOne(
            'SELECT COALESCE(MAX(sequence), 0) FROM station_log_entries WHERE station_id = ?',
            [$station->id]
        );

        $conn->insert('station_log_entries', [
            ...self::songFields($media, $playlist, $payload),
            'station_id' => $station->id,
            'planned_at' => $plannedAt,
            'sequence' => $sequence,
            'status' => StationLogEntry::STATUS_PLANNED,
            'note' => $note,
            'is_locked' => 0,
            'created_at' => time(),
        ]);

        return (int)$conn->lastInsertId();
    }

    /**
     * The columns of a log line that name the song it plays.
     *
     * @param array<string, mixed> $payload what the line is, beyond the song: refill_of or top_up
     * @return array<string, mixed>
     */
    public static function songFields(StationMedia $media, StationPlaylist $playlist, array $payload): array
    {
        return [
            'media_id' => $media->id,
            'playlist_id' => $playlist->id,
            'duration' => max(1.0, $media->getCalculatedLength()),
            'text' => mb_substr((string)$media->text, 0, 255) ?: null,
            'title' => null !== $media->title ? mb_substr($media->title, 0, 255) : null,
            'artist' => null !== $media->artist ? mb_substr($media->artist, 0, 255) : null,
            'payload' => json_encode([
                'album' => $media->album,
                'song_id' => $media->song_id,
                'media_type' => $media->type,
                ...$payload,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Move the planned lines after $plannedAt in the same hour by $delta
     * seconds, up to the hour's end or the next programme, whichever is first.
     * Locked lines and programmes keep their times.
     */
    private function shiftHour(int $stationId, int $plannedAt, int $delta, ?int $skipId = null): void
    {
        if (0 === $delta) {
            return;
        }

        $station = $this->em->find(Station::class, $stationId);
        if (!$station instanceof Station) {
            return;
        }

        $hourEnd = CarbonImmutable::createFromTimestamp($plannedAt, $station->getTimezoneObject())
            ->startOfHour()
            ->addHour()
            ->getTimestamp();

        $conn = $this->em->getConnection();
        $nextProgramme = $conn->fetchOne(
            'SELECT MIN(planned_at) FROM station_log_entries
            WHERE station_id = ? AND status IN (?, ?) AND planned_at > ? AND planned_at < ?
            AND (duration >= ? OR is_locked = 1)',
            [
                $stationId,
                StationLogEntry::STATUS_PLANNED,
                StationLogEntry::STATUS_QUEUED,
                $plannedAt,
                $hourEnd,
                self::PROGRAMME_MIN_SECONDS,
            ]
        );
        $until = (false !== $nextProgramme && null !== $nextProgramme) ? (int)$nextProgramme : $hourEnd;

        $conn->executeStatement(
            'UPDATE station_log_entries SET planned_at = planned_at + ?
            WHERE station_id = ? AND status = ? AND is_locked = 0
            AND planned_at > ? AND planned_at < ? AND duration < ? AND id <> ?',
            [
                $delta,
                $stationId,
                StationLogEntry::STATUS_PLANNED,
                $plannedAt,
                $until,
                self::PROGRAMME_MIN_SECONDS,
                $skipId ?? 0,
            ]
        );
    }

    /** @param array<string, mixed> $payload */
    private function markHandled(int $id, array $payload, string $outcome, ?int $newId = null): string
    {
        $payload['refill'] = $outcome;
        if (null !== $newId) {
            $payload['refilled_by'] = $newId;
        }

        $this->em->getConnection()->update(
            'station_log_entries',
            ['payload' => json_encode($payload, JSON_THROW_ON_ERROR)],
            ['id' => $id]
        );

        return $outcome;
    }

    /** @return array<string, mixed> */
    private static function decodePayload(mixed $raw): array
    {
        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
