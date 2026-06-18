<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\Api\ClockWheel\ClockWheelPreview;
use App\Entity\Api\ClockWheel\ClockWheelPreviewItem;
use App\Entity\Enums\ClockWheelFillStrategy;
use App\Entity\Enums\ClockWheelSlotAlgorithms;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Radio\AutoDJ\Scheduler;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-only projection of one broadcast hour (PR12). Does not queue tracks or write audit rows.
 */
final class ClockWheelPreviewSimulator
{
    private const int HOUR_SECONDS = 3600;

    private const int MIN_MUSIC_WINDOW_SECONDS = 30;

    private const int MIN_TALK_WINDOW_SECONDS = 45;

    private const int MIN_SHORT_FORM_WINDOW_SECONDS = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Scheduler $scheduler,
    ) {
    }

    public function simulateNextHour(StationClockWheel $wheel, ?DateTimeImmutable $referenceTime = null): ClockWheelPreview
    {
        $station = $wheel->station;
        $tz = $station->getTimezoneObject();
        $reference = CarbonImmutable::instance($referenceTime ?? new DateTimeImmutable('now', $tz))
            ->setTimezone($tz);
        $hourStart = $reference->addHour()->startOf('hour');

        return $this->simulateHour($wheel, $hourStart);
    }

    public function simulateHour(StationClockWheel $wheel, DateTimeImmutable $hourStart): ClockWheelPreview
    {
        $response = new ClockWheelPreview();
        $response->hour_start_timestamp = $hourStart->getTimestamp();
        $response->hour_start = $hourStart->format(DateTimeImmutable::ATOM);

        $slots = $this->sortSlots($wheel->slots->toArray());
        if ($slots === []) {
            $response->warnings[] = 'This wheel has no slots.';

            return $response;
        }

        $cursor = 0;
        $simulatedSongIds = [];

        foreach ($slots as $index => $slot) {
            if ($cursor >= self::HOUR_SECONDS) {
                break;
            }

            if ($cursor < $slot->position_seconds) {
                $cursor = $slot->position_seconds;
            }

            $nextAnchor = $this->getNextAnchorSeconds($slots, $index);
            $availableSeconds = max(1, $nextAnchor - $cursor);
            $item = $this->projectSlot($wheel, $slot, $availableSeconds, $cursor, $simulatedSongIds, $hourStart);

            if ($item === null) {
                continue;
            }

            $playAt = $hourStart->modify('+' . $cursor . ' seconds');
            $item->projected_play_at = $playAt->format(DateTimeImmutable::ATOM);

            $response->items[] = $item;

            $playSeconds = $item->duration_seconds ?? $availableSeconds;
            $cursor += max(1, min($playSeconds, $availableSeconds));
        }

        $response->estimated_loop_seconds = min($cursor, self::HOUR_SECONDS);
        $response->is_valid = $response->warnings === [] && $cursor <= self::HOUR_SECONDS;

        return $response;
    }

    private function projectSlot(
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        int $availableSeconds,
        int $cursor,
        array &$simulatedSongIds,
        DateTimeImmutable $hourStart,
    ): ?ClockWheelPreviewItem {
        $item = new ClockWheelPreviewItem();
        $item->position_seconds = $slot->position_seconds;
        $item->position_label = $this->formatPosition($slot->position_seconds);
        $item->slot_type = $slot->type?->value ?? 'unknown';
        $item->drift_seconds = $cursor - $slot->position_seconds;

        if ($slot->playlist_id !== null && $slot->playlist_id > 0) {
            $playlist = $this->em->find(StationPlaylist::class, $slot->playlist_id);
            if ($playlist instanceof StationPlaylist) {
                $scheduleWarning = $this->getPlaylistScheduleWarning($playlist, $hourStart);
                if ($scheduleWarning !== null) {
                    $item->warnings[] = $scheduleWarning;
                }
            }
        }

        $type = $slot->type;
        if ($type === null) {
            $item->warnings[] = 'Slot has no content type.';

            return $item;
        }

        $minWindow = $this->getMinWindowSeconds($slot);
        if (
            ClockWheelFillStrategy::Conservative === $wheel->fill_strategy
            && $this->isFlexibleMusicSlot($slot)
            && $availableSeconds < $minWindow
        ) {
            $item->warnings[] = sprintf(
                'Insufficient time (%ds) before next anchor; would defer.',
                $availableSeconds
            );

            return $item;
        }

        $candidates = $this->loadCandidates($wheel, $slot);
        if ($candidates === []) {
            $item->warnings[] = 'No matching media for this slot.';

            return $item;
        }

        $maxDuration = $this->resolveMaxDuration($slot, $availableSeconds);
        $candidates = $this->filterCandidates($candidates, $maxDuration, $slot, $wheel->fill_strategy);

        if ($candidates === []) {
            $item->warnings[] = 'No media fits the available window.';

            return $item;
        }

        $media = $this->pickCandidate($candidates, $slot->algorithm ?? ClockWheelSlotAlgorithms::Random, $simulatedSongIds);
        if ($media === null) {
            $item->warnings[] = 'Could not select a track (duplicate simulation).';

            return $item;
        }

        $simulatedSongIds[] = $media->song_id;
        $item->title = $media->title;
        $item->artist = $media->artist;
        $item->duration_seconds = (int)min(
            (int)ceil($media->getCalculatedLength()),
            (int)floor($maxDuration)
        );

        return $item;
    }

    /**
     * @param StationClockWheelSlot[] $slots
     *
     * @return StationClockWheelSlot[]
     */
    private function sortSlots(array $slots): array
    {
        usort(
            $slots,
            static fn (StationClockWheelSlot $a, StationClockWheelSlot $b): int =>
                $a->position_seconds <=> $b->position_seconds
                ?: $a->slot_order <=> $b->slot_order
        );

        return $slots;
    }

    /**
     * @param StationClockWheelSlot[] $slots
     */
    private function getNextAnchorSeconds(array $slots, int $activeIndex): int
    {
        $next = $slots[$activeIndex + 1] ?? null;

        return $next?->position_seconds ?? self::HOUR_SECONDS;
    }

    private function formatPosition(int $positionSeconds): string
    {
        $clamped = min(self::HOUR_SECONDS - 1, max(0, $positionSeconds));
        $mins = intdiv($clamped, 60);
        $secs = $clamped % 60;

        return sprintf('%d:%02d', $mins, $secs);
    }

    private function getMinWindowSeconds(StationClockWheelSlot $slot): int
    {
        if (!$this->isFlexibleMusicSlot($slot)) {
            return self::MIN_SHORT_FORM_WINDOW_SECONDS;
        }

        return match ($slot->type) {
            ClockWheelSlotTypes::Talk => self::MIN_TALK_WINDOW_SECONDS,
            ClockWheelSlotTypes::Music, null => self::MIN_MUSIC_WINDOW_SECONDS,
            default => self::MIN_MUSIC_WINDOW_SECONDS,
        };
    }

    private function isFlexibleMusicSlot(StationClockWheelSlot $slot): bool
    {
        $type = $slot->type;

        return $type === null
            || $type === ClockWheelSlotTypes::Music
            || $type === ClockWheelSlotTypes::Talk;
    }

    /**
     * @return StationMedia[]
     */
    private function loadCandidates(StationClockWheel $wheel, StationClockWheelSlot $slot): array
    {
        $station = $wheel->station;
        $type = $slot->type;
        $categoryId = $slot->category_id;
        $playlistId = $slot->playlist_id;

        $params = ['storageLocation' => $station->media_storage_location];
        $dql = 'SELECT m FROM App\Entity\StationMedia m
             WHERE m.storage_location = :storageLocation';

        if ($playlistId !== null && $playlistId > 0) {
            $dql .= ' AND EXISTS (
                SELECT 1 FROM App\Entity\StationPlaylistMedia spm
                WHERE spm.media = m AND spm.playlist = :playlistId
            )';
            $params['playlistId'] = $playlistId;
        }

        $dql .= ' AND m.type = :type ORDER BY m.id ASC';
        $params['type'] = $type?->value;

        if ($categoryId !== null) {
            $dql = str_replace(' ORDER BY', ' AND m.category_id = :categoryId ORDER BY', $dql);
            $params['categoryId'] = $categoryId;
        }

        /** @var StationMedia[] $result */
        $result = $this->em->createQuery($dql)
            ->setParameters($params)
            ->setMaxResults(200)
            ->getResult();

        return $result;
    }

    private function resolveMaxDuration(StationClockWheelSlot $slot, int $availableSeconds): float
    {
        if ($slot->duration_seconds !== null && $slot->duration_seconds > 0) {
            return (float)min($slot->duration_seconds, $availableSeconds);
        }

        return (float)$availableSeconds;
    }

    /**
     * @param StationMedia[] $candidates
     *
     * @return StationMedia[]
     */
    private function filterCandidates(
        array $candidates,
        float $maxDuration,
        StationClockWheelSlot $slot,
        ClockWheelFillStrategy $fillStrategy,
    ): array {
        $fitting = array_values(array_filter(
            $candidates,
            static fn (StationMedia $m): bool => $m->getCalculatedLength() <= $maxDuration
        ));

        if ($fitting !== []) {
            if (ClockWheelFillStrategy::Aggressive === $fillStrategy) {
                usort(
                    $fitting,
                    static fn (StationMedia $a, StationMedia $b): int =>
                        $a->getCalculatedLength() <=> $b->getCalculatedLength()
                );
            }

            return $fitting;
        }

        if ($candidates === []) {
            return [];
        }

        usort(
            $candidates,
            static fn (StationMedia $a, StationMedia $b): int =>
                $a->getCalculatedLength() <=> $b->getCalculatedLength()
        );

        return [$candidates[0]];
    }

    /**
     * @param StationMedia[] $candidates
     * @param string[] $simulatedSongIds
     */
    private function pickCandidate(
        array $candidates,
        ClockWheelSlotAlgorithms $algorithm,
        array $simulatedSongIds,
    ): ?StationMedia {
        $pool = $candidates;

        if (ClockWheelSlotAlgorithms::Random === $algorithm) {
            shuffle($pool);
        }

        foreach ($pool as $media) {
            if (!in_array($media->song_id, $simulatedSongIds, true)) {
                return $media;
            }
        }

        return $pool[0] ?? null;
    }

    private function getPlaylistScheduleWarning(
        StationPlaylist $playlist,
        DateTimeImmutable $hourStart,
    ): ?string {
        if ($playlist->schedule_items->isEmpty()) {
            return null;
        }

        $checkpoints = [0, 1800, 3540];
        foreach ($checkpoints as $offsetSeconds) {
            $at = $hourStart->modify('+' . $offsetSeconds . ' seconds');
            if (!$this->scheduler->shouldPlaylistPlayNow($playlist, $at)) {
                return sprintf(
                    'Pinned playlist "%s" is not scheduled for the full wheel hour (check at +%ds).',
                    $playlist->name,
                    $offsetSeconds
                );
            }
        }

        return null;
    }
}
