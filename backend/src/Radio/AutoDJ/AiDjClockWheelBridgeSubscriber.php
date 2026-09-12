<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationScheduleRepository;
use App\Event\Radio\BuildQueue;
use App\Radio\Schedule\ScheduleConflictChecker;
use App\Service\HolidayOverrideService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Lets AI DJ lifecycle/talk logic run before an active Clock Wheel claims the
 * BuildQueue event.
 *
 * ClockWheelScheduler runs at priority 3 and fills BuildQueue::nextSongs. The AI
 * DJ listeners normally run at priorities 2/1 and intentionally stop when another
 * selector has already filled that list. Without this bridge, a station using an
 * active Clock Wheel can therefore starve the AI DJ for the entire wheel: no
 * welcome, no regular talk/artist spotlight and no scheduled sign-off.
 *
 * We only move the AI work forward for a Clock Wheel that is genuinely eligible
 * to own this exact queue slot. Ordinary AutoDJ, scheduled playlists, emergency
 * content and listener requests keep their existing event order and behavior.
 */
final class AiDjClockWheelBridgeSubscriber implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly StationScheduleRepository $scheduleRepo,
        private readonly Scheduler $scheduler,
        private readonly ScheduleConflictChecker $conflictChecker,
        private readonly HolidayOverrideService $holidayOverrideService,
        private readonly AiDjCadenceWatchdogSubscriber $cadenceWatchdog,
        private readonly AiDjShiftLifecycleListener $shiftLifecycle,
        private readonly AiDjQueueListener $queueListener,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Listener requests run first at priority 5. We then give AI DJ lifecycle
        // and speech one safe chance immediately before ClockWheelScheduler (3).
        return [
            BuildQueue::class => ['onBuildQueue', 4],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        if ($event->isInterrupting() || !empty($event->getNextSongs())) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        // Match ClockWheelScheduler's ownership gates. If another calendar item
        // outranks the wheel, let the existing AI DJ subscriber order run normally.
        if (
            $this->conflictChecker->hasEmergencyScheduleActive($station, $expectedPlayTime)
            || $this->conflictChecker->hasNonClockWheelScheduleActive($station, $expectedPlayTime)
        ) {
            return;
        }

        $activeWheel = null;
        $timezone = $station->getTimezoneObject();

        foreach ($this->scheduleRepo->getAllScheduledItemsForStation($station) as $schedule) {
            if (null === $schedule->clock_wheel) {
                continue;
            }

            if ($this->scheduler->shouldSchedulePlayNow($schedule, $timezone, $expectedPlayTime)) {
                $activeWheel = $schedule->clock_wheel;
                break;
            }
        }

        if (null === $activeWheel) {
            $activeWheel = $this->holidayOverrideService->getHolidayClockWheel($station, $expectedPlayTime);
        }

        if (null === $activeWheel || !$activeWheel->is_active) {
            return;
        }

        $this->logger->debug('AI DJ: Running lifecycle/talk before active Clock Wheel queue selection.', [
            'clock_wheel_id' => $activeWheel->id,
        ]);

        // The watchdog may make an overdue break eligible. Lifecycle goes next so
        // a shift sign-off owns the one available request slot. Ordinary speech is
        // last and keeps all of its existing TOH/news/cooldown/request-queue guards.
        $this->cadenceWatchdog->onBuildQueue($event);
        $this->shiftLifecycle->onBuildQueue($event);
        $this->queueListener->onBuildQueue($event);
    }
}
