<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Event\Radio\RevalidateQueuedSong;
use App\Entity\Enums\ClockWheelScheduleMode;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Station;
use App\Entity\StationSchedule;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\ClockWheel\ClockWheelEventLogger;
use App\Radio\AutoDJ\ClockWheel\ClockWheelPlaybackPlanner;
use App\Radio\AutoDJ\ClockWheel\ClockWheelSeparationSettings;
use App\Radio\Schedule\ScheduleConflictChecker;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Intercepts the AutoDJ queue building process to inject Clock Wheel playback.
 *
 * When a clock wheel schedule is active and no other calendar item takes priority,
 * resolves the next song from timed format-clock anchors.
 */
final class ClockWheelScheduler implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly StationScheduleRepository $scheduleRepo,
        private readonly Scheduler $scheduler,
        private readonly ClockWheelPlaybackPlanner $planner,
        private readonly ScheduleConflictChecker $conflictChecker,
        private readonly ClockWheelEventLogger $eventLogger,
        private readonly LinearLog\LinearLogPlayout $linearLogPlayout,
        private readonly LinearLogPreviewContext $previewContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 3 — runs after requests (5) but before normal QueueBuilder (0)
            BuildQueue::class => [
                ['buildFromClockWheel', 3],
            ],
            // Before the Top-of-Hour swap (-1), so it lands the wheel's own song.
            RevalidateQueuedSong::class => [
                ['keepWheelHourOwnSong', 0],
            ],
        ];
    }

    /**
     * A queued song that slid into a clock wheel's hour from a playlist the
     * wheel does not allow -- the next hour's opener, once the wheel's songs
     * ran shorter on air than planned -- gets a song from the wheel's own slot.
     * The Top-of-Hour swap then lands that song on the ID, as it lands a
     * playlist hour's last song, and the opener's line goes back to the log for
     * its own hour (LinearLogHourGuard). Left as it was, the swap matched it
     * from its own playlist, the schedule guard threw it out every 10s and the
     * hour ran out before the ID (Decades Lunch, 12:57pm Mon 2026-11-16).
     */
    public function keepWheelHourOwnSong(RevalidateQueuedSong $event): void
    {
        if ($this->previewContext->isActive()) {
            return;
        }

        $row = $event->getQueueRow();
        if (
            !$this->em->contains($row)
            || $row->sent_to_autodj
            || $row->is_played
            || null !== $row->clock_wheel
            || null !== $row->request
            || $row->top_of_hour_legal_id
            || $row->clock_wheel_legal_id_substitute
        ) {
            return;
        }

        $media = $row->media;
        $playlist = $row->playlist;
        if (null === $media || null === $playlist || 'music' !== $media->type || true === $playlist->is_programme) {
            return;
        }

        $station = $event->getStation();
        $at = $event->getExpectedPlayAt();
        $schedule = $this->findActiveClockWheelSchedule($station, $at);
        $wheel = $schedule?->clock_wheel;
        if (
            null === $wheel
            || !$wheel->is_active
            || ClockWheelScheduleMode::Strict === $schedule->clock_wheel_mode
            || $this->scheduler->isPlaylistAllowedAt($playlist, $at)
        ) {
            return;
        }

        $history = $this->queueRepo->getRecentlyPlayedWithCategoryByTimeRange(
            $station,
            $at,
            $station->backend_config->duplicate_prevention_time_range
        );
        $pick = $this->planner->resolveNextQueueEntry($wheel, $history, $at, $schedule);
        if (null === $pick) {
            return;
        }
        // The planner made a row of its own for the pick; this slot keeps its row.
        if ($this->em->contains($pick)) {
            $this->em->detach($pick);
        }
        $newMedia = $pick->media;
        if (null === $newMedia || 'music' !== $newMedia->type) {
            return;
        }

        $was = $media->text;
        $row->setSong($newMedia);
        $row->media = $newMedia;
        $row->playlist = $pick->playlist ?? $row->playlist;
        $row->clock_wheel = $wheel;
        $row->clock_wheel_schedule_mode = $pick->clock_wheel_schedule_mode ?? $schedule->clock_wheel_mode?->value;
        $row->clock_wheel_enforce_cap = false;
        $row->clock_wheel_max_play_seconds = null;
        $row->clock_wheel_stretch_ratio = null;
        $row->duration = $newMedia->getCalculatedLength();
        $this->em->persist($row);

        $this->logger->notice('Clock Wheel: a song from outside the wheel slid into its hour; the wheel gives that slot a song of its own.', [
            'queue_id' => $row->id,
            'was' => $was,
            'now' => $newMedia->text,
            'wheel' => $wheel->name,
            'airs_at' => $at->format(DATE_ATOM),
        ]);
    }

    public function buildFromClockWheel(BuildQueue $event): void
    {
        if (!empty($event->getNextSongs())) {
            return;
        }

        // The saved linear log already holds this hour's clock-wheel items. When
        // the log's only line for the slot belongs to the next hour (its opener,
        // or a show that waits for its start), the wheel fills its own hour.
        if (
            $this->linearLogPlayout->ownsSelection($event)
            && !$this->linearLogPlayout->offersOnlyLaterHour($event->getStation(), $event->getExpectedPlayTime())
        ) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        if ($this->conflictChecker->hasEmergencyScheduleActive($station, $expectedPlayTime)) {
            $activeEvent = $this->findActiveClockWheelSchedule($station, $expectedPlayTime);

            $this->logger->debug(
                'Clock Wheel skipped: emergency schedule is active.'
            );
            $this->eventLogger->recordFallback(
                $station,
                $activeEvent?->clock_wheel,
                null,
                $expectedPlayTime,
                ClockWheelFallbackReason::EmergencyOverride,
            );
            $this->em->flush();

            return;
        }

        if ($this->conflictChecker->hasNonClockWheelScheduleActive($station, $expectedPlayTime)) {
            $this->logger->debug(
                'Clock Wheel skipped: another scheduled playlist or streamer is active.'
            );
            $this->eventLogger->recordFallback(
                $station,
                null,
                null,
                $expectedPlayTime,
                ClockWheelFallbackReason::ScheduleConflict,
            );
            $this->em->flush();

            return;
        }

        $activeEvent = $this->findActiveClockWheelSchedule($station, $expectedPlayTime);

        if (null === $activeEvent || null === $activeEvent->clock_wheel) {
            return;
        }

        $wheel = $activeEvent->clock_wheel;

        if (!$wheel->is_active) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                null,
                $expectedPlayTime,
                ClockWheelFallbackReason::WheelInactive,
            );
            $this->em->flush();

            return;
        }

        $this->logger->info(
            sprintf('Clock Wheel "%s" is active. Overriding normal AutoDJ queue.', $wheel->name),
            ['clock_wheel_id' => $wheel->id, 'schedule_id' => $activeEvent->id]
        );

        $separationSettings = ClockWheelSeparationSettings::resolveForWheel($wheel);
        $historyMinutes = $station->backend_config->duplicate_prevention_time_range;
        if ($separationSettings->enabled || $separationSettings->burnRateMaxPlays24h !== null) {
            $historyMinutes = max($historyMinutes, $separationSettings->historyLookbackMinutes());
        }

        $recentHistory = $this->queueRepo->getRecentlyPlayedWithCategoryByTimeRange(
            $station,
            $expectedPlayTime,
            $historyMinutes
        );

        $nextSong = $this->planner->resolveNextQueueEntry($wheel, $recentHistory, $expectedPlayTime, $activeEvent);

        if (null !== $nextSong) {
            $set = $event->setNextSongs($nextSong);

            if ($set) {
                $this->em->flush();
                $this->logger->info(
                    'Clock Wheel resolved next song.',
                    ['next_song' => (string)$event]
                );
            }
        } else {
            $this->logger->warning(
                sprintf(
                    'Clock Wheel "%s" could not resolve a playable track. Falling through to normal AutoDJ.',
                    $wheel->name
                )
            );
            $this->em->flush();
        }
    }

    /**
     * Find a StationSchedule that links to an active clock wheel for the station at the given time.
     *
     * Uses the same date/recurrence/overnight rules as playlist scheduling.
     */
    private function findActiveClockWheelSchedule(Station $station, DateTimeImmutable $now): ?StationSchedule
    {
        $tz = $station->getTimezoneObject();

        foreach ($this->scheduleRepo->getAllScheduledItemsForStation($station) as $schedule) {
            if ($schedule->clock_wheel === null) {
                continue;
            }

            if ($this->scheduler->shouldSchedulePlayNow($schedule, $tz, $now)) {
                return $schedule;
            }
        }

        return null;
    }
}
