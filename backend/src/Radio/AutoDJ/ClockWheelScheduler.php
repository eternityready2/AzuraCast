<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Enums\ClockWheelSlotAlgorithms;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Entity\StationSchedule;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\ClockWheel\HourTimeline;
use App\Radio\AutoDJ\ClockWheel\TimelinePlan;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ClockWheelScheduler implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    private const float WINDOW_BUFFER_SECONDS = 5.0;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly DuplicatePrevention $duplicatePrevention,
        private readonly ScheduleConflictChecker $conflictChecker,
        private readonly HourTimeline $timeline,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => [
                ['buildFromClockWheel', 3],
            ],
        ];
    }

    public function buildFromClockWheel(BuildQueue $event): void
    {
        if (!empty($event->getNextSongs())) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        $activeSchedule = $this->findActiveClockWheelSchedule($station->id, $expectedPlayTime);
        if ($activeSchedule === null) {
            return;
        }

        if ($this->conflictChecker->isNonWheelScheduleActiveAt($station, $expectedPlayTime)) {
            $this->logger->debug('Clock Wheel deferring to non-wheel schedule.', [
                'clock_wheel_id' => $activeSchedule->clock_wheel->id,
                'schedule_id'    => $activeSchedule->id,
            ]);
            return;
        }

        $wheel = $activeSchedule->clock_wheel;

        $plan = $this->timeline->planNext($wheel, $expectedPlayTime);
        if ($plan === null) {
            $this->logger->info('Clock Wheel hour complete, falling through.', [
                'clock_wheel_id' => $wheel->id,
                'schedule_id'    => $activeSchedule->id,
            ]);
            return;
        }

        $this->logger->info('Clock Wheel timeline selected slot.', [
            'clock_wheel_id'        => $wheel->id,
            'schedule_id'           => $activeSchedule->id,
            'slot_id'               => $plan->slot->id ?? null,
            'slot_position_seconds' => $plan->slot->position_seconds,
            'slot_order'            => $plan->slot->slot_order,
            'current_t'             => $plan->currentT,
            'available_seconds'     => $plan->availableSeconds,
        ]);

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range
        );

        $queue = $this->resolveSlot($plan, $recentHistory);
        if ($queue === null) {
            $this->logger->warning('Clock Wheel slot produced no playable track.', [
                'clock_wheel_id'    => $wheel->id,
                'slot_id'           => $plan->slot->id ?? null,
                'available_seconds' => $plan->availableSeconds,
            ]);
            return;
        }

        if (!$event->setNextSongs($queue)) {
            return;
        }

        $this->em->flush();

        $this->logger->info('Clock Wheel resolved next song.', [
            'next_song'             => (string)$event,
            'slot_position_seconds' => $plan->slot->position_seconds,
            'available_seconds'     => $plan->availableSeconds,
        ]);
    }

    private function findActiveClockWheelSchedule(int $stationId, DateTimeImmutable $now): ?StationSchedule
    {
        $timeCode = (int)$now->format('G') * 100 + (int)$now->format('i');
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
            if ($days === [] || in_array($weekday, $days, true)) {
                return $schedule;
            }
        }

        return null;
    }

    private function resolveSlot(TimelinePlan $plan, array $recentHistory): ?StationQueue
    {
        $slot = $plan->slot;
        $station = $slot->clock_wheel->station;

        $candidates = $slot->playlist !== null
            ? $this->fetchCandidatesByPlaylist($slot)
            : $this->fetchCandidatesByTypeOrCategory($slot, $station->id);

        if ($candidates === []) {
            $this->logger->warning('Clock Wheel slot has no candidate media.', [
                'slot_id'            => $slot->id ?? null,
                'pinned_playlist_id' => $slot->playlist?->id,
                'type'               => $slot->type?->value,
                'category_id'        => $slot->category_id,
                'position_seconds'   => $slot->position_seconds,
            ]);
            return null;
        }

        $candidates = $this->filterToWindow($candidates, $slot, $plan->availableSeconds);
        if ($candidates === []) {
            return null;
        }

        $mediaQueue = array_map(
            static function (StationMedia $m): StationPlaylistQueue {
                $q = new StationPlaylistQueue();
                $q->media_id = $m->id;
                $q->spm_id   = 0;
                $q->song_id  = $m->song_id;
                $q->artist   = $m->artist ?? '';
                $q->title    = $m->title  ?? '';
                return $q;
            },
            $candidates
        );

        $algorithm = $slot->algorithm ?? ClockWheelSlotAlgorithms::Random;
        $mediaQueue = $this->applyAlgorithm($mediaQueue, $candidates, $algorithm, $recentHistory);

        $validTrack = $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, false)
            ?? $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, true);

        if ($validTrack === null) {
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

    /**
     * @param StationMedia[] $candidates
     * @return StationMedia[]
     */
    private function filterToWindow(array $candidates, StationClockWheelSlot $slot, int $availableSeconds): array
    {
        $cap = (float)$availableSeconds + self::WINDOW_BUFFER_SECONDS;
        $cap = ($slot->duration_seconds !== null && $slot->duration_seconds > 0)
            ? min($cap, (float)$slot->duration_seconds)
            : $cap;

        $fits = array_values(array_filter(
            $candidates,
            static fn(StationMedia $m): bool => $m->length > 0 && $m->length <= $cap
        ));

        if ($fits !== []) {
            return $fits;
        }

        $sorted = $candidates;
        usort($sorted, static fn(StationMedia $a, StationMedia $b): int => $a->length <=> $b->length);

        $this->logger->info('Clock Wheel slot falling back to shortest track (none fit window).', [
            'slot_id'           => $slot->id ?? null,
            'window_cap_secs'   => $cap,
            'chosen_length_sec' => $sorted[0]->length ?? null,
        ]);

        return [$sorted[0]];
    }

    /** @return StationMedia[] */
    private function fetchCandidatesByPlaylist(StationClockWheelSlot $slot): array
    {
        return $this->em->createQuery(
            'SELECT m FROM App\Entity\StationMedia m
             JOIN m.playlists pm
             WHERE pm.playlist = :playlist'
        )
            ->setParameter('playlist', $slot->playlist)
            ->getResult();
    }

    /** @return StationMedia[] */
    private function fetchCandidatesByTypeOrCategory(StationClockWheelSlot $slot, int $stationId): array
    {
        $type = $slot->type;
        $categoryId = $slot->category_id;

        if ($type === null && $categoryId === null) {
            return [];
        }

        $dql = 'SELECT m FROM App\Entity\StationMedia m
                JOIN m.storage_location sl
                JOIN sl.stations st
                WHERE st.id = :stationId';
        $params = ['stationId' => $stationId];

        if ($type !== null) {
            $dql .= ' AND m.type = :type';
            $params['type'] = $type;
        }
        if ($categoryId !== null) {
            $dql .= ' AND m.category_id = :categoryId';
            $params['categoryId'] = $categoryId;
        }

        return $this->em->createQuery($dql)->setParameters($params)->getResult();
    }

    /**
     * @param StationPlaylistQueue[] $mediaQueue
     * @param StationMedia[] $candidates
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     * @return StationPlaylistQueue[]
     */
    private function applyAlgorithm(
        array $mediaQueue,
        array $candidates,
        ClockWheelSlotAlgorithms $algorithm,
        array $recentHistory
    ): array {
        if ($algorithm === ClockWheelSlotAlgorithms::Random) {
            shuffle($mediaQueue);
            return $mediaQueue;
        }

        $histTimestamp = [];
        $histArtist = [];
        foreach ($recentHistory as $h) {
            $songId = $h['song_id'];
            $ts = $h['timestamp_played'];
            $ts = $ts instanceof DateTimeInterface ? $ts->getTimestamp() : (int)$ts;
            if (!isset($histTimestamp[$songId]) || $ts > $histTimestamp[$songId]) {
                $histTimestamp[$songId] = $ts;
            }
            $histArtist[$songId] = $h['artist'] ?? '';
        }

        if ($algorithm === ClockWheelSlotAlgorithms::OldestTrack) {
            usort(
                $mediaQueue,
                static fn(StationPlaylistQueue $a, StationPlaylistQueue $b): int
                    => ($histTimestamp[$a->song_id] ?? 0) <=> ($histTimestamp[$b->song_id] ?? 0)
            );
            return $mediaQueue;
        }

        $isAlbum = in_array($algorithm, [
            ClockWheelSlotAlgorithms::OldestAlbum,
            ClockWheelSlotAlgorithms::MostRecentAlbum,
        ], true);
        $isOldest = in_array($algorithm, [
            ClockWheelSlotAlgorithms::OldestAlbum,
            ClockWheelSlotAlgorithms::OldestArtist,
        ], true);

        $candidatesById = [];
        $candidatesBySongId = [];
        foreach ($candidates as $m) {
            $candidatesById[$m->id] = $m;
            $candidatesBySongId[$m->song_id] = $m;
        }

        $getGroupKey = static fn(StationPlaylistQueue $q): string
            => strtolower(trim((string)(
                ($m = $candidatesById[$q->media_id] ?? null)
                    ? ($isAlbum ? ($m->album ?? '') : ($m->artist ?? ''))
                    : ''
            )));

        $groups = [];
        foreach ($mediaQueue as $q) {
            $groups[$getGroupKey($q)][] = $q;
        }

        $groupLastPlayed = array_fill_keys(array_keys($groups), 0);

        if ($isAlbum) {
            $histSongIds = array_keys($histTimestamp);
            $histAlbum = [];

            foreach ($histSongIds as $songId) {
                if (isset($candidatesBySongId[$songId])) {
                    $histAlbum[$songId] = strtolower(trim((string)($candidatesBySongId[$songId]->album ?? '')));
                }
            }

            $missingSongIds = array_diff($histSongIds, array_keys($histAlbum));
            if ($missingSongIds !== []) {
                $rows = $this->em->createQuery(
                    'SELECT m.song_id, m.album FROM App\Entity\StationMedia m WHERE m.song_id IN (:ids)'
                )->setParameter('ids', array_values($missingSongIds))->getArrayResult();
                foreach ($rows as $row) {
                    $histAlbum[$row['song_id']] = strtolower(trim((string)($row['album'] ?? '')));
                }
            }

            foreach ($histSongIds as $songId) {
                $albumKey = $histAlbum[$songId] ?? '';
                if (!array_key_exists($albumKey, $groupLastPlayed)) {
                    continue;
                }
                $ts = $histTimestamp[$songId];
                if ($ts > $groupLastPlayed[$albumKey]) {
                    $groupLastPlayed[$albumKey] = $ts;
                }
            }
        } else {
            foreach ($histTimestamp as $songId => $ts) {
                $artistKey = strtolower(trim((string)($histArtist[$songId] ?? '')));
                if (!array_key_exists($artistKey, $groupLastPlayed)) {
                    continue;
                }
                if ($ts > $groupLastPlayed[$artistKey]) {
                    $groupLastPlayed[$artistKey] = $ts;
                }
            }
        }

        $groupKeys = array_keys($groups);
        shuffle($groupKeys);
        usort(
            $groupKeys,
            static fn(string $a, string $b): int => $isOldest
                ? $groupLastPlayed[$a] <=> $groupLastPlayed[$b]
                : $groupLastPlayed[$b] <=> $groupLastPlayed[$a]
        );

        $sorted = [];
        foreach ($groupKeys as $key) {
            $groupItems = $groups[$key];
            shuffle($groupItems);
            array_push($sorted, ...$groupItems);
        }

        return $sorted;
    }
}
