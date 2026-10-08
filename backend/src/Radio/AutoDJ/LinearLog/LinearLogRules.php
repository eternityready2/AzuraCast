<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationLogEntry;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Radio\AutoDJ\Scheduler;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Operator override rules for the saved 24-hour linear log.
 *
 * FM automation lets the human running the station correct the log by hand and
 * by rule, without waiting for the scheduler to be fixed. These rules are that
 * safety net: they walk the future of the saved log and take out any line that
 * may not play at the time it is planned for -- a song from another playlist
 * that leaked into a scheduled programme block, or a scheduled line planned
 * before its own window opens.
 *
 * A dropped line keeps its row with status "dropped" and a note saying which
 * rule took it out, so the log records what happened instead of quietly
 * changing. Lines an operator locked by hand are never touched: a hand edit is
 * more specific than a standing rule, and the human always wins.
 */
final class LinearLogRules
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    /** Planned repeats taken out per pass: what one LinearLogRefill pass puts back. */
    private const int MAX_REPEATS_PER_PASS = 10;

    /** A line ending this close to the ID, or past it, is the hour's final song. */
    private const int FINAL_SONG_TOLERANCE_SECONDS = 10;

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
    }

    /**
     * Apply the station's enabled rules to every future line of its saved log.
     *
     * @return array{checked: int, dropped: int, skipped_locked: int, reasons: list<string>,
     *     dropped_ids: list<int>}
     */
    public function apply(Station $station, ?int $from = null): array
    {
        $config = $station->backend_config;
        $enforceWindows = $config->linear_log_rule_enforce_windows;
        $dropOutside = $config->linear_log_rule_drop_outside_window;

        $result = ['checked' => 0, 'dropped' => 0, 'skipped_locked' => 0, 'reasons' => [], 'dropped_ids' => []];
        if (!$enforceWindows && !$dropOutside) {
            return $result;
        }

        $from ??= time();

        /** @var StationLogEntry[] $entries */
        $entries = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
                AND e.status IN (:open)
                AND e.planned_at >= :from
                ORDER BY e.planned_at ASC, e.sequence ASC
            DQL
        )->setParameter('station', $station)
            ->setParameter('open', [StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED])
            ->setParameter('from', $from)
            ->getResult();

        foreach ($entries as $entry) {
            $result['checked']++;

            $violation = $this->findViolation($entry, $enforceWindows, $dropOutside);
            if (null === $violation) {
                continue;
            }

            // A hand edit outranks a standing rule.
            if ($entry->is_locked) {
                $result['skipped_locked']++;
                continue;
            }

            if (!$this->drop($entry, $violation)) {
                continue;
            }
            $result['dropped']++;
            $result['dropped_ids'][] = $entry->id;
            if (!in_array($violation, $result['reasons'], true)) {
                $result['reasons'][] = $violation;
            }
        }

        if ($result['dropped'] > 0) {
            $this->em->flush();
            $this->logger->notice(
                'Linear Log rules dropped planned lines that may not play at their planned time.',
                [
                    'station' => $station->name,
                    'dropped' => $result['dropped'],
                    'checked' => $result['checked'],
                    'reasons' => $result['reasons'],
                ]
            );
        }

        return $result;
    }

    /**
     * Take out a planned song the log already airs within the station's
     * duplicate-prevention window before it, so LinearLogRefill puts another
     * song in its slot.
     *
     * The pickers keep repeats out of the plan they write. One gets in later:
     * the Top-of-Hour swap airs a song the log has planned again further on, or
     * a hand edit does. QueuedRepeatGuard removed that second copy only as it
     * reached the live queue, when the queue already held the lines after it
     * and the slot could no longer be refilled, so the hour ran short and the
     * AutoDJ filled the end of it ("Worthy", swapped in at 20:53 and planned
     * again for 22:35, Wed 2026-10-07). Found while the line is still only
     * planned, its slot is refilled in the log.
     *
     * Music only, as in QueuedRepeatGuard. A locked line, a line already in the
     * live queue and an hour's final song (the Top-of-Hour swap places that
     * one) are left alone.
     *
     * @return int lines dropped
     */
    public function dropRepeats(Station $station): int
    {
        $repeats = $this->findRepeats($station, time());
        if ([] === $repeats) {
            return 0;
        }

        $minutes = $station->backend_config->duplicate_prevention_time_range;
        $tz = $station->getTimezoneObject();

        $dropped = 0;
        foreach ($repeats as $id => $earlierAt) {
            $entry = $this->em->find(StationLogEntry::class, $id);
            if (
                !$entry instanceof StationLogEntry
                || StationLogEntry::STATUS_PLANNED !== $entry->status
                || $entry->is_locked
            ) {
                continue;
            }

            $reason = sprintf(
                'the same song airs at %s, within %d minutes',
                CarbonImmutable::createFromTimestamp($earlierAt, $tz)->format('g:i:s A'),
                $minutes
            );
            if ($this->drop($entry, $reason)) {
                $dropped++;
            }
        }

        if ($dropped > 0) {
            $this->em->flush();
            $this->logger->notice(
                'Linear Log rules dropped planned songs that repeat within the duplicate-prevention window.',
                ['station' => $station->name, 'dropped' => $dropped, 'window_minutes' => $minutes]
            );
        }

        return $dropped;
    }

    /**
     * Planned lines that repeat a song the log airs within the
     * duplicate-prevention window before them.
     *
     * @return array<int, int> log line id => air time of the earlier play
     */
    private function findRepeats(Station $station, int $now): array
    {
        $window = $station->backend_config->duplicate_prevention_time_range * 60;
        if ($window <= 0) {
            return [];
        }

        $conn = $this->em->getConnection();

        // What each queued line really plays: the Top-of-Hour swap changes the
        // queue row, and the line only takes the new song once it has aired.
        $queuedSongs = $conn->fetchAllKeyValue(
            'SELECT log_entry_id, song_id FROM station_queue
            WHERE station_id = ? AND is_played = 0 AND log_entry_id IS NOT NULL',
            [$station->id]
        );

        // planned_at bounds the scan on its index; a line airs within minutes of it.
        $lines = $conn->fetchAllAssociative(
            'SELECT e.id, e.status, e.is_locked, e.duration, m.song_id, m.type,
                COALESCE(e.aired_at, e.planned_at) AS airs_at
            FROM station_log_entries e
            JOIN station_media m ON m.id = e.media_id
            WHERE e.station_id = ? AND e.status <> ? AND e.planned_at >= ?
            ORDER BY airs_at ASC, e.sequence ASC',
            [$station->id, StationLogEntry::STATUS_DROPPED, $now - $window - 3600]
        );

        /** @var array<string, int> $lastAiredAt song id => air time of its latest play that stays in the log */
        $lastAiredAt = [];
        $repeats = [];
        foreach ($lines as $line) {
            $id = (int)$line['id'];
            $airsAt = (int)$line['airs_at'];
            $songId = (string)(
                StationLogEntry::STATUS_QUEUED === $line['status']
                    ? ($queuedSongs[$id] ?? $line['song_id'])
                    : $line['song_id']
            );
            if ('' === $songId) {
                continue;
            }

            $earlierAt = $lastAiredAt[$songId] ?? null;
            if (
                null !== $earlierAt
                && $airsAt - $earlierAt <= $window
                && $airsAt >= $now
                && StationLogEntry::STATUS_PLANNED === $line['status']
                && !$line['is_locked']
                && 'music' === ($line['type'] ?? 'music')
                && (float)$line['duration'] < LinearLogRefill::PROGRAMME_MIN_SECONDS
                && !self::isFinalSong($station, $airsAt, (float)$line['duration'])
            ) {
                // Taken out, so it is not a play a later line can repeat.
                $repeats[$id] = $earlierAt;
                if (count($repeats) >= self::MAX_REPEATS_PER_PASS) {
                    break;
                }
                continue;
            }

            $lastAiredAt[$songId] = $airsAt;
        }

        return $repeats;
    }

    /** True when the line runs up to the Top-of-Hour ID at :59:59, or past it. */
    private static function isFinalSong(Station $station, int $airsAt, float $duration): bool
    {
        $idAt = CarbonImmutable::createFromTimestamp($airsAt, $station->getTimezoneObject())
            ->startOfHour()
            ->addHour()
            ->getTimestamp() - 1;

        return $airsAt + $duration >= $idAt - self::FINAL_SONG_TOLERANCE_SECONDS;
    }

    /**
     * Why this line may not play when it is planned, or null when it is fine.
     */
    private function findViolation(
        StationLogEntry $entry,
        bool $enforceWindows,
        bool $dropOutside
    ): ?string {
        $at = new DateTimeImmutable('@' . $entry->planned_at);
        $station = $entry->station;

        $clockWheelId = $entry->payload['clock_wheel_id'] ?? null;
        if (null !== $clockWheelId) {
            $clockWheel = $this->em->find(StationClockWheel::class, (int)$clockWheelId);
            if ($clockWheel instanceof StationClockWheel && $clockWheel->station->id === $station->id) {
                if ($this->scheduler->isClockWheelAllowedAt($clockWheel, $at)) {
                    return null;
                }

                return $dropOutside
                    ? sprintf('Clock wheel "%s" is not scheduled at this time', $clockWheel->name)
                    : null;
            }
        }

        $playlist = $entry->playlist;
        if (!$playlist instanceof StationPlaylist) {
            return null;
        }

        if ($this->scheduler->isPlaylistAllowedAt($playlist, $at)) {
            return null;
        }

        // A scheduled playlist planned outside its own window: the "starts too
        // early" case. isPlaylistAllowedAt() can also say no here purely from
        // the cross-playlist "a narrower block wins" comparison -- some other
        // schedule row opened at the same instant -- and that must never drop
        // the playlist's own occurrence from the live queue: a playlist always
        // owns its air inside its own real scheduled time, full stop.
        if ($playlist->schedule_items->count() > 0) {
            if ($this->scheduler->isPlaylistScheduledToPlayNow($playlist, $at, excludeSpecialRules: true)) {
                return null;
            }

            return $dropOutside
                ? sprintf('"%s" is planned outside its own scheduled window', $playlist->name)
                : null;
        }

        if (!$enforceWindows) {
            return null;
        }

        // An unscheduled playlist inside somebody else's block: the "leaked into
        // a scheduled playlist" case. Name the block so the note is useful.
        $owner = $this->openBlockName($station, $at);

        return null !== $owner
            ? sprintf('"%s" leaked into the scheduled block "%s"', $playlist->name, $owner)
            : sprintf('"%s" may not play at this time', $playlist->name);
    }

    /** Name of a scheduled playlist whose window is open at $at. */
    private function openBlockName(Station $station, DateTimeImmutable $at): ?string
    {
        foreach ($station->playlists as $playlist) {
            if (
                $playlist->schedule_items->count() > 0
                && $playlist->is_enabled
                && $this->scheduler->isPlaylistScheduledToPlayNow($playlist, $at, excludeSpecialRules: true)
            ) {
                return $playlist->name;
            }
        }

        return null;
    }

    /**
     * Take a line out of the plan, keeping it in the log as a dropped line so the
     * operator can see the rule fired, and pull its queue row if it had one.
     *
     * A line whose track Liquidsoap has already loaded is left alone: nothing
     * here stops it airing, and dropping it would leave the song on air with a
     * dropped line and a second song refilled into its slot.
     *
     * @return bool whether the line was dropped
     */
    private function drop(StationLogEntry $entry, string $reason): bool
    {
        // Found by the line, not by its queue_id: that is only filled in by the
        // next reconcile pass, up to a minute after the line is queued.
        $queueRow = StationLogEntry::STATUS_QUEUED === $entry->status
            ? $this->em->createQuery(
                <<<'DQL'
                    SELECT sq FROM App\Entity\StationQueue sq
                    WHERE sq.station = :station AND sq.log_entry_id = :entryId AND sq.is_played = 0
                    ORDER BY sq.id DESC
                DQL
            )->setParameter('station', $entry->station)
                ->setParameter('entryId', $entry->id)
                ->setMaxResults(1)
                ->getOneOrNullResult()
            : null;

        if ($queueRow instanceof StationQueue) {
            if ($queueRow->sent_to_autodj) {
                return false;
            }
            $this->em->remove($queueRow);
        }

        $entry->status = StationLogEntry::STATUS_DROPPED;
        $entry->note = mb_substr('Dropped by log rule: ' . $reason, 0, 255);
        $entry->queue_id = null;
        $this->em->persist($entry);

        return true;
    }
}
