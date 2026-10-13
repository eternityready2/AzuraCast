<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Radio\AutoDJ\AiredLength;
use App\Radio\AutoDJ\LinearLogBuilder;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use App\Radio\AutoDJ\StrictProgrammeClock;
use Carbon\CarbonImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Keeps the hours of the saved log that are still ahead of the live queue
 * fitted to their Top-of-Hour Station ID, as FM automation keeps its log,
 * instead of leaving an hour as it was planned until the Top-of-Hour swap
 * rescues it at the ID.
 *
 * A line is timed when the plan is built, and nothing measured its hour again
 * before it aired. Hours planned at 3am on Mon 2026-10-12, with every song
 * timed at its file length, read up to 2:42 over and 1:23 short of their ID on
 * the page that afternoon, and would have until they aired: only the hour the
 * live queue was in was looked at, and only given a song when it was two
 * minutes or more short (LinearLogRefill::topUp()). Each minute now, every
 * hour ahead of that one is measured where the page measures it
 * (LinearLogStore::liveEntries()) and brought back to its ID whichever way it
 * is out:
 *
 * - A line that cannot start before the ID does not air (LinearLogHour). It is
 *   dropped now, as playout drops it once the hour has ended.
 * - An hour two minutes or more short is given a song, as the queue's hour is.
 * - An hour whose last song ends more than half a minute off the ID gets
 *   another song in that line, one that ends on the ID; see landClosingSong().
 *
 * The measure is the plan's own: file lengths, and what songs have really
 * taken on air in that hour (AirTimePerSong). It is an estimate, and the
 * Top-of-Hour swap still lands each hour on its ID when it airs. This keeps
 * the plan, and what the page shows of it, no further off than the estimate.
 *
 * Only planned lines change, and only in hours the queue has not reached: the
 * hour playout is taking lines from is timed by the queue itself, its last
 * song is the swap's to fit, and it is topped up as it always was. A locked
 * line is the operator's. A show is a timed event, and an hour whose show runs
 * through the ID, or that carries a stream or strict-lane programme, is left
 * alone. So is an hour with no Station ID at its top to be fitted to.
 */
