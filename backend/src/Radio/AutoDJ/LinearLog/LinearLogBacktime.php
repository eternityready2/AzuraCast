<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Radio\AutoDJ\AiredLength;
use App\Radio\AutoDJ\DmcaComplianceListener;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\AutoDJ\StrictProgrammeClock;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Backtimes the hour on air to its Top-of-Hour Station ID from real elapsed
 * time, as FM automation backtimes to a hard sync point.
 *
 * The live queue holds an hour of songs ahead, and the log re-fitted only the
 * lines not yet queued, so an hour was fixed in place an hour before it aired.
 * Whatever it then gained or lost on air (talk, an intro trimmed) was left to
 * the Top-of-Hour swap and its one last song: the swap acted in 136 of 148
 * music hours in the week to Wed 2026-10-14, and when the song it found had to
 * be sped up or slowed down, the hour landed cleanly 73.5% of the time.
 *
 * In the half hour before the ID the queue's times are measured from the song
 * on air (Queue), so most of the hour is real. Where the hour's last song then
 * ends more than half a minute off the ID, one queued song before the swap's
 * final approach gets another song as much longer or shorter, so the last song
 * lands on the ID as it is and the swap has nothing left to do, or only a few
 * seconds. It is the log's own correction; the swap stays the safety net.
 *
 * Only a song still in AzuraCast's queue changes, never one handed to the
 * player, the next one up, the song a DJ introduces, the hour's last song or
 * anything in the swap's final approach. The new song passes the checks the
 * queue applies to a pick: the playlist's schedule, repeats, DMCA limits.
 */
