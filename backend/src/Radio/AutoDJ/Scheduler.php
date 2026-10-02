<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Entity\StationStreamer;
use App\Utilities\DateRange;
use App\Utilities\ScheduleRecurrence;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\Collection;
use Monolog\LogRecord;

final class Scheduler
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly StationPlaylistMediaRepository $spmRepo,
        private readonly StationPlaylistRepository $spRepo,
        private readonly StationQueueRepository $queueRepo,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly LinearLogPreviewContext $previewContext,
    ) {
    }

    /**
     * Seconds until the next playlist is scheduled to start, station-wide,
     * within the next hour. Used by soft-strict boundary protection.
     * Checks both today's and tomorrow's occurrence of each schedule item, so
     * a start time just after midnight is not missed late at night. Returns
     * null if nothing starts within the next hour.
     */
    public function secondsUntilNextScheduledStart(
        \App\Entity\Station $station,
        DateTimeImmutable $now,
    ): ?int {
        $tz = $station->getTimezoneObject();
        $nowLocal = CarbonImmutable::instance($now)->setTimezone($tz);

        $best = null;

        $allScheduleItems = [];
        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }
            foreach ($playlist->schedule_items as $scheduleItem) {
                $allScheduleItems[] = $scheduleItem;
            }
        }

        // Include clock wheel schedules -- they are distinct from playlists
        // but equally represent hard programme boundaries that music must not
        // run past.
        foreach ($station->clock_wheels as $clockWheel) {
            foreach ($clockWheel->schedule_items as $scheduleItem) {
                $allScheduleItems[] = $scheduleItem;
            }
        }

        foreach ($allScheduleItems as $scheduleItem) {
            $days = $scheduleItem->days;

            // Check both today's and tomorrow's occurrence so a start time
            // just after midnight isn't missed when checking late at night.
            foreach ([$nowLocal, $nowLocal->addDay()] as $candidateDay) {
                $isoDay = (int)$candidateDay->dayOfWeekIso;
                if ([] !== $days && !in_array($isoDay, $days, true)) {
                    continue;
                }

                $startTime = StationSchedule::getDateTime($scheduleItem->start_time, $tz, $candidateDay);
                $deltaSeconds = $startTime->getTimestamp() - $nowLocal->getTimestamp();

                if ($deltaSeconds <= 0 || $deltaSeconds > HourBoundaryPlanner::HOUR_SECONDS) {
                    continue;
                }

                if (null === $best || $deltaSeconds < $best) {
                    $best = $deltaSeconds;
                }
            }
        }

        return $best;
    }

    public function shouldPlaylistPlayNow(
        StationPlaylist $playlist,
        ?DateTimeImmutable $now = null
    ): bool {
        $this->logger->pushProcessor(
            function (LogRecord $record) use ($playlist) {
                $record->extra['playlist'] = [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                ];
                return $record;
            }
        );

        $now ??= Time::nowUtc();

        if (!$this->isPlaylistScheduledToPlayNow($playlist, $now)) {
            $this->logger->debug('Playlist is not scheduled to play now.');
            $this->logger->popProcessor();
            return false;
        }

        $shouldPlay = true;

        switch ($playlist->type) {
            case PlaylistTypes::OncePerHour:
                $shouldPlay = $this->shouldPlaylistPlayNowPerHour($playlist, $now);

                $this->logger->debug(
                    sprintf(
                        'Once-per-hour playlist %s been played yet this hour.',
                        $shouldPlay ? 'HAS NOT' : 'HAS'
                    )
                );
                break;

            case PlaylistTypes::OncePerXSongs:
                $playPerSongs = $playlist->play_per_songs;
                $shouldPlay = !$this->queueRepo->isPlaylistRecentlyPlayed($playlist, $playPerSongs);

                $this->logger->debug(
                    sprintf(
                        'Once-per-X-songs playlist %s been played within the last %d song(s).',
                        $shouldPlay ? 'HAS NOT' : 'HAS',
                        $playPerSongs
                    )
                );
                break;

            case PlaylistTypes::OncePerXMinutes:
                $playPerMinutes = $playlist->play_per_minutes;
                $shouldPlay = !$this->wasPlaylistPlayedInLastXMinutes($playlist, $now, $playPerMinutes);

                $this->logger->debug(
                    sprintf(
                        'Once-per-X-minutes playlist %s been played within the last %d minute(s).',
                        $shouldPlay ? 'HAS NOT' : 'HAS',
                        $playPerMinutes
                    )
                );
                break;

            case PlaylistTypes::Advanced:
                $this->logger->debug('Playlist is "Advanced" type and is not managed by the AutoDJ.');
                $shouldPlay = false;
                break;

            case PlaylistTypes::Standard:
                break;
        }

        $this->logger->popProcessor();
        return $shouldPlay;
    }

    /**
     * True while an enabled scheduled playlist has its window open at $at, or
     * will within $lookaheadSeconds. Such a block owns the air: nothing
     * unscheduled plays then.
     *
     * The lookahead exists because the queue is built ahead of real time: a
     * rotation pick's $at is predicted from how long the songs queued before
     * it are expected to run, and that prediction drifts a little against the
     * real clock. A pick predicted to land just before a scheduled block opens
     * can actually air a few seconds into it once real playback catches up,
     * so an unscheduled song gets approved for a slot that ends up inside the
     * scheduled window. Checking a short window ahead of $at as well closes
     * that gap without changing anything about how the ID, swap, or any
     * already-open scheduled block behaves.
     */
    public function isScheduledBlockOpenAt(
        \App\Entity\Station $station,
        DateTimeImmutable $at,
        int $lookaheadSeconds = 0
    ): bool {
        if (null !== $this->narrowestOpenScheduleSeconds($station, $at)) {
            return true;
        }

        if ($lookaheadSeconds > 0) {
            $ahead = CarbonImmutable::instance($at)->addSeconds($lookaheadSeconds);
            return null !== $this->narrowestOpenScheduleSeconds($station, $ahead);
        }

        return false;
    }

    /**
     * The scheduling rule every playout path must obey (AutoDJ, the queue, and
     * the saved Linear Log): a scheduled playlist plays only inside its own
     * window, and nothing else plays while that window is open.
     */
    public function isPlaylistAllowedAt(StationPlaylist $playlist, DateTimeImmutable $at): bool
    {
        $station = $playlist->station;
        $tz = $station->getTimezoneObject();
        $narrowestOpen = $this->narrowestOpenScheduleSeconds($station, $at);

        if ($playlist->schedule_items->count() > 0) {
            $ownWindow = $this->openScheduleSecondsForPlaylist($playlist, $tz, $at);
            if (null === $ownWindow) {
                return false;
            }

            // A broad row (an all-day background rotation, say) must not bleed
            // into a narrower programme block open at the same moment. Equal
            // widths are left alone; those are deliberate co-programming.
            return null === $narrowestOpen || $ownWindow <= $narrowestOpen;
        }

        if ($this->isPlaylistCoveredByGroupScheduleAt($playlist, $at)) {
            return true;
        }

        return null === $narrowestOpen;
    }

    /**
     * Length in seconds of the narrowest scheduled window open anywhere on the
     * station at $at, or null when no scheduled block is open.
     *
     * Ownership of the air is a property of the schedule row, not of whether the
     * playlist can hand the AutoDJ a track at this instant. Testing isPlayable()
     * here silently surrendered the slot for two whole classes of scheduled
     * programme -- remote web streams (remote_type "stream") and any playlist
     * flagged to interrupt other songs -- letting rotation music leak into them.
     */
    private function narrowestOpenScheduleSeconds(
        \App\Entity\Station $station,
        DateTimeImmutable $at
    ): ?int {
        $tz = $station->getTimezoneObject();
        $narrowest = null;

        foreach ($station->playlists as $playlist) {
            $seconds = $this->openScheduleSecondsForPlaylist($playlist, $tz, $at);
            if (null !== $seconds && (null === $narrowest || $seconds < $narrowest)) {
                $narrowest = $seconds;
            }
        }

        // A scheduled clock wheel is a programme block in exactly the same sense
        // as a scheduled playlist. ClockWheelScheduler falls through to the
        // ordinary AutoDJ when a wheel cannot resolve a track, so without this
        // the fall-through pulled unscheduled rotation music into the wheel's
        // own window.
        foreach ($station->clock_wheels as $clockWheel) {
            if (!$this->canClockWheelOwnScheduledAir($clockWheel)) {
                continue;
            }

            foreach ($clockWheel->schedule_items as $schedule) {
                $seconds = $this->ownedWindowSeconds($schedule, $tz, $at);
                if (null !== $seconds && (null === $narrowest || $seconds < $narrowest)) {
                    $narrowest = $seconds;
                }
            }
        }

        return $narrowest;
    }

    private function canClockWheelOwnScheduledAir(\App\Entity\StationClockWheel $clockWheel): bool
    {
        return $clockWheel->is_active
            && ($clockWheel->slots->count() > 0 || $clockWheel->inherits_template_slots);
    }

    /**
     * The clock wheel counterpart of isPlaylistAllowedAt. A queue row produced by
     * a wheel is governed by the wheel's own schedule, not by the window of the
     * member playlist the track happened to come from.
     */
    public function isClockWheelAllowedAt(
        \App\Entity\StationClockWheel $clockWheel,
        DateTimeImmutable $at
    ): bool {
        // A wheel with no schedule rows of its own is driven by something else
        // (a holiday override, say); this rule has nothing to say about it.
        if (0 === $clockWheel->schedule_items->count()) {
            return true;
        }

        $station = $clockWheel->station;
        $tz = $station->getTimezoneObject();

        $ownWindow = null;
        foreach ($clockWheel->schedule_items as $schedule) {
            $seconds = $this->ownedWindowSeconds($schedule, $tz, $at);
            if (null !== $seconds && (null === $ownWindow || $seconds < $ownWindow)) {
                $ownWindow = $seconds;
            }
        }

        if (null === $ownWindow) {
            return false;
        }

        $narrowestOpen = $this->narrowestOpenScheduleSeconds($station, $at);

        return null === $narrowestOpen || $ownWindow <= $narrowestOpen;
    }

    /**
     * Length in seconds of this playlist's narrowest schedule row open at $at,
     * or null when the playlist cannot hold the air then.
     */
    private function openScheduleSecondsForPlaylist(
        StationPlaylist $playlist,
        DateTimeZone $tz,
        DateTimeImmutable $at
    ): ?int {
        if (!$this->canOwnScheduledAir($playlist)) {
            return null;
        }

        $narrowest = null;
        foreach ($playlist->schedule_items as $schedule) {
            $seconds = $this->ownedWindowSeconds($schedule, $tz, $at);
            if (null === $seconds) {
                continue;
            }

            // A loop-once show that has finished its content no longer owns the
            // air: rotation music should fill the tail of the window instead of
            // dead air. ownedWindowSeconds uses excludeSpecialRules so it always
            // sees the schedule as active; check the content state here.
            if ($schedule->loop_once && !$this->isLoopOnceContentAvailable($schedule, $tz, $at)) {
                continue;
            }

            if (null === $narrowest || $seconds < $narrowest) {
                $narrowest = $seconds;
            }
        }

        return $narrowest;
    }

    /**
     * Whether a loop-once schedule still has content to play in its current
     * occurrence. When content is exhausted, the window releases so rotation
     * music can fill the tail instead of dead air.
     *
     * Deliberately time-based rather than reading the playlist's played_at /
     * queue_reset_at: those only reflect whatever really happened the last time
     * this ran, so a check made ahead of air time (the Linear Log builder's
     * simulation is rolled back, and LinearLogRules re-checks the saved plan
     * afterward against live state) sees last time's stale values and reports
     * the window as still open, silently deleting the rotation music the
     * builder correctly planned to fill the tail. Elapsed-time-since-window-
     * start vs. one full cycle's duration gives the same answer regardless of
     * when or in what state it is asked.
     */
    private function isLoopOnceContentAvailable(
        StationSchedule $schedule,
        DateTimeZone $tz,
        DateTimeImmutable $at
    ): bool {
        $playlist = $schedule->playlist;
        if (null === $playlist) {
            return true;
        }

        $now = Time::nowInTimezone($tz, $at);
        $startTime = StationSchedule::getDateTime($schedule->start_time, $tz, $now);
        $endTime = StationSchedule::getDateTime($schedule->end_time, $tz, $now);

        if ($startTime->equalTo($endTime)) {
            $ranges = [
                new DateRange($startTime, $endTime->addMinutes(15)),
                new DateRange($startTime->subDay(), $endTime->subDay()->addMinutes(15)),
                new DateRange($startTime->addDay(), $endTime->addDay()->addMinutes(15)),
            ];
        } elseif ($startTime->greaterThan($endTime)) {
            $ranges = [
                new DateRange($startTime->subDay(), $endTime),
                new DateRange($startTime, $endTime->addDay()),
            ];
        } else {
            $ranges = [new DateRange($startTime, $endTime)];
        }

        foreach ($ranges as $dateRange) {
            if (!$dateRange->contains($now)) {
                continue;
            }

            $elapsedSeconds = $now->getTimestamp() - $dateRange->start->getTimestamp();
            $cycleSeconds = $this->playlistCycleDurationSeconds($playlist);

            if (null === $cycleSeconds) {
                // No known duration (e.g. an empty playlist): fall back to the
                // live-state check rather than guessing.
                return $this->shouldPlaylistLoopNow($schedule, $dateRange);
            }

            return $elapsedSeconds < $cycleSeconds;
        }

        return true;
    }

    /**
     * For a loop-once Songs schedule, how many seconds after its window opens
     * its single content pass is exhausted -- the moment ordinary rotation
     * becomes eligible to fill the tail of the window. Null when the schedule
     * is not a bounded loop-once Songs block, i.e. nothing releases the window
     * early. Used by the Linear Log preview to step a projection cursor to the
     * real release point instead of over-reporting a phantom gap for the whole
     * remainder of the schedule window.
     */
    public function loopOnceContentDurationSeconds(StationSchedule $schedule): ?float
    {
        if (!$schedule->loop_once) {
            return null;
        }

        $playlist = $schedule->playlist;
        if (null === $playlist) {
            return null;
        }

        return $this->playlistCycleDurationSeconds($playlist);
    }

    /**
     * Total playable duration of one full pass through a Songs playlist, or
     * null when it has no known length. Static and independent of any
     * mutable "already played" state.
     */
    private function playlistCycleDurationSeconds(StationPlaylist $playlist): ?float
    {
        if (PlaylistSources::Songs !== $playlist->source) {
            return null;
        }

        $total = $this->em->createQuery(
            <<<'DQL'
                SELECT SUM(sm.length) FROM App\Entity\StationPlaylistMedia spm
                JOIN spm.media sm
                WHERE spm.playlist = :playlist
            DQL
        )->setParameter('playlist', $playlist)
            ->getSingleScalarResult();

        return null !== $total ? (float)$total : null;
    }

    /**
     * Whether a playlist can hold the air for its own scheduled window.
     * Deliberately independent of interrupt behaviour and of remote subtype; it
     * only rejects a playlist with no content source at all, so an empty
     * programme still cannot manufacture silence.
     */
    private function canOwnScheduledAir(StationPlaylist $playlist): bool
    {
        if (!$playlist->is_enabled || 0 === $playlist->schedule_items->count()) {
            return false;
        }

        return match ($playlist->source) {
            PlaylistSources::Requests => true,
            PlaylistSources::Playlists => $playlist->playlists->count() > 0,
            PlaylistSources::Songs => $playlist->media_items->count() > 0,
            PlaylistSources::RemoteUrl => null !== $playlist->remote_url,
        };
    }

    /**
     * Length of this schedule row's window when it owns the air at $at, or null
     * when it does not.
     *
     * The window is treated as end-exclusive here, unlike shouldSchedulePlayNow()
     * which counts both boundaries. At a handover -- a block ending at midnight
     * and another starting there -- both rows would otherwise be open on that
     * one instant, and the outgoing (usually narrower) block would win the tick
     * and push the incoming block's first song out of the log. The block that is
     * starting owns the boundary.
     */
    private function ownedWindowSeconds(
        StationSchedule $schedule,
        DateTimeZone $tz,
        DateTimeImmutable $at
    ): ?int {
        if (!$this->shouldSchedulePlayNow($schedule, $tz, $at, excludeSpecialRules: true)) {
            return null;
        }

        $now = Time::nowInTimezone($tz, $at);
        $start = StationSchedule::getDateTime($schedule->start_time, $tz, $now);
        $end = StationSchedule::getDateTime($schedule->end_time, $tz, $now);

        // An equal-time row is a short spot block, not a handover; leave it be.
        if (
            $start->getTimestamp() !== $end->getTimestamp()
            && $end->getTimestamp() === $now->getTimestamp()
        ) {
            return null;
        }

        return $this->scheduleWindowSeconds($schedule, $tz, $at);
    }

    private function scheduleWindowSeconds(
        StationSchedule $schedule,
        DateTimeZone $tz,
        DateTimeImmutable $at
    ): int {
        $now = Time::nowInTimezone($tz, $at);
        $start = StationSchedule::getDateTime($schedule->start_time, $tz, $now);
        $end = StationSchedule::getDateTime($schedule->end_time, $tz, $now);

        $seconds = $end->getTimestamp() - $start->getTimestamp();

        // Match shouldSchedulePlayNow(): equal times are a short 15-minute spot
        // block, not an all-day row, so rank it as the narrow window it is. An
        // end before the start wraps past midnight.
        if (0 === $seconds) {
            return 900;
        }

        if ($seconds < 0) {
            $seconds += 86400;
        }

        return $seconds;
    }

    public function isPlaylistScheduledToPlayNow(
        StationPlaylist $playlist,
        DateTimeImmutable $now,
        bool $excludeSpecialRules = false
    ): bool {
        $scheduleItems = $playlist->schedule_items;

        if (0 === $scheduleItems->count()) {
            $this->logger->debug('Playlist has no schedule items; skipping schedule time check.');
            return true;
        }

        $stationTz = $playlist->station->getTimezoneObject();

        $scheduleItem = $this->getActiveScheduleFromCollection(
            $scheduleItems,
            $stationTz,
            $now,
            $excludeSpecialRules
        );

        return null !== $scheduleItem;
    }

    /**
     * True while a member playlist is currently being provided by at least one
     * fully-active ancestor Playlist Group chain. Scheduled member playlists can
     * therefore play independently outside the parent group's active window.
     */
    public function isPlaylistCoveredByGroupScheduleAt(
        StationPlaylist $playlist,
        DateTimeImmutable $now
    ): bool {
        if ($playlist->playlist_groups->count() === 0) {
            return false;
        }

        foreach ($playlist->playlist_groups as $membership) {
            if ($this->isGroupChainActiveAt($membership->playlist_group, $now)) {
                return true;
            }
        }

        return false;
    }

    public function isPlaylistFullyCoveredByGroupSchedule(
        StationPlaylist $playlist,
        DateRange $window
    ): bool {
        return $this->isPlaylistCoveredByGroupScheduleAt($playlist, $window->start)
            && $this->isPlaylistCoveredByGroupScheduleAt($playlist, $window->end->subSecond());
    }

    /**
     * @param int[] $visitedIdsInChain
     */
    private function isGroupChainActiveAt(
        StationPlaylist $group,
        DateTimeImmutable $now,
        array $visitedIdsInChain = []
    ): bool {
        if (in_array($group->id, $visitedIdsInChain, true)) {
            return false;
        }

        $visitedIdsInChain[] = $group->id;

        // Exclude special rules so this check never mutates/reset queue state.
        if (!$this->isPlaylistScheduledToPlayNow($group, $now, excludeSpecialRules: true)) {
            return false;
        }

        if ($group->playlist_groups->count() === 0) {
            return true;
        }

        foreach ($group->playlist_groups as $membership) {
            if ($this->isGroupChainActiveAt($membership->playlist_group, $now, $visitedIdsInChain)) {
                return true;
            }
        }

        return false;
    }

    private function shouldPlaylistPlayNowPerHour(
        StationPlaylist $playlist,
        DateTimeImmutable $now
    ): bool {
        if ($this->hourBoundaryPlanner->shouldSuppressOncePerHourPlaylist($playlist)) {
            $this->logger->debug(
                'Once-per-hour playlist at :00 suppressed; station top-of-hour protection handles legal ID.'
            );

            return false;
        }

        $stationNow = CarbonImmutable::instance($now)
            ->setTimezone($playlist->station->getTimezoneObject());
        $targetTime = $stationNow
            ->startOfHour()
            ->addMinutes($playlist->play_per_hour_minute);

        $playedAt = $playlist->played_at;
        if (null === $playedAt) {
            return !$targetTime->isAfter($stationNow);
        }

        if ($targetTime->isAfter($stationNow)) {
            $targetTime = $targetTime->subHour();
        }

        return CarbonImmutable::instance($playedAt)->isBefore($targetTime);
    }

    private function wasPlaylistPlayedInLastXMinutes(
        StationPlaylist $playlist,
        DateTimeImmutable $now,
        int $minutes
    ): bool {
        $playedAt = $playlist->played_at;
        if (null === $playedAt) {
            return false;
        }

        return CarbonImmutable::instance($now)
            ->subMinutes($minutes)
            ->isBefore($playedAt);
    }

    /**
     * Get the remaining scheduled play time in seconds for a remote stream.
     */
    public function getPlaylistScheduleDuration(
        StationPlaylist $playlist,
        ?DateTimeImmutable $now = null,
    ): int {
        $stationTz = $playlist->station->getTimezoneObject();
        $now = CarbonImmutable::instance(Time::nowInTimezone($stationTz, $now));

        $scheduleItem = $this->getActiveScheduleFromCollection(
            $playlist->schedule_items,
            $stationTz,
            $now
        );

        if (!$scheduleItem instanceof StationSchedule) {
            return 0;
        }

        $fullDuration = $scheduleItem->getDuration($stationTz);
        if (
            $fullDuration <= 0
            || $playlist->backendAllowOverrun()
        ) {
            return $fullDuration;
        }

        $end = StationSchedule::getDateTime($scheduleItem->end_time, $stationTz, $now);

        if ($scheduleItem->start_time > $scheduleItem->end_time) {
            $nowCode = ($now->hour * 100) + $now->minute;
            if ($nowCode >= $scheduleItem->start_time) {
                $end = $end->addDay();
            }
        }

        $remainingSeconds = $end->getTimestamp() - $now->getTimestamp();
        if ($remainingSeconds <= 0) {
            return 0;
        }

        $crossfadeOverlap = max(0.0, $playlist->station->backend_config->getCrossfadeDuration());
        return max(1, (int)ceil($remainingSeconds + $crossfadeOverlap));
    }

    public function canStreamerStreamNow(
        StationStreamer $streamer,
        ?DateTimeImmutable $now = null
    ): bool {
        if (!$streamer->enforce_schedule) {
            return true;
        }

        $stationTz = $streamer->station->getTimezoneObject();

        $scheduleItem = $this->getActiveScheduleFromCollection(
            $streamer->schedule_items,
            $stationTz,
            $now
        );

        return null !== $scheduleItem;
    }

    /**
     * @param Collection<int, StationSchedule> $scheduleItems
     * @param DateTimeZone $tz
     * @param DateTimeImmutable|null $now
     * @param bool $excludeSpecialRules
     * @return StationSchedule|null
     */
    private function getActiveScheduleFromCollection(
        Collection $scheduleItems,
        DateTimeZone $tz,
        ?DateTimeImmutable $now = null,
        bool $excludeSpecialRules = false
    ): ?StationSchedule {
        $now = Time::nowInTimezone($tz, $now);

        if ($scheduleItems->count() > 0) {
            foreach ($scheduleItems as $scheduleItem) {
                $scheduleName = (string)$scheduleItem;

                if ($this->shouldSchedulePlayNow($scheduleItem, $tz, $now, $excludeSpecialRules)) {
                    $this->logger->debug(
                        sprintf(
                            '%s - Should Play Now',
                            $scheduleName
                        )
                    );
                    return $scheduleItem;
                }

                $this->logger->debug(
                    sprintf(
                        '%s - Not Eligible to Play Now',
                        $scheduleName
                    )
                );
            }
        }
        return null;
    }

    public function shouldSchedulePlayNow(
        StationSchedule $schedule,
        DateTimeZone $tz,
        ?DateTimeImmutable $now = null,
        bool $excludeSpecialRules = false
    ): bool {
        $now = Time::nowInTimezone($tz, $now);

        $startTime = StationSchedule::getDateTime($schedule->start_time, $tz, $now);
        $endTime = StationSchedule::getDateTime($schedule->end_time, $tz, $now);

        $this->logger->debug('Checking to see whether schedule should play now.', [
            'startTime' => $startTime,
            'endTime' => $endTime,
        ]);

        if (!$this->shouldSchedulePlayOnCurrentDate($schedule, $tz, $now)) {
            $this->logger->debug('Schedule is not scheduled to play today.');
            return false;
        }

        /** @var DateRange[] $comparePeriods */
        $comparePeriods = [];

        if ($startTime->equalTo($endTime)) {
            $comparePeriods[] = new DateRange(
                $startTime,
                $endTime->addMinutes(15)
            );
            $comparePeriods[] = new DateRange(
                $startTime->subDay(),
                $endTime->subDay()->addMinutes(15)
            );
            $comparePeriods[] = new DateRange(
                $startTime->addDay(),
                $endTime->addDay()->addMinutes(15)
            );
        } elseif ($startTime->greaterThan($endTime)) {
            $comparePeriods[] = new DateRange(
                $startTime->subDay(),
                $endTime
            );
            $comparePeriods[] = new DateRange(
                $startTime,
                $endTime->addDay()
            );
        } else {
            $comparePeriods[] = new DateRange(
                $startTime,
                $endTime
            );
        }

        return array_any(
            $comparePeriods,
            fn($dateRange) => $this->shouldPlayInSchedulePeriod($schedule, $dateRange, $now, $excludeSpecialRules)
        );
    }

    private function shouldPlayInSchedulePeriod(
        StationSchedule $schedule,
        DateRange $dateRange,
        DateTimeImmutable $now,
        bool $excludeSpecialRules = false
    ): bool {
        if (!$dateRange->contains($now)) {
            return false;
        }

        $dayToCheck = $dateRange->start->dayOfWeekIso;
        if (!$this->isScheduleScheduledToPlayToday($schedule, $dayToCheck)) {
            return false;
        }

        $playlist = $schedule->playlist;
        if (null === $playlist) {
            return true;
        }

        if ($excludeSpecialRules) {
            return true;
        }

        if ($playlist->backendPlaySingleTrack()) {
            $playedAt = $playlist->played_at;

            if (null !== $playedAt && $dateRange->start->isBefore($playedAt)) {
                return false;
            }
        }

        if ($schedule->reset_queue_at_start) {
            $this->resetQueueAtBlockStart($playlist, $dateRange, $schedule->reset_queue_recursive);
        }

        if (
            $schedule->loop_once
            && !$this->shouldPlaylistLoopNow($schedule, $dateRange)
        ) {
            return false;
        }

        return true;
    }

    private function resetQueueAtBlockStart(
        StationPlaylist $playlist,
        DateRange $dateRange,
        bool $recursive
    ): void {
        if (!in_array($playlist->source, [PlaylistSources::Songs, PlaylistSources::Playlists], true)) {
            return;
        }

        // A projection simulates the reset; performing it would edit live
        // rotation state from a read-only report.
        if ($this->previewContext->isActive()) {
            return;
        }

        if ($dateRange->contains($playlist->played_at)) {
            return;
        }

        $resetAt = $dateRange->start->subSecond();

        if (null !== $playlist->queue_reset_at && $playlist->queue_reset_at >= $resetAt) {
            return;
        }

        $this->logger->debug(
            'Resetting playlist queue at schedule block start.',
            ['reset_at' => $resetAt]
        );

        if (PlaylistSources::Songs === $playlist->source) {
            $this->spmRepo->resetQueue($playlist, $resetAt);
        } elseif ($recursive) {
            $this->resetPlaylistGroupTree($playlist, $resetAt);
        } else {
            $this->spRepo->resetPlaylistGroupQueue($playlist, $resetAt);
        }
    }

    /**
     * @param int[] $visitedIds
     */
    private function resetPlaylistGroupTree(
        StationPlaylist $group,
        CarbonImmutable $resetAt,
        array $visitedIds = []
    ): void {
        if (in_array($group->id, $visitedIds, true)) {
            return;
        }

        $visitedIds[] = $group->id;

        $this->spRepo->resetPlaylistGroupQueue($group, $resetAt);

        foreach ($group->playlists as $membership) {
            $member = $membership->playlist;

            switch ($member->source) {
                case PlaylistSources::Playlists:
                    $this->resetPlaylistGroupTree($member, $resetAt, $visitedIds);
                    break;

                case PlaylistSources::Songs:
                    if (!in_array($member->id, $visitedIds, true)) {
                        $visitedIds[] = $member->id;
                        $this->spmRepo->resetQueue($member, $resetAt);
                    }
                    break;

                default:
                    break;
            }
        }
    }

    private function shouldPlaylistLoopNow(
        StationSchedule $schedule,
        DateRange $dateRange
    ): bool {
        $this->logger->debug('Checking if playlist should loop now.');

        $playlist = $schedule->playlist;

        if (null === $playlist) {
            $this->logger->error('Attempting to check playlist loop status on a non-playlist-based schedule item.');
            return false;
        }

        $playlistPlayedAt = $playlist->played_at;

        $isQueueEmpty = $this->spmRepo->isQueueEmpty($playlist);

        // Scoped to THIS occurrence. The station-wide question left a show whose
        // only episode was still cued for today unable to loop tomorrow: the
        // queue was empty and a cued row existed, so neither reset branch below
        // ran and the whole window was reported as an unfillable gap.
        $hasCuedPlaylistMedia = $this->queueRepo->hasCuedPlaylistMediaInRange($playlist, $dateRange);

        if (!$dateRange->contains($playlistPlayedAt)) {
            $this->logger->debug('Playlist was not played yet.');

            $isQueueFilled = $this->spmRepo->isQueueCompletelyFilled($playlist);

            if ((!$isQueueFilled || $isQueueEmpty) && !$hasCuedPlaylistMedia) {
                $now = $dateRange->start->subSecond();

                $this->logger->debug('Resetting playlist queue with now override', [$now]);

                $this->spmRepo->resetQueue($playlist, $now);
                $isQueueEmpty = false;
            }

            // Nothing has aired for this occurrence yet, so there is no completed
            // pass to detect here. queue_reset_at is not a reliable signal in this
            // branch: unrelated per-playlist queue refills (QueueBuilder emptying
            // and reshuffling this playlist's own track queue) stamp it to "now"
            // the moment the window opens, which the check below would otherwise
            // misread as "already looped." Falling through into that check turned
            // a never-played occurrence into a permanent "should NOT loop" for the
            // rest of the window -- dead air where the schedule owns the air but
            // refuses to fill it.
            return !$isQueueEmpty;
        }

        if ($isQueueEmpty && !$hasCuedPlaylistMedia) {
            $this->logger->debug('Resetting playlist queue.');

            $this->spmRepo->resetQueue($playlist);
            $isQueueEmpty = false;
        }

        $playlist = $this->em->refetch($playlist);

        $playlistQueueResetAt = $playlist->queue_reset_at;

        if (!$isQueueEmpty && !$dateRange->contains($playlistQueueResetAt)) {
            $this->logger->debug('Playlist should loop.');
            return true;
        }

        $this->logger->debug('Playlist should NOT loop.');
        return false;
    }

    /**
     * Determines if a schedule entity should play on the current date.
     */
    public function shouldSchedulePlayOnCurrentDate(
        StationSchedule $schedule,
        DateTimeZone $tz,
        ?DateTimeImmutable $now = null
    ): bool {
        $now = CarbonImmutable::instance(Time::nowInTimezone($tz, $now));

        $startDate = $schedule->start_date;
        $endDate = $schedule->end_date;

        if (!empty($startDate)) {
            $startDate = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $tz);

            if (null !== $startDate) {
                $startDate = StationSchedule::getDateTime(
                    $schedule->start_time,
                    $tz,
                    $startDate
                );

                if ($now->endOfDay()->lt($startDate)) {
                    return false;
                }
            }
        }

        if (!empty($endDate)) {
            $endDate = CarbonImmutable::createFromFormat('Y-m-d', $endDate, $tz);

            if (null !== $endDate) {
                $isOvernightSchedule = $schedule->start_time > $schedule->end_time;

                if ($isOvernightSchedule && $schedule->start_date === $schedule->end_date) {
                    $endDate = $endDate->addDay();
                }

                $endDate = StationSchedule::getDateTime(
                    $schedule->end_time,
                    $tz,
                    $endDate
                );

                if ($now->startOfDay()->gt($endDate)) {
                    return false;
                }
            }
        }

        if (ScheduleRecurrence::hasRecurrence($schedule)) {
            return ScheduleRecurrence::isDateInSchedule($schedule, $tz, $now);
        }

        return true;
    }

    private static function hasRecurrence(StationSchedule $schedule): bool
    {
        return ScheduleRecurrence::hasRecurrence($schedule);
    }

    /**
     * Given an ISO-8601 date, return if the playlist can be played on that day.
     */
    public function isScheduleScheduledToPlayToday(
        StationSchedule $schedule,
        int $dayToCheck
    ): bool {
        if (self::hasRecurrence($schedule)) {
            return true;
        }
        $playOnceDays = $schedule->days;
        return empty($playOnceDays)
            || in_array($dayToCheck, $playOnceDays, false);
    }

    /**
     * True during the window in which a playlist's "Strict" schedule item is due
     * to start -- used to trigger a hard interrupt via the interrupting-queue
     * mechanism rather than waiting for the current track to finish naturally.
     *
     * A 5-minute grace window is intentional. QueueInterruptingTracks runs every
     * minute, but can be delayed or skipped during station restarts, AirCheck
     * recovery, or a brief Liquidsoap outage. Without grace, a missed minute means
     * the show simply never plays (Faith Horizons at 5pm was lost this way after
     * the 11pm AirCheck crash on 2026-09-16 left the station recovering past 5pm).
     * Grace allows catch-up: if the task fires at 5:03pm it still hard-interrupts
     * into Faith Horizons rather than silently skipping it.
     *
     * The window only applies while we are still within the show's own scheduled
     * end_time, so a 30-minute show that started at 5pm cannot be hard-interrupted
     * at 5:29pm by this logic.
     */
    private const int STRICT_START_GRACE_MINUTES = 5;

    public function isPlaylistStrictStartDueNow(
        StationPlaylist $playlist,
        DateTimeZone $tz,
        ?DateTimeImmutable $now = null
    ): bool {
        $now = CarbonImmutable::instance(Time::nowInTimezone($tz, $now));
        $nowMinute = $now->hour * 100 + $now->minute;

        foreach ($playlist->schedule_items as $schedule) {
            if (!$schedule->strict_start) {
                continue;
            }

            // Accept the exact start minute or up to STRICT_START_GRACE_MINUTES
            // afterwards so a delayed sync task can still fire the hard interrupt.
            $startCode = $schedule->start_time;
            $startDt = StationSchedule::getDateTime($startCode, $tz, $now);
            $graceEnd = $startDt->addMinutes(self::STRICT_START_GRACE_MINUTES);
            $graceEndCode = (int)$graceEnd->format('H') * 100 + (int)$graceEnd->format('i');

            $inGraceWindow = false;
            if ($startCode <= $graceEndCode) {
                // Normal (same-hour) case.
                $inGraceWindow = $nowMinute >= $startCode && $nowMinute < $graceEndCode;
            } else {
                // Grace window crosses midnight.
                $inGraceWindow = $nowMinute >= $startCode || $nowMinute < $graceEndCode;
            }

            if (!$inGraceWindow) {
                continue;
            }

            if (!$this->shouldSchedulePlayOnCurrentDate($schedule, $tz, $now)) {
                continue;
            }

            if (!$this->isScheduleScheduledToPlayToday($schedule, $now->dayOfWeekIso)) {
                continue;
            }

            // Only interrupt if we are still within the show's own scheduled
            // end_time so a late catch-up cannot override an already-ended show.
            $endCode = $schedule->end_time;
            if ($endCode !== 0 && $endCode !== $startCode) {
                if ($endCode > $startCode) {
                    if ($nowMinute >= $endCode) {
                        continue;
                    }
                } else {
                    // Overnight show; end_time < start_time.
                    if ($nowMinute >= $endCode && $nowMinute < $startCode) {
                        continue;
                    }
                }
            }

            return true;
        }

        return false;
    }
}