final class LinearLogHourFit
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    public const string NO_ROOM_NOTE = 'Dropped: its hour has no room for it before the ID';

    private const string REFITTED_NOTE = 'Re-fitted to land on the top of the hour; planned: ';

    /** Lines changed per pass; the rest wait for the next minute. */
    private const int MAX_PER_PASS = 10;

    /**
     * An hour whose last line ends within this of the ID is on time: the page
     * reads it so, and the Top-of-Hour swap and Liquidsoap's fit land what is
     * left by themselves.
     */
    private const int ON_TIME_SECONDS = 30;

    /** The ID that closes an hour starts within this before the hour's end, or on it. */
    private const int CLOSING_ID_SECONDS = 120;

    /**
     * How long an hour ahead that no song could bring closer to its ID is left
     * as it is, while nothing about it changes: the search reads a whole
     * playlist, and a minute later it finds the same.
     */
    private const int REST_SECONDS = 900;

    public function __construct(
        private readonly LinearLogRefill $refill,
        private readonly LinearLogStore $store,
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly LinearLogBuilder $builder,
        private readonly LinearLogHour $hour,
        private readonly StrictProgrammeClock $strictProgrammeClock,
        private readonly AiredLength $airedLength,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Fit the station's hours ahead of the live queue.
     *
     * @return int lines dropped, added or re-fitted
     */
    public function fit(Station $station): int
    {
        if (!LinearLogRefill::isEnabled($station)) {
            return 0;
        }

        // The refill in the same pass may have cleared the entity manager.
        $stationId = $station->id;
        $station = $this->em->find(Station::class, $stationId);
        if (!$station instanceof Station) {
            return 0;
        }

        // Around the ID the queue's times slide with the wall clock, and a
        // build writes the whole plan back when it ends: the hours are
        // measured once the top of the hour has settled, as a build waits for
        // it (LinearLogBuilder), and never under a build.
        $snapshot = $this->snapshotStore->get($station);
        if (
            empty($snapshot['entries'])
            || in_array($snapshot['status'] ?? null, ['queued', 'building'], true)
            || $this->builder->secondsUntilClearOfTopOfHour($station) > 0
        ) {
            return 0;
        }

        $now = time();
        $entries = $this->store->liveEntries(
            $station,
            $snapshot['entries'],
            $station->backend_config->linear_log_hours
        );

        // Where the live queue ends is the next slot playout fills, with a
        // line of the hour that slot airs in (LinearLogPlayout::nextLineFor()).
        // That hour and the ones behind it are the queue's.
        $queueEnd = max($now, (int)ceil((float)$this->em->getConnection()->fetchOne(
            'SELECT MAX(UNIX_TIMESTAMP(timestamp_played) + duration) FROM station_queue
            WHERE station_id = ? AND is_played = 0 AND top_of_hour_legal_id = 0',
            [$stationId]
        )));
        $takingFrom = $this->hour->endOf($station, $queueEnd) - 3600;

        $done = 0;
        foreach ($this->hoursToAir($station, $entries, $now) as $hour) {
            if ($done >= self::MAX_PER_PASS) {
                break;
            }
            if ($hour['start'] <= $takingFrom) {
                continue;
            }

            try {
                $done += $this->fitHour($stationId, $hour);
            } catch (Throwable $e) {
                $this->logger->error('Linear Log: fitting an hour to its ID failed.', [
                    'hour_starts_at' => $hour['start'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $done;
    }

    /**
     * The hours still to air as the page lists them, each with the lines that
     * air before its Station ID and the lines it has no room for.
     *
     * An hour is fitted to the ID that closes it and measured from the one
     * that opens it. Where no ID is listed at a boundary (a Clock Wheel owns
     * it, the ID is off, or it has been moved more than two minutes ahead of
     * the top of the hour), the page runs lines on across it and playout does
     * not, so the hours on either side are not listed as they will air and are
     * left as planned. So are an hour that has ended, one the log stops
     * inside, one with nothing listed before its ID (the show of the hour
     * before is still on air) and one whose own show runs through the ID:
     * none has an ending to fit.
     *
     * @param list<array<string, mixed>> $entries from LinearLogStore::liveEntries()
     * @return list<array{
     *     start: int,
     *     id_at: int,
     *     lines: non-empty-list<array<string, mixed>>,
     *     leftovers: list<array<string, mixed>>
     * }>
     */
    private function hoursToAir(Station $station, array $entries, int $now): array
    {
        $tz = $station->getTimezoneObject();

        $logEnds = 0.0;
        $byHour = [];
        foreach ($entries as $entry) {
            $at = (int)($entry['played_at'] ?? 0);
            if ($at <= 0 || StationLogEntry::STATUS_DROPPED === ($entry['log_status'] ?? null)) {
                continue;
            }

            $logEnds = max($logEnds, $at + (float)($entry['duration'] ?? 0));
            $byHour[CarbonImmutable::createFromTimestamp($at, $tz)->startOfHour()->getTimestamp()][] = $entry;
        }
        ksort($byHour);

        // When each Top-of-Hour Station ID listed starts.
        $idStarts = [];
        foreach ($entries as $entry) {
            if (!empty($entry['top_of_hour_legal_id'])) {
                $idStarts[] = (int)($entry['played_at'] ?? 0);
            }
        }

        $hours = [];
        foreach ($byHour as $start => $items) {
            $end = $start + 3600;
            if ($end <= $now || $logEnds < $end) {
                continue;
            }

            // The hour on air opened in the past, whatever opened it.
            $idAt = self::idAtTopOfHour($idStarts, $end);
            if (null === $idAt || ($start > $now && null === self::idAtTopOfHour($idStarts, $start))) {
                continue;
            }

            $lines = [];
            $leftovers = [];
            $showRunsOn = false;
            foreach ($items as $item) {
                if (!empty($item['over_scheduled'])) {
                    $leftovers[] = $item;
                    continue;
                }

                $at = (int)$item['played_at'];
                if ($at >= $idAt) {
                    continue;
                }

                $lines[] = $item;
                $seconds = (float)($item['duration'] ?? 0);
                $isShow = 'scheduled_programme' === ($item['source_type'] ?? null)
                    || $seconds >= LinearLogRefill::PROGRAMME_MIN_SECONDS;
                $showRunsOn = $showRunsOn || ($isShow && $at + $seconds > $idAt);
            }

            if ([] !== $lines && !$showRunsOn) {
                $hours[] = ['start' => $start, 'id_at' => $idAt, 'lines' => $lines, 'leftovers' => $leftovers];
            }
        }

        return $hours;
    }

    /**
     * When the Top-of-Hour Station ID at the top of the hour $topOfHour
     * starts: up to two minutes before it (a second before, as a rule) or on
     * it. Null when none is listed there.
     *
     * @param list<int> $idStarts when each listed Station ID starts
     */
    private static function idAtTopOfHour(array $idStarts, int $topOfHour): ?int
    {
        foreach ($idStarts as $at) {
            if ($at >= $topOfHour - self::CLOSING_ID_SECONDS && $at <= $topOfHour) {
                return $at;
            }
        }

        return null;
    }

    /**
     * Bring one hour ahead of the queue back to its ID.
     *
     * @param array{
     *     start: int,
     *     id_at: int,
     *     lines: non-empty-list<array<string, mixed>>,
     *     leftovers: list<array<string, mixed>>
     * } $hour
     * @return int lines dropped, added or re-fitted
     */
    private function fitHour(int $stationId, array $hour): int
    {
        // The hour's last line is the one that has to end on the ID.
        $closing = $hour['lines'][0];
        $hasQueuedLine = false;
        foreach ($hour['lines'] as $line) {
            if (self::landsAt($line) >= self::landsAt($closing)) {
                $closing = $line;
            }
            $hasQueuedLine = $hasQueuedLine || StationLogEntry::STATUS_QUEUED === ($line['log_status'] ?? null);
        }
        $offBy = self::landsAt($closing) - $hour['id_at'];

        // Where another line would start: behind the last one and whatever
        // airs after it.
        $endsAt = (int)ceil(
            (int)$closing['played_at'] + (float)($closing['air_seconds'] ?? $closing['duration'] ?? 0)
        );
        $shortBy = $hour['id_at'] - $endsAt;

        // Two things are true of every hour ahead of the queue: nothing in it
        // is queued, and a line is left over only because the lines before it
        // fill the hour. Where the list says otherwise it does not describe
        // the hour as it will air (a long show from the hour before holds the
        // air, or a line is out of its place in the list), and acting on it
        // would empty the hour or fill it twice. Nothing in it is changed.
        if ($hasQueuedLine || ([] !== $hour['leftovers'] && $shortBy > LinearLogHour::ID_HOLD_WINDOW_SECONDS)) {
            return 0;
        }

        $leftovers = [];
        foreach ($hour['leftovers'] as $line) {
            if (self::isPlannedAndUnlocked($line)) {
                $leftovers[] = (int)$line['log_entry_id'];
            }
        }

        $isOff = abs($offBy) > self::ON_TIME_SECONDS;
        if ([] === $leftovers && !$isOff) {
            return 0;
        }

        $station = $this->em->find(Station::class, $stationId);
        if (!$station instanceof Station) {
            return 0;
        }

        // Before anything is written: an hour that carries a stream or
        // strict-lane programme is not the queue's to fill.
        $openLines = $this->openLinesOf($stationId, $hour['start']);
        if ($this->carriesProgramme($station, $openLines)) {
            return 0;
        }

        $done = $this->dropForNoRoom($stationId, $leftovers);
        if (!$isOff) {
            return $done;
        }

        // An hour that nothing could be found for rests while it stays as it
        // was: the search reads a whole playlist, and finds the same a minute
        // later.
        $restKey = 'linear_log_fit_rest_' . $stationId . '_' . $hour['start'];
        $state = implode(':', [
            (int)($closing['log_entry_id'] ?? 0),
            (string)($closing['song_id'] ?? ''),
            count($hour['lines']),
            intdiv((int)round($offBy), 10),
        ]);
        if ($state === $this->cache->get($restKey)) {
            return $done;
        }

        $changed = 0;
        if ($shortBy >= LinearLogRefill::MIN_TOP_UP_SECONDS) {
            // After the hour's last planned line, so playout takes it last.
            $lastPlannedAt = $hour['start'];
            foreach ($openLines as $line) {
                if (StationLogEntry::STATUS_PLANNED === $line['status'] && (int)$line['planned_at'] >= $hour['start']) {
                    $lastPlannedAt = max($lastPlannedAt, (int)$line['planned_at']);
                }
            }
            $changed = $this->refill->addSong(
                $station,
                $hour['start'],
                $hour['id_at'],
                $endsAt,
                $shortBy,
                $lastPlannedAt,
                true
            );
        }
        if (0 === $changed) {
            $changed = $this->landClosingSong($stationId, $hour['id_at'], $closing, $offBy);
        }
        if (0 === $changed) {
            $this->cache->set($restKey, $state, self::REST_SECONDS);
        }

        return $done + $changed;
    }

    /**
     * When a listed line ends on air. A song plays from its AutoCue start to
     * its end cue, the length the plan and the Top-of-Hour swap land an hour's
     * last song on the ID with.
     *
     * @param array<string, mixed> $line
     */
    private static function landsAt(array $line): float
    {
        return (int)$line['played_at'] + (float)($line['aired_duration'] ?? $line['duration'] ?? 0);
    }

    /** @param array<string, mixed> $line */
    private static function isPlannedAndUnlocked(array $line): bool
    {
        return !empty($line['log_entry_id'])
            && StationLogEntry::STATUS_PLANNED === ($line['log_status'] ?? null)
            && empty($line['is_locked']);
    }

    /**
     * The saved lines still to air that hold air time in the hour starting at $hourStart.
     *
     * @return list<array<string, mixed>>
     */
    private function openLinesOf(int $stationId, int $hourStart): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT status, planned_at, media_id, playlist_id FROM station_log_entries
            WHERE station_id = ? AND status IN (?, ?) AND planned_at < ? AND planned_at + duration > ?',
            [
                $stationId,
                StationLogEntry::STATUS_PLANNED,
                StationLogEntry::STATUS_QUEUED,
                $hourStart + 3600,
                $hourStart,
            ]
        );
    }

    /**
     * True when one of the hour's lines is a stream (it has no file) or a
     * programme the strict lane plays from its own source.
     *
     * @param list<array<string, mixed>> $openLines
     */
    private function carriesProgramme(Station $station, array $openLines): bool
    {
        $strictLane = [];
        foreach ($station->playlists as $playlist) {
            if ($this->strictProgrammeClock->isPlayedByStrictLane($playlist)) {
                $strictLane[$playlist->id] = true;
            }
        }

        foreach ($openLines as $line) {
            if (null === $line['media_id'] || isset($strictLane[(int)$line['playlist_id']])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop planned lines their hour has no room for. A line claimed for the
     * queue or locked by hand in the meantime stays.
     *
     * @param list<int> $ids
     */
    private function dropForNoRoom(int $stationId, array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        $dropped = (int)$this->em->getConnection()->executeStatement(
            'UPDATE station_log_entries SET status = ?, note = ?
            WHERE station_id = ? AND id IN (?) AND status = ? AND is_locked = 0',
            [StationLogEntry::STATUS_DROPPED, self::NO_ROOM_NOTE, $stationId, $ids, StationLogEntry::STATUS_PLANNED],
            [3 => ArrayParameterType::INTEGER]
        );

        if ($dropped > 0) {
            $this->logger->notice('Linear Log: dropped planned lines their hour has no room for before its ID.', [
                'dropped' => $dropped,
                'of_log_entry_ids' => $ids,
            ]);
        }

        return $dropped;
    }

    /**
     * Put a song that ends on the ID in the hour's last line, when the one
     * planned there ends more than half a minute past the ID or short of it:
     * what the Top-of-Hour swap does when the queue reaches the line, done in
     * the log ahead of it, from the line's own playlist while that may play.
     *
     * When the song runs over and nothing short enough can take its place, it
     * does not air whole whatever happens (the swap replaces or drops a last
     * song the ID would cut by more than that): it is dropped, and on the next
     * pass the hour is given a song or the one before it becomes the one to
     * fit. An ending that no song of the playlist brings closer to the ID is
     * left to the swap.
     *
     * @param array<string, mixed> $closing the hour's last line, as the page lists it
     * @param float $offBy seconds it ends past the ID; negative when short of it
     * @return int lines re-fitted or dropped
     */
    private function landClosingSong(int $stationId, int $idAt, array $closing, float $offBy): int
    {
        if (
            !self::isPlannedAndUnlocked($closing)
            || LinearLogRefill::MUSIC_TYPE !== ($closing['media_type'] ?? null)
        ) {
            return 0;
        }

        // Read here, not handed in: a search for a song to add may have cleared
        // the entity manager since the hour was read. A show keeps its own
        // window, and a Clock Wheel's slot keeps the song the wheel gave it.
        $id = (int)$closing['log_entry_id'];
        $station = $this->em->find(Station::class, $stationId);
        $line = $this->em->find(StationLogEntry::class, $id);
        if (
            !$station instanceof Station
            || !$line instanceof StationLogEntry
            || null === $line->media
            || !LinearLogHourGuard::isHourBound($line)
            || null !== ($line->payload['clock_wheel_id'] ?? null)
        ) {
            return 0;
        }

        // What the plan first had here, through however many re-fits, and what
        // the line is beyond its song.
        $planned = null !== $line->note && str_starts_with($line->note, self::REFITTED_NOTE)
            ? substr($line->note, strlen(self::REFITTED_NOTE))
            : ($line->text ?? 'unknown');
        $kept = array_intersect_key($line->payload ?? [], ['top_up' => true, 'refill_of' => true]);

        $startsAt = (int)$closing['played_at'];
        $room = $idAt - $startsAt;
        $pick = $room >= LinearLogRefill::MIN_SONG_SECONDS
            ? $this->refill->pickReplacement(
                $station,
                CarbonImmutable::createFromTimestamp($startsAt, 'UTC')->toDateTimeImmutable(),
                $room,
                $room + LinearLogRefill::ID_GRACE_SECONDS,
                $line->playlist,
                $line->media->id,
                true
            )
            : null;

        if (null === $pick) {
            return $offBy > 0 ? $this->dropForNoRoom($stationId, [$id]) : 0;
        }

        // A song that lands no closer than the swap's own tolerance is no
        // reason to change the line.
        [$media, $playlist] = $pick;
        $landsOffBy = $this->airedLength->lengthOf($media) - $room;
        if (abs($landsOffBy) > abs($offBy) - LinearLogRefill::ID_GRACE_SECONDS) {
            return 0;
        }

        // Only while it is still planned and unlocked: playout may have claimed
        // the line for the queue, or the operator locked it, since it was read.
        $refitted = (int)$this->em->getConnection()->update(
            'station_log_entries',
            [
                ...LinearLogRefill::songFields($media, $playlist, $kept),
                'note' => mb_substr(self::REFITTED_NOTE . $planned, 0, 255),
            ],
            ['id' => $id, 'status' => StationLogEntry::STATUS_PLANNED, 'is_locked' => 0]
        );

        if ($refitted > 0) {
            $this->logger->notice('Linear Log: re-fitted an hour\'s last song to land on its ID.', [
                'log_entry_id' => $id,
                'planned' => $planned,
                'song' => $media->text,
                'was_off_by' => round($offBy, 1),
                'lands_off_by' => round($landsOffBy, 1),
            ]);
        }

        return $refitted;
    }
}
