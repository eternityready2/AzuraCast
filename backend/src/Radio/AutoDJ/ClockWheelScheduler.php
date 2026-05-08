<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Entity\StationSchedule;
use App\Event\Radio\BuildQueue;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Intercepts the AutoDJ queue building process to inject Clock Wheel playback.
 *
 * When a StationClockWheelEvent is active at the expected play time, this
 * subscriber fires BEFORE the normal QueueBuilder and resolves the next song
 * from the wheel's ordered slots, bypassing normal playlist rotation entirely.
 */
final class ClockWheelScheduler implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly DuplicatePrevention $duplicatePrevention,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 3 — runs after requests (5) but before normal QueueBuilder (0)
            BuildQueue::class => [
                ['buildFromClockWheel', 3],
            ],
        ];
    }

    public function buildFromClockWheel(BuildQueue $event): void
    {
        // If the event already has a next song (e.g. from a request), skip.
        if (!empty($event->getNextSongs())) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        $activeEvent = $this->findActiveClockWheelSchedule($station->id, $expectedPlayTime);

        if (null === $activeEvent) {
            return;
        }

        $wheel = $activeEvent->clock_wheel;

        $this->logger->info(
            sprintf('Clock Wheel "%s" is active. Overriding normal AutoDJ queue.', $wheel->name),
            ['clock_wheel_id' => $wheel->id, 'schedule_id' => $activeEvent->id]
        );

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range
        );

        $nextSong = $this->resolveNextSongFromWheel($wheel, $recentHistory, $expectedPlayTime);

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
                sprintf('Clock Wheel "%s" could not resolve a playable track. Falling through to normal AutoDJ.', $wheel->name)
            );
        }
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Find a StationSchedule that links to an active Clock Wheel for the given station and time.
     */
    private function findActiveClockWheelSchedule(int $stationId, DateTimeImmutable $now): ?StationSchedule
    {
        // AzuraCast time-code: HHMM as integer (e.g. 09:30 → 930)
        $timeCode = (int)$now->format('G') * 100 + (int)$now->format('i');
        // ISO 8601 weekday: 1=Mon … 7=Sun
        $weekday = (int)$now->format('N');

        /** @var StationSchedule[] $schedules */
        $schedules = $this->em->createQuery(
            'SELECT s, w FROM App\Entity\StationSchedule s
             JOIN s.clock_wheel w
             WHERE w.station = :stationId
             AND w.is_active = true
             AND s.start_time <= :timeCode
             AND s.end_time > :timeCode'
        )
            ->setParameter('stationId', $stationId)
            ->setParameter('timeCode', $timeCode)
            ->getResult();

        foreach ($schedules as $schedule) {
            $days = $schedule->days;
            if (empty($days) || in_array($weekday, $days, true)) {
                return $schedule;
            }
        }

        return null;
    }

    /**
     * Walk through the wheel's slots in order and return the first resolvable track.
     */
    private function resolveNextSongFromWheel(
        StationClockWheel $wheel,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime
    ): ?StationQueue {
        $slots = $wheel->slots->toArray();

        // Sort slots by slot_order ascending
        usort($slots, static fn(StationClockWheelSlot $a, StationClockWheelSlot $b) => $a->slot_order <=> $b->slot_order);

        foreach ($slots as $slot) {
            $queue = $this->resolveSlot($slot, $recentHistory, $expectedPlayTime);
            if (null !== $queue) {
                return $queue;
            }
        }

        return null;
    }

    /**
     * Resolve a single slot to a StationQueue entry.
     * Queries station_media directly by type, so no playlist assignment is needed.
     */
    private function resolveSlot(
        StationClockWheelSlot $slot,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime
    ): ?StationQueue {
        $station = $slot->clock_wheel->station;
        $type = $slot->type;
        $categoryId = $slot->category_id;

        // Must have at least one filter.
        if ($type === null && $categoryId === null) {
            $this->logger->warning('Clock Wheel slot has neither type nor category set — skipping.');
            return null;
        }

        // Build DQL dynamically: filter by type and/or category.
        $dql = 'SELECT m FROM App\Entity\StationMedia m
             JOIN m.storage_location sl
             JOIN sl.stations st
             WHERE st.id = :stationId';

        $params = ['stationId' => $station->id];

        if ($type !== null) {
            $dql .= ' AND m.type = :type';
            $params['type'] = $type;
        }

        if ($categoryId !== null) {
            $dql .= ' AND m.category_id = :categoryId';
            $params['categoryId'] = $categoryId;
        }

        // Fetch all media matching the slot filters from this station's storage locations.
        /** @var StationMedia[] $candidates */
        $candidates = $this->em->createQuery($dql)
            ->setParameters($params)
            ->getResult();

        if (empty($candidates)) {
            $this->logger->warning(
                sprintf(
                    'Clock Wheel slot: no media found with type "%s"%s for station %d.',
                    $type?->value ?? '(any)',
                    $categoryId !== null ? sprintf(' and category_id %d', $categoryId) : '',
                    $station->id
                )
            );
            return null;
        }

        // Build a lightweight queue-like array for duplicate prevention.
        // DuplicatePrevention expects objects with a `media_id` property.
        $mediaQueue = array_map(
            static fn(StationMedia $m) => (object)['media_id' => $m->id, 'spm_id' => null],
            $candidates
        );

        // Shuffle for random selection (respects 'random' algorithm default).
        shuffle($mediaQueue);

        // Apply duplicate prevention.
        $validTrack = $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, false)
            ?? $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, true);

        if (null === $validTrack) {
            return null;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            return null;
        }

        $queueEntry = StationQueue::fromMedia($station, $media);
        $this->em->persist($queueEntry);

        return $queueEntry;
    }
}
