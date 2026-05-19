<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Enums\ClockWheelSlotAlgorithms;
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
 * When a StationSchedule linked to an active StationClockWheel matches the
 * expected play time, this subscriber overrides the normal QueueBuilder and
 * advances the wheel slot-by-slot. Each call walks one slot forward, looping
 * back to slot 0 at the top of every hour, so the on-air sequence matches the
 * clock the operator drew in the UI.
 */
final class ClockWheelScheduler implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    /**
     * Duration-preference tolerance, expressed as a fraction of the desired
     * slot length. A value of 0.4 means a 30-second ID slot will prefer media
     * whose length sits between 18 s and 42 s. Falls back to the full candidate
     * pool when nothing in the library matches.
     */
    private const float DURATION_PREF_TOLERANCE = 0.4;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly DuplicatePrevention $duplicatePrevention,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 3 runs after requests (5) but before normal QueueBuilder (0)
            BuildQueue::class => [
                ['buildFromClockWheel', 3],
            ],
        ];
    }

    public function buildFromClockWheel(BuildQueue $event): void
    {
        // If a request handler already filled the queue, leave it alone.
        if (!empty($event->getNextSongs())) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        $activeSchedule = $this->findActiveClockWheelSchedule($station->id, $expectedPlayTime);
        if (null === $activeSchedule) {
            return;
        }

        $wheel = $activeSchedule->clock_wheel;

        $slots = $wheel->slots->toArray();
        usort(
            $slots,
            static fn(StationClockWheelSlot $a, StationClockWheelSlot $b)
                => $a->slot_order <=> $b->slot_order
        );

        $slotCount = count($slots);
        if ($slotCount === 0) {
            $this->logger->warning(
                sprintf('Clock Wheel "%s" is active but has no slots.', $wheel->name),
                ['clock_wheel_id' => $wheel->id]
            );
            return;
        }

        $this->logger->info(
            sprintf('Clock Wheel "%s" is active. Overriding normal AutoDJ queue.', $wheel->name),
            ['clock_wheel_id' => $wheel->id, 'schedule_id' => $activeSchedule->id]
        );

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range
        );

        $startIndex = $this->nextSlotIndex($wheel, $slotCount, $expectedPlayTime);

        $nextSong = null;
        $usedIndex = null;

        // Walk every slot at most once: start at the next one and wrap around.
        // The first slot that resolves to a playable track wins.
        for ($offset = 0; $offset < $slotCount; $offset++) {
            $index = ($startIndex + $offset) % $slotCount;
            $slot = $slots[$index];

            $queue = $this->resolveSlot($slot, $recentHistory, $expectedPlayTime);
            if (null !== $queue) {
                $nextSong = $queue;
                $usedIndex = $index;
                break;
            }
        }

        if (null === $nextSong) {
            $this->logger->warning(
                sprintf(
                    'Clock Wheel "%s" could not resolve a playable track from any slot. Falling through to normal AutoDJ.',
                    $wheel->name
                ),
                ['clock_wheel_id' => $wheel->id]
            );
            return;
        }

        $set = $event->setNextSongs($nextSong);
        if (!$set) {
            return;
        }

        // Record the slot we used so the next BuildQueue tick advances to slot+1.
        $wheel->last_slot_index = $usedIndex;
        $wheel->last_slot_advanced_at = DateTimeImmutable::createFromInterface($expectedPlayTime);
        $this->em->persist($wheel);
        $this->em->flush();

        $this->logger->info(
            'Clock Wheel resolved next song.',
            [
                'next_song' => (string)$event,
                'slot_index' => $usedIndex,
                'slot_count' => $slotCount,
            ]
        );
    }

    // ------------------------------------------------------------------
    // Slot advancement state
    // ------------------------------------------------------------------

    /**
     * Compute which slot the scheduler should attempt this tick.
     *
     * Resets to slot 0 when:
     *   - the wheel has never produced a track,
     *   - the previous advance happened in an earlier hour, or
     *   - the persisted index is now out of range (slot list shrank).
     *
     * Otherwise returns (last_slot_index + 1) mod slotCount.
     */
    private function nextSlotIndex(
        StationClockWheel $wheel,
        int $slotCount,
        DateTimeImmutable $expectedPlayTime
    ): int {
        if ($wheel->last_slot_index === null || $wheel->last_slot_advanced_at === null) {
            return 0;
        }

        $hourStart = $expectedPlayTime->setTime((int)$expectedPlayTime->format('H'), 0, 0);
        if ($wheel->last_slot_advanced_at < $hourStart) {
            return 0;
        }

        $lastIndex = $wheel->last_slot_index;
        if ($lastIndex < 0 || $lastIndex >= $slotCount) {
            return 0;
        }

        return ($lastIndex + 1) % $slotCount;
    }

    // ------------------------------------------------------------------
    // Active schedule lookup
    // ------------------------------------------------------------------

    /**
     * Find a StationSchedule that links to an active Clock Wheel for the given
     * station and the supplied play time. Returns null when no wheel is active.
     */
    private function findActiveClockWheelSchedule(int $stationId, DateTimeImmutable $now): ?StationSchedule
    {
        // AzuraCast time-code: HHMM as integer (e.g. 09:30 -> 930)
        $timeCode = (int)$now->format('G') * 100 + (int)$now->format('i');
        // ISO 8601 weekday: 1=Mon .. 7=Sun
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

    // ------------------------------------------------------------------
    // Slot resolution
    // ------------------------------------------------------------------

    /**
     * Resolve a single slot to a StationQueue entry. Honours the slot's
     * playlist pin first, then falls back to type/category filtering.
     */
    private function resolveSlot(
        StationClockWheelSlot $slot,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime
    ): ?StationQueue {
        $station = $slot->clock_wheel->station;

        $candidates = $slot->playlist !== null
            ? $this->fetchCandidatesByPlaylist($slot)
            : $this->fetchCandidatesByTypeOrCategory($slot, $station->id);

        if (empty($candidates)) {
            $this->logger->warning(
                sprintf(
                    'Clock Wheel slot order=%d resolved no candidate media for station %d.',
                    $slot->slot_order,
                    $station->id
                ),
                [
                    'slot_id' => $slot->id ?? null,
                    'pinned_playlist_id' => $slot->playlist?->id,
                    'type' => $slot->type?->value,
                    'category_id' => $slot->category_id,
                ]
            );
            return null;
        }

        // Prefer media that match the slot's intended length. Falls back to the
        // full pool when nothing fits the tolerance window.
        if ($slot->duration_seconds !== null && $slot->duration_seconds > 0) {
            $preferred = $this->filterByDurationPreference($candidates, $slot->duration_seconds);
            if (!empty($preferred)) {
                $candidates = $preferred;
            }
        }

        // Wrap each candidate in a StationPlaylistQueue so DuplicatePrevention
        // can match on song_id, artist and title rather than media_id alone.
        $mediaQueue = [];
        foreach ($candidates as $m) {
            $q = new StationPlaylistQueue();
            $q->media_id = $m->id;
            $q->spm_id = 0;
            $q->song_id = $m->song_id;
            $q->artist = $m->artist ?? '';
            $q->title = $m->title ?? '';
            $mediaQueue[] = $q;
        }

        $algorithm = $slot->algorithm ?? ClockWheelSlotAlgorithms::Random;
        $mediaQueue = $this->applyAlgorithm($mediaQueue, $candidates, $algorithm, $recentHistory);

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

    /**
     * Fetch all media belonging to the slot's pinned playlist.
     *
     * @return StationMedia[]
     */
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

    /**
     * Fetch candidates by media type and/or category for the given station.
     *
     * @return StationMedia[]
     */
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

        return $this->em->createQuery($dql)
            ->setParameters($params)
            ->getResult();
    }

    /**
     * Narrow a candidate list to media whose runtime lies within the slot's
     * duration tolerance window. Returns an empty array if nothing fits, which
     * the caller treats as "no preference, use the full pool".
     *
     * @param StationMedia[] $candidates
     * @return StationMedia[]
     */
    private function filterByDurationPreference(array $candidates, int $desiredSeconds): array
    {
        $tolerance = self::DURATION_PREF_TOLERANCE;
        $min = (float)$desiredSeconds * (1.0 - $tolerance);
        $max = (float)$desiredSeconds * (1.0 + $tolerance);

        return array_values(array_filter(
            $candidates,
            static fn(StationMedia $m): bool => $m->length >= $min && $m->length <= $max
        ));
    }

    // ------------------------------------------------------------------
    // Algorithms
    // ------------------------------------------------------------------

    /**
     * Orders $mediaQueue according to the given algorithm.
     *
     * - Random:           shuffle (fair random selection)
     * - OldestTrack:      track least recently played (or never played) comes first
     * - OldestAlbum:      tracks from the album least recently played come first
     * - OldestArtist:     tracks from the artist least recently played come first
     * - MostRecentAlbum:  tracks from the album most recently played come first
     * - MostRecentArtist: tracks from the artist most recently played come first
     *
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
            if ($ts instanceof \DateTimeInterface) {
                $ts = $ts->getTimestamp();
            }
            $ts = (int)$ts;
            if (!isset($histTimestamp[$songId]) || $ts > $histTimestamp[$songId]) {
                $histTimestamp[$songId] = $ts;
            }
            $histArtist[$songId] = $h['artist'] ?? '';
        }

        if ($algorithm === ClockWheelSlotAlgorithms::OldestTrack) {
            usort($mediaQueue, static function (
                StationPlaylistQueue $a,
                StationPlaylistQueue $b
            ) use ($histTimestamp): int {
                $tsA = $histTimestamp[$a->song_id] ?? 0;
                $tsB = $histTimestamp[$b->song_id] ?? 0;
                return $tsA <=> $tsB;
            });
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

        $getGroupKey = static function (StationPlaylistQueue $q) use ($candidatesById, $isAlbum): string {
            $m = $candidatesById[$q->media_id] ?? null;
            if ($m === null) {
                return '';
            }
            return strtolower(trim((string)($isAlbum ? ($m->album ?? '') : ($m->artist ?? ''))));
        };

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
            if (!empty($missingSongIds)) {
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
        usort($groupKeys, static function (string $a, string $b) use ($groupLastPlayed, $isOldest): int {
            $tsA = $groupLastPlayed[$a];
            $tsB = $groupLastPlayed[$b];
            return $isOldest ? ($tsA <=> $tsB) : ($tsB <=> $tsA);
        });

        $sorted = [];
        foreach ($groupKeys as $key) {
            $groupItems = $groups[$key];
            shuffle($groupItems);
            foreach ($groupItems as $q) {
                $sorted[] = $q;
            }
        }

        return $sorted;
    }
}