final class LinearLogBacktime
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    public const string BACKTIMED_NOTE = 'Backtimed so its hour lands on the ID; planned: ';

    /** Backtiming starts this long before the ID, with most of the hour aired. */
    public const int WINDOW_SECONDS = 1800;

    /**
     * The swap's final approach (TopOfHourSongSwapSelector::FINAL_APPROACH_SECONDS):
     * songs starting this close to the ID are handed to the player one at a
     * time and landed by the swap at the hand-off.
     */
    public const int SWAP_APPROACH_SECONDS = 720;

    /** An hour whose last song ends within this of the ID is on time (as the page reads it). */
    private const int ON_TIME_SECONDS = 30;

    /** A song this close to its start may be handed to the player at any moment. */
    private const int MIN_LEAD_SECONDS = 120;

    /** A new song must bring the hour at least this much closer to its ID. */
    private const int MIN_GAIN_SECONDS = 20;

    /** Songs backtimed per hour; a larger drift is the swap's. */
    private const int MAX_PER_HOUR = 3;

    /** How long an hour no song could bring closer is left while nothing about it changes. */
    private const int REST_SECONDS = 300;

    public function __construct(
        private readonly TopOfHourClock $clock,
        private readonly LinearLogRefill $refill,
        private readonly LinearLogHour $hour,
        private readonly AiredLength $airedLength,
        private readonly StrictProgrammeClock $strictProgrammeClock,
        private readonly DmcaComplianceListener $dmca,
        private readonly Scheduler $scheduler,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @return int songs backtimed */
    public function backtime(Station $station): int
    {
        try {
            return $this->backtimeHourOnAir($station);
        } catch (Throwable $e) {
            $this->logger->error('Linear Log: backtiming the hour on air failed.', [
                'station_id' => $station->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function backtimeHourOnAir(Station $station): int
    {
        if (!LinearLogRefill::isEnabled($station) || !$this->clock->isEnabled($station)) {
            return 0;
        }

        $now = time();
        $nowAt = new DateTimeImmutable('@' . $now);
        $idAt = $this->clock->getTargetStartFor($station, $nowAt)->getTimestamp();
        $secondsToId = $idAt - $now;
        if (
            $secondsToId > self::WINDOW_SECONDS
            || $secondsToId <= self::SWAP_APPROACH_SECONDS
            || $this->clock->clockWheelOwnsBoundary($station, $this->clock->getNextBoundary($station, $nowAt))
        ) {
            return 0;
        }

        $rows = $this->unplayedRows($station->id);
        $hourRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (int)$row['starts_at'] < $idAt
        ));

        // The queue has to reach the ID: an hour still being filled is the
        // top-up's and the fit's (LinearLogHourFit).
        $queueEnds = 0.0;
        foreach ($rows as $row) {
            $queueEnds = max($queueEnds, (int)$row['starts_at'] + (float)$row['duration']);
        }
        if ([] === $hourRows || $queueEnds < $idAt) {
            return 0;
        }

        // A show, a stream or a Clock Wheel's slot is a timed event: an hour
        // that carries one is not the log's to move.
        foreach ($hourRows as $row) {
            if ($this->isTimedEvent($row)) {
                return 0;
            }
        }

        $closing = $this->closingSong($station, $hourRows);
        if (null === $closing || !self::isMusic($closing)) {
            return 0;
        }

        // A song the queue cut at a clock anchor (a schedule's window ending
        // inside the hour) times everything after it from that anchor. Only
        // the hour's last song may carry the cap that keeps it off the ID.
        foreach ($hourRows as $row) {
            if ((int)$row['id'] !== (int)$closing['id'] && self::isTimedByClock($row)) {
                return 0;
            }
        }

        $closingMedia = $this->em->find(StationMedia::class, (int)$closing['media_id']);
        $closingSeconds = (float)$closing['duration'];
        $landsAt = (int)$closing['starts_at'] + $this->airedLength->lengthOf($closingMedia, $closingSeconds);
        $roomLeft = $idAt - $landsAt;
        if (abs($roomLeft) <= self::ON_TIME_SECONDS) {
            return 0;
        }

        $hourStart = CarbonImmutable::createFromTimestamp($idAt, $station->getTimezoneObject())
            ->startOfHour()
            ->getTimestamp();
        if ($this->backtimedIn($station->id, $hourStart, $idAt) >= self::MAX_PER_HOUR) {
            return 0;
        }

        $restKey = 'linear_log_backtime_rest_' . $station->id . '_' . $hourStart;
        $state = implode(':', [(int)$closing['id'], (int)$closing['media_id'], intdiv((int)round($roomLeft), 10)]);
        if ($state === $this->cache->get($restKey)) {
            return 0;
        }

        $done = $this->backtimeOneSong($station, $rows, $hourRows, $closing, $roomLeft, $idAt, $now);
        if (0 === $done) {
            $this->cache->set($restKey, $state, self::REST_SECONDS);
        }

        return $done;
    }

    /**
     * Give the latest song that may be moved another song as much longer or
     * shorter as the hour's last song ends short of the ID or past it.
     *
     * @param list<array<string, mixed>> $rows every unplayed queue row, in air order
     * @param list<array<string, mixed>> $hourRows those that start before the ID
     * @param array<string, mixed> $closing the hour's last song
     */
    private function backtimeOneSong(
        Station $station,
        array $rows,
        array $hourRows,
        array $closing,
        float $roomLeft,
        int $idAt,
        int $now,
    ): int {
        $moved = $this->movableSong($station, $rows, $hourRows, $closing, $idAt, $now);
        if (null === $moved) {
            return 0;
        }

        $line = $this->em->find(StationLogEntry::class, (int)$moved['log_entry_id']);
        $playlist = $this->em->find(StationPlaylist::class, (int)$moved['playlist_id']);
        if (!$line instanceof StationLogEntry || !$playlist instanceof StationPlaylist) {
            return 0;
        }

        $length = (float)$moved['duration'];
        $wanted = max(LinearLogRefill::MIN_SONG_SECONDS, $length + $roomLeft);
        $startsAt = (int)$moved['starts_at'];
        $airsAt = new DateTimeImmutable('@' . $startsAt);

        // Read before the search: it may clear the entity manager.
        $lineId = $line->id;
        $planned = self::firstPlanned($line);
        $payload = $line->payload ?? [];
        $movedMediaId = (int)$moved['media_id'];

        $pick = $this->refill->pickReplacement(
            $station,
            $airsAt,
            $wanted,
            $wanted + LinearLogRefill::ID_GRACE_SECONDS,
            $playlist,
            $movedMediaId,
            LinearLogSongMatch::Closest,
            fn(StationMedia $media, StationPlaylist $from): bool => $this->mayAirAt($media, $from, $startsAt),
        );
        if (null === $pick) {
            return 0;
        }

        [$media, $from] = $pick;
        $movesBy = $media->getCalculatedLength() - $length;
        $leftAfter = $roomLeft - $movesBy;
        if (abs($leftAfter) > abs($roomLeft) - self::MIN_GAIN_SECONDS) {
            return 0;
        }

        if (!$this->songsAfterMayAir($hourRows, $startsAt, $movesBy)) {
            return 0;
        }

        // The hour's last song must still start before the ID where it then
        // starts, or it opens the next hour and leaves this one short.
        $station = $this->em->find(Station::class, $station->id);
        $closingStartsAt = (int)floor((int)$closing['starts_at'] + $movesBy);
        if (
            !$station instanceof Station
            || $this->hour->opensNextHour($station, $closingStartsAt, (float)$closing['duration'])
        ) {
            return 0;
        }

        $note = mb_substr(self::BACKTIMED_NOTE . $planned, 0, 255);
        if (!$this->putSong((int)$moved['id'], $movedMediaId, $lineId, $media, $from, $payload, $note, $startsAt)) {
            return 0;
        }

        $this->logger->notice('Linear Log: backtimed a queued song so the hour on air lands on its ID.', [
            'queue_id' => (int)$moved['id'],
            'log_entry_id' => $lineId,
            'song' => (string)$media->text,
            'planned' => $planned,
            'was_off_by' => round(-$roomLeft, 1),
            'lands_off_by' => round(-$leftAfter, 1),
        ]);

        return 1;
    }

    /**
     * Every unplayed queue row, in air order, timed as the queue last
     * projected it from the song on air.
     *
     * @return list<array<string, mixed>>
     */
    private function unplayedRows(int $stationId): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT q.id, q.media_id, q.playlist_id, q.log_entry_id, q.sent_to_autodj, q.request_id,
                q.autodj_custom_uri, q.clock_wheel_id, q.clock_wheel_enforce_cap, q.clock_wheel_legal_id_substitute,
                q.clock_wheel_stretch_ratio, q.hour_boundary_enforce_cap, q.top_of_hour_legal_id,
                q.top_of_hour_boundary_at, q.group_playlist_id, q.playlist_chain, q.duration,
                UNIX_TIMESTAMP(q.timestamp_played) AS starts_at,
                m.type AS media_type, p.is_programme
            FROM station_queue q
            LEFT JOIN station_media m ON m.id = q.media_id
            LEFT JOIN station_playlists p ON p.id = q.playlist_id
            WHERE q.station_id = ? AND q.is_played = 0
            ORDER BY q.timestamp_played ASC, q.id ASC',
            [$stationId]
        );
    }

    /** @param array<string, mixed> $row */
    private function isTimedEvent(array $row): bool
    {
        if (
            !empty($row['is_programme'])
            || null !== $row['autodj_custom_uri']
            || null !== $row['clock_wheel_id']
            || null === $row['media_id']
            || (float)$row['duration'] >= LinearLogRefill::PROGRAMME_MIN_SECONDS
        ) {
            return true;
        }

        $playlist = null !== $row['playlist_id']
            ? $this->em->find(StationPlaylist::class, (int)$row['playlist_id'])
            : null;

        return $playlist instanceof StationPlaylist && $this->strictProgrammeClock->isPlayedByStrictLane($playlist);
    }

    /**
     * The hour's last song: the last row that starts before the ID and is not
     * held to open the next hour (LinearLogHour).
     *
     * @param list<array<string, mixed>> $hourRows
     * @return array<string, mixed>|null
     */
    private function closingSong(Station $station, array $hourRows): ?array
    {
        for ($i = count($hourRows) - 1; $i >= 0; $i--) {
            $row = $hourRows[$i];
            if (
                empty($row['top_of_hour_legal_id'])
                && !$this->hour->opensNextHour($station, (int)$row['starts_at'], (float)$row['duration'])
            ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The latest song of the hour the log may give another song: an ordinary
     * queued song not yet handed to the player, behind the next song up and
     * the song a DJ introduces (the first unplayed one), clear of the swap's
     * final approach, whose line is the log's to change.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $hourRows
     * @param array<string, mixed> $closing
     * @return array<string, mixed>|null
     */
    private function movableSong(
        Station $station,
        array $rows,
        array $hourRows,
        array $closing,
        int $idAt,
        int $now,
    ): ?array {
        $firstUnsentId = null;
        $firstMusicId = null;
        foreach ($rows as $row) {
            if (null === $firstUnsentId && empty($row['sent_to_autodj'])) {
                $firstUnsentId = (int)$row['id'];
            }
            if (null === $firstMusicId && self::isMusic($row)) {
                $firstMusicId = (int)$row['id'];
            }
        }

        for ($i = count($hourRows) - 1; $i >= 0; $i--) {
            $row = $hourRows[$i];
            $startsAt = (int)$row['starts_at'];
            if (
                (int)$row['id'] === (int)$closing['id']
                || (int)$row['id'] === $firstUnsentId
                || (int)$row['id'] === $firstMusicId
                || !empty($row['sent_to_autodj'])
                || $startsAt < $now + self::MIN_LEAD_SECONDS
                || $startsAt >= $idAt - self::SWAP_APPROACH_SECONDS
                || !self::isOrdinarySong($row)
            ) {
                continue;
            }

            $line = $this->em->find(StationLogEntry::class, (int)$row['log_entry_id']);
            if (
                $line instanceof StationLogEntry
                && StationLogEntry::STATUS_QUEUED === $line->status
                && !$line->is_locked
                && $line->queue_id === (int)$row['id']
                && LinearLogHourGuard::isHourBound($line)
                && !str_starts_with((string)$line->note, StationLogEntry::REOFFERED_NOTE)
                && !str_starts_with((string)$line->note, self::BACKTIMED_NOTE)
                && null === $this->strictProgrammeClock->holdAt($station, new DateTimeImmutable('@' . $startsAt))
            ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * True when every row after the moved one may still air where it then
     * starts, as ScheduleWindowGuard judges it: otherwise it takes the row out
     * and leaves the hole this was to close.
     *
     * @param list<array<string, mixed>> $hourRows
     */
    private function songsAfterMayAir(array $hourRows, int $movedStartsAt, float $movesBy): bool
    {
        foreach ($hourRows as $row) {
            if ((int)$row['starts_at'] <= $movedStartsAt || null === $row['playlist_id']) {
                continue;
            }

            $playlist = $this->em->find(StationPlaylist::class, (int)$row['playlist_id']);
            $startsAt = new DateTimeImmutable('@' . (int)floor((int)$row['starts_at'] + $movesBy));
            if ($playlist instanceof StationPlaylist && !$this->scheduler->isPlaylistAllowedAt($playlist, $startsAt)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private static function isTimedByClock(array $row): bool
    {
        return !empty($row['hour_boundary_enforce_cap'])
            || !empty($row['clock_wheel_enforce_cap'])
            || null !== $row['clock_wheel_stretch_ratio']
            || null !== $row['top_of_hour_boundary_at'];
    }

    /** @param array<string, mixed> $row */
    private static function isMusic(array $row): bool
    {
        return LinearLogRefill::MUSIC_TYPE === ($row['media_type'] ?? null);
    }

    /**
     * A song the queue chose freely: no cap, tempo target, Clock Wheel slot,
     * request, Top-of-Hour boundary, playlist group or chain.
     *
     * @param array<string, mixed> $row
     */
    private static function isOrdinarySong(array $row): bool
    {
        return self::isMusic($row)
            && null !== $row['log_entry_id']
            && null !== $row['playlist_id']
            && null === $row['request_id']
            && null === $row['clock_wheel_id']
            && null === $row['clock_wheel_stretch_ratio']
            && null === $row['top_of_hour_boundary_at']
            && null === $row['group_playlist_id']
            && null === $row['playlist_chain']
            && empty($row['clock_wheel_enforce_cap'])
            && empty($row['clock_wheel_legal_id_substitute'])
            && empty($row['hour_boundary_enforce_cap'])
            && empty($row['top_of_hour_legal_id']);
    }

    /**
     * True when $media may air at $startsAt as a queue pick may: within DMCA
     * limits, and not a song QueuedRepeatGuard would take back out (aired
     * within the duplicate-prevention window, or already in the queue).
     */
    private function mayAirAt(StationMedia $media, StationPlaylist $playlist, int $startsAt): bool
    {
        $station = $playlist->station;

        $entry = StationQueue::fromMedia($station, $media);
        $entry->playlist = $playlist;
        if (!$this->dmca->allows($entry, $station, new DateTimeImmutable('@' . $startsAt))) {
            return false;
        }

        $conn = $this->em->getConnection();
        $queued = $conn->fetchOne(
            'SELECT 1 FROM station_queue WHERE station_id = ? AND song_id = ? AND is_played = 0 LIMIT 1',
            [$station->id, $media->song_id]
        );
        if (false !== $queued) {
            return false;
        }

        $minutes = $station->backend_config->duplicate_prevention_time_range;

        return $minutes <= 0 || false === $conn->fetchOne(
            'SELECT 1 FROM song_history WHERE station_id = ? AND song_id = ? AND timestamp_start >= ? LIMIT 1',
            [
                $station->id,
                $media->song_id,
                CarbonImmutable::createFromTimestamp($startsAt - $minutes * 60, 'UTC')->format('Y-m-d H:i:s'),
            ]
        );
    }

    /** Songs already backtimed in the hour starting at $hourStart. */
    private function backtimedIn(int $stationId, int $hourStart, int $idAt): int
    {
        return (int)$this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM station_log_entries
            WHERE station_id = ? AND planned_at >= ? AND planned_at < ? AND note LIKE ?',
            [$stationId, $hourStart, $idAt, self::BACKTIMED_NOTE . '%']
        );
    }

    /**
     * Give the queue row and its log line the new song together, only while
     * the row is still in AzuraCast's queue with the song it was read with:
     * a row handed to the player in the meantime airs as it was.
     *
     * @param array<string, mixed> $payload the line's payload as it was read
     */
    private function putSong(
        int $queueId,
        int $readMediaId,
        int $lineId,
        StationMedia $media,
        StationPlaylist $playlist,
        array $payload,
        string $note,
        int $startsAt,
    ): bool {
        $conn = $this->em->getConnection();

        return (bool)$conn->transactional(function () use (
            $conn,
            $queueId,
            $readMediaId,
            $lineId,
            $media,
            $playlist,
            $payload,
            $note,
            $startsAt
        ): bool {
            $current = $conn->fetchAssociative(
                'SELECT media_id, sent_to_autodj, is_played FROM station_queue WHERE id = ? FOR UPDATE',
                [$queueId]
            );
            if (
                false === $current
                || (int)$current['media_id'] !== $readMediaId
                || !empty($current['sent_to_autodj'])
                || !empty($current['is_played'])
            ) {
                return false;
            }

            // The line first: one locked or taken out in the meantime keeps
            // its song, and so does its row.
            unset($payload['album'], $payload['song_id'], $payload['media_type']);
            $lineChanged = $conn->update(
                'station_log_entries',
                [...LinearLogRefill::songFields($media, $playlist, $payload), 'note' => $note],
                ['id' => $lineId, 'status' => StationLogEntry::STATUS_QUEUED, 'is_locked' => 0]
            );
            if (0 === (int)$lineChanged) {
                return false;
            }

            $song = StationQueue::fromMedia($playlist->station, $media);
            $conn->update('station_queue', [
                'media_id' => $media->id,
                'playlist_id' => $playlist->id,
                'song_id' => $song->song_id,
                'text' => $song->text,
                'title' => $song->title,
                'artist' => $song->artist,
                'album' => $song->album,
                'duration' => $media->getCalculatedLength(),
            ], ['id' => $queueId]);

            $conn->executeStatement(
                'UPDATE station_playlist_media SET last_played = ?, is_queued = 0
                WHERE playlist_id = ? AND media_id = ?',
                [$startsAt, $playlist->id, $media->id]
            );

            return true;
        });
    }

    /** What the plan first had in a line, through re-fits. */
    private static function firstPlanned(StationLogEntry $line): string
    {
        $note = (string)$line->note;
        foreach ([LinearLogHourFit::REFITTED_NOTE, LinearLogHourFit::REFITTED_BEFORE_NOTE] as $prefix) {
            if (str_starts_with($note, $prefix)) {
                return substr($note, strlen($prefix));
            }
        }

        return $line->text ?? 'unknown';
    }
}
