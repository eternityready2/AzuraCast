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
use App\Radio\AutoDJ\LinearLogPreviewContext;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\AutoDJ\StrictProgrammeClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Refills the saved log where a line was dropped, as FM automation does: the
 * slot gets another line in the log, instead of a hole the AutoDJ fills only
 * once the queue reaches it (or a shorter hour that pulls everything after it
 * forward).
 *
 * - A line the schedule guard dropped at a projected air time that turned out
 *   wrong, while its own playlist may play when the log planned it, is put back
 *   once (Wed 2026-10-07 7pm: every song planned after Altered Stories was
 *   judged inside the show's window and dropped).
 * - Any other dropped song is replaced by a song of about the same length from
 *   the playlist the AutoDJ would play at that time, so the rest of the hour
 *   keeps its timing; what difference is left moves the hour's later lines.
 * - A dropped short item (promo, liner) that cannot be replaced closes up: the
 *   hour's later lines move up, as they do on air.
 *
 * Only the hour the drop is in changes. Locked lines, programmes and anything
 * already handed to the queue stay where they are, and each dropped line is
 * handled once.
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
    private const float MIN_SONG_SECONDS = 90.0;

    /**
     * At least this long is a programme: never refilled, never moved. The
     * station's shows run 25 minutes and up; live worship songs reach 11.
     */
    private const float PROGRAMME_MIN_SECONDS = 1200.0;

    /** How far a replacement's length may differ from the dropped line's before it counts as a poor match. */
    private const float LENGTH_MATCH_SECONDS = 20.0;

    /** Closest-length candidates the replacement is drawn from, so it does not always pick the same song. */
    private const int CANDIDATE_POOL_SIZE = 3;

    /** A slot starting closer than this to the ID is left to the Top-of-Hour hold and filler. */
    private const int MIN_ROOM_BEFORE_ID_SECONDS = 60;

    /** How far past the ID a planned line may end: the swap's tolerance. */
    private const int ID_GRACE_SECONDS = 5;

    /** StationMedia::$type of an ordinary song. */
    private const string MUSIC_TYPE = 'music';

    /** Drop reasons that leave nothing to refill. */
    private const array NOT_REFILLED = [
        // The hour already ended or overran; its time has gone.
        'Dropped: its hour ran long',
        // A strict programme's own source airs in that time.
        'Dropped: a strict programme owns this air time',
        // The slot is in the past: it was handed to Liquidsoap and never aired.
        'Dropped: marked played but never aired',
        'Dropped: removed from the queue before it aired',
    ];

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LinearLogPreviewContext $previewContext,
        private readonly Scheduler $scheduler,
        private readonly StrictProgrammeClock $strictProgrammeClock,
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
     * @return int lines restored or refilled
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

            if (in_array($outcome, ['restored', 'refilled', 'closed_up'], true)) {
                $done++;
            }
        }

        return $done;
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

        if (
            $duration >= self::PROGRAMME_MIN_SECONDS
            || 'scheduled_programme' === ($payload['source_type'] ?? null)
            || null === $line['media_id']
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

        // Dropped by the schedule guard at a projected time, while the log's own
        // time is fine for its playlist: the plan was right, so it stands.
        if (
            str_contains($note, ' may not play at ')
            && empty($payload['restored'])
            && $playlist instanceof StationPlaylist
            && $this->scheduler->isPlaylistAllowedAt($playlist, $at)
        ) {
            $payload['restored'] = true;
            $this->em->getConnection()->update('station_log_entries', [
                'status' => StationLogEntry::STATUS_PLANNED,
                'note' => mb_substr('Restored: ' . $note, 0, 255),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'queue_id' => null,
            ], ['id' => $id]);

            $this->logger->notice('Linear Log: restored a line dropped at a projected time its plan did not have.', [
                'log_entry_id' => $id,
                'planned_at' => $plannedAt,
                'reason' => $note,
            ]);
            return 'restored';
        }

        // Media-less lines returned as programmes above.
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
        $newId = $this->writeLine($station, $media, $pickPlaylist, $plannedAt, $id);

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
     * @return array{StationMedia, StationPlaylist}|null
     */
    private function pickReplacement(
        Station $station,
        DateTimeImmutable $at,
        float $duration,
        float $maxLength,
        ?StationPlaylist $ownPlaylist,
        ?int $excludeMediaId,
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
        [$nearSongIds, $nearArtists] = $this->logNear($station, $at->getTimestamp(), max(60, $range) * 60);

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

            $length = $media->getCalculatedLength();
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

        $pool = array_values(array_filter(
            array_slice($scored, 0, self::CANDIDATE_POOL_SIZE),
            static fn(array $c): bool => $c['diff'] <= $window,
        ));
        $chosen = [] !== $pool ? $pool[random_int(0, count($pool) - 1)] : $scored[0];

        return [$chosen['media'], $playlist];
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
     * Song ids and artists of the log's lines within $seconds of $at.
     *
     * @return array{array<string, true>, array<string, true>}
     */
    private function logNear(Station $station, int $at, int $seconds): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT e.payload, m.song_id, m.artist
            FROM station_log_entries e
            LEFT JOIN station_media m ON m.id = e.media_id
            WHERE e.station_id = ? AND e.status <> ?
            AND e.planned_at BETWEEN ? AND ?',
            [$station->id, StationLogEntry::STATUS_DROPPED, $at - $seconds, $at + $seconds]
        );

        $songIds = [];
        $artists = [];
        foreach ($rows as $row) {
            if (!empty($row['song_id'])) {
                $songIds[(string)$row['song_id']] = true;
            }
            $artist = mb_strtolower(trim((string)($row['artist'] ?? '')));
            if ('' !== $artist) {
                $artists[$artist] = true;
            }
        }

        return [$songIds, $artists];
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

    private function writeLine(
        Station $station,
        StationMedia $media,
        StationPlaylist $playlist,
        int $plannedAt,
        int $refillOf,
    ): int {
        $conn = $this->em->getConnection();
        $sequence = 1 + (int)$conn->fetchOne(
            'SELECT COALESCE(MAX(sequence), 0) FROM station_log_entries WHERE station_id = ?',
            [$station->id]
        );

        $conn->insert('station_log_entries', [
            'station_id' => $station->id,
            'media_id' => $media->id,
            'playlist_id' => $playlist->id,
            'planned_at' => $plannedAt,
            'sequence' => $sequence,
            'duration' => max(1.0, $media->getCalculatedLength()),
            'status' => StationLogEntry::STATUS_PLANNED,
            'text' => mb_substr((string)$media->text, 0, 255) ?: null,
            'title' => null !== $media->title ? mb_substr($media->title, 0, 255) : null,
            'artist' => null !== $media->artist ? mb_substr($media->artist, 0, 255) : null,
            'payload' => json_encode([
                'album' => $media->album,
                'song_id' => $media->song_id,
                'media_type' => $media->type,
                'refill_of' => $refillOf,
            ], JSON_THROW_ON_ERROR),
            'note' => 'Refilled: replaces a dropped line',
            'is_locked' => 0,
            'created_at' => time(),
        ]);

        return (int)$conn->lastInsertId();
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
