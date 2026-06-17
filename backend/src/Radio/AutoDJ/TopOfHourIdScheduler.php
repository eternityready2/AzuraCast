<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Station;
use App\Entity\StationSchedule;
use App\Event\Radio\BuildQueue;
use App\Radio\Schedule\ScheduleConflictChecker;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues mandatory legal_id at :00 when station-wide top-of-hour protection is enabled.
 */
final class TopOfHourIdScheduler implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly HourBoundaryLegalIdResolver $legalIdResolver,
        private readonly StationQueueRepository $queueRepo,
        private readonly StationScheduleRepository $scheduleRepo,
        private readonly Scheduler $scheduler,
        private readonly ScheduleConflictChecker $conflictChecker,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => [
                ['buildTopOfHourId', 2],
            ],
        ];
    }

    public function buildTopOfHourId(BuildQueue $event): void
    {
        if (!empty($event->getNextSongs()) || $event->isInterrupting()) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        if (!$this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station)) {
            return;
        }

        if ($this->conflictChecker->hasEmergencyScheduleActive($station, $expectedPlayTime)) {
            return;
        }

        if ($this->clockWheelHandlesLegalIdThisHour($station, $expectedPlayTime)) {
            $this->logger->debug('Top-of-hour ID skipped: active clock wheel has legal_id at :00.');

            return;
        }

        if (!$this->hourBoundaryPlanner->isTopOfHourIdDue($station, $expectedPlayTime)) {
            return;
        }

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range
        );

        $nextSong = $this->legalIdResolver->resolveMandatoryLegalId(
            $station,
            $recentHistory,
            $expectedPlayTime,
        );

        if (null === $nextSong) {
            $this->logger->warning('Top-of-hour ID: could not resolve mandatory legal_id track.');

            return;
        }

        if ($event->setNextSongs($nextSong)) {
            $this->em->flush();
            $this->logger->info('Top-of-hour ID resolved next song.');
        }
    }

    private function clockWheelHandlesLegalIdThisHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        $activeEvent = $this->findActiveClockWheelSchedule($station, $expectedPlayTime);
        if (null === $activeEvent?->clock_wheel) {
            return false;
        }

        $wheel = $activeEvent->clock_wheel;
        if (!$wheel->is_active) {
            return false;
        }

        foreach ($wheel->slots as $slot) {
            if (
                $slot->type === ClockWheelSlotTypes::LegalId
                && $slot->position_seconds === 0
            ) {
                return true;
            }
        }

        return false;
    }

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
