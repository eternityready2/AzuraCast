<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Station;
use App\Entity\StationSchedule;
use App\Event\Radio\BuildQueue;
use App\Radio\Schedule\ScheduleConflictChecker;
use App\Radio\SmartBlock\SmartBlockPicker;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plays a smart block in the calendar time it is scheduled for.
 *
 * When nothing earlier has picked the next song and a smart block's calendar
 * time is open, its next song comes from the smart block's own rules. If the
 * smart block has nothing it can play, the normal AutoDJ carries on.
 */
final class SmartBlockScheduler implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly StationScheduleRepository $scheduleRepo,
        private readonly Scheduler $scheduler,
        private readonly ScheduleConflictChecker $conflictChecker,
        private readonly SmartBlockPicker $picker,
        private readonly LinearLog\LinearLogPlayout $linearLogPlayout,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After requests (5), before clock wheels (3) and the normal QueueBuilder (0).
            BuildQueue::class => [
                ['buildFromSmartBlock', 4],
            ],
        ];
    }

    public function buildFromSmartBlock(BuildQueue $event): void
    {
        if (!empty($event->getNextSongs())) {
            return;
        }

        // The saved linear log already holds this hour's smart block songs.
        if ($this->linearLogPlayout->ownsSelection($event)) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        $activeEvent = $this->findActiveSmartBlockSchedule($station, $expectedPlayTime);
        if (null === $activeEvent || null === $activeEvent->smart_block) {
            return;
        }

        if ($this->conflictChecker->hasEmergencyScheduleActive($station, $expectedPlayTime)) {
            $this->logger->debug('Smart block skipped: emergency schedule is active.');
            return;
        }

        $smartBlock = $activeEvent->smart_block;

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range
        );

        $nextSong = $this->picker->pick($smartBlock, $expectedPlayTime, $recentHistory);

        if (null === $nextSong) {
            $this->logger->warning(
                sprintf(
                    'Smart block "%s" could not pick a song. Falling through to normal AutoDJ.',
                    $smartBlock->name
                ),
                ['smart_block_id' => $smartBlock->id]
            );
            return;
        }

        if ($event->setNextSongs($nextSong)) {
            $this->em->flush();
            $this->logger->info(
                sprintf('Smart block "%s" picked the next song.', $smartBlock->name),
                ['next_song' => (string)$event, 'smart_block_id' => $smartBlock->id]
            );
        }
    }

    private function findActiveSmartBlockSchedule(Station $station, DateTimeImmutable $now): ?StationSchedule
    {
        $tz = $station->getTimezoneObject();

        foreach ($this->scheduleRepo->getAllScheduledItemsForStation($station) as $schedule) {
            if (null === $schedule->smart_block || !$schedule->smart_block->is_enabled) {
                continue;
            }

            if ($this->scheduler->shouldSchedulePlayNow($schedule, $tz, $now)) {
                return $schedule;
            }
        }

        return null;
    }
}
