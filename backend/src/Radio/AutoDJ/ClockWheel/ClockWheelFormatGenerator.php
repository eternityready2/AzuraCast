<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\Enums\ClockWheelSlotAlgorithms;
use App\Entity\Enums\ClockWheelSlotTypes;

/**
 * Auto Format Clock Generator (Master Plan V3, required deliverable).
 *
 * Takes plain hour-level goals ("mostly music, ID at top, promos at :30")
 * and produces a complete starting clock-wheel slot layout. The output is
 * a plain array of slot definitions -- the caller persists them as normal,
 * fully-editable StationClockWheelSlot rows. Nothing about the generated
 * wheel is locked; manual creation is untouched.
 */
final class ClockWheelFormatGenerator
{
    private const int HOUR_SECONDS = 3600;

    /** Assumed average music track length used for layout spacing. */
    private const int AVG_MUSIC_SECONDS = 210;

    /** Assumed short-content lengths per type. */
    private const array TYPE_DEFAULT_SECONDS = [
        'id' => 15,
        'promo' => 45,
        'ad' => 60,
        'talk' => 300,
    ];

    /**
     * @param array{
     *     music_percent?: int,
     *     id_at_top?: bool,
     *     promo_positions?: array<int, int>,
     *     ad_positions?: array<int, int>,
     *     talk_positions?: array<int, int>,
     *     music_category_id?: int|null,
     *     algorithm?: string|null
     * } $goals
     *
     * @return array<int, array{
     *     position_seconds: int,
     *     type: string,
     *     category_id: int|null,
     *     algorithm: string,
     *     duration_seconds: int|null,
     *     slot_order: int
     * }>
     */
    public function generate(array $goals): array
    {
        $musicPercent = max(0, min(100, (int)($goals['music_percent'] ?? 75)));
        $idAtTop = (bool)($goals['id_at_top'] ?? true);
        $promoPositions = $this->normalizePositions($goals['promo_positions'] ?? [1800]);
        $adPositions = $this->normalizePositions($goals['ad_positions'] ?? []);
        $talkPositions = $this->normalizePositions($goals['talk_positions'] ?? []);
        $musicCategoryId = $goals['music_category_id'] ?? null;
        $algorithm = $this->normalizeAlgorithm($goals['algorithm'] ?? null);

        $slots = [];
        $reserved = [];

        // 1. Mandatory top-of-hour ID (if requested) at exactly 0:00.
        if ($idAtTop) {
            $slots[] = $this->slot(0, ClockWheelSlotTypes::Id->value, null, $algorithm, self::TYPE_DEFAULT_SECONDS['id']);
            $reserved[] = [0, self::TYPE_DEFAULT_SECONDS['id']];
        }

        // 2. Fixed-position non-music content.
        foreach ($promoPositions as $pos) {
            $slots[] = $this->slot($pos, ClockWheelSlotTypes::Promo->value, null, $algorithm, self::TYPE_DEFAULT_SECONDS['promo']);
            $reserved[] = [$pos, self::TYPE_DEFAULT_SECONDS['promo']];
        }

        foreach ($adPositions as $pos) {
            $slots[] = $this->slot($pos, ClockWheelSlotTypes::Ad->value, null, $algorithm, self::TYPE_DEFAULT_SECONDS['ad']);
            $reserved[] = [$pos, self::TYPE_DEFAULT_SECONDS['ad']];
        }

        foreach ($talkPositions as $pos) {
            $slots[] = $this->slot($pos, ClockWheelSlotTypes::Talk->value, null, $algorithm, self::TYPE_DEFAULT_SECONDS['talk']);
            $reserved[] = [$pos, self::TYPE_DEFAULT_SECONDS['talk']];
        }

        // 3. Fill remaining time with music slots, roughly evenly spaced,
        //    targeting the requested music percentage of the hour.
        $targetMusicSeconds = (int)floor(self::HOUR_SECONDS * ($musicPercent / 100));
        $musicSlotCount = max(1, (int)round($targetMusicSeconds / self::AVG_MUSIC_SECONDS));

        $musicPositions = $this->distributeMusicPositions($musicSlotCount, $reserved);

        foreach ($musicPositions as $pos) {
            $slots[] = $this->slot($pos, ClockWheelSlotTypes::Music->value, $musicCategoryId, $algorithm, null);
        }

        // 4. Sort by position and assign slot_order tiebreakers.
        usort($slots, static fn (array $a, array $b) => $a['position_seconds'] <=> $b['position_seconds']);

        foreach ($slots as $index => &$slotRow) {
            $slotRow['slot_order'] = $index;
        }
        unset($slotRow);

        return $slots;
    }

    /**
     * @return array{position_seconds: int, type: string, category_id: int|null, algorithm: string, duration_seconds: int|null, slot_order: int}
     */
    private function slot(
        int $positionSeconds,
        string $type,
        ?int $categoryId,
        string $algorithm,
        ?int $durationSeconds,
    ): array {
        return [
            'position_seconds' => $positionSeconds,
            'type' => $type,
            'category_id' => $categoryId,
            'algorithm' => $algorithm,
            'duration_seconds' => $durationSeconds,
            'slot_order' => 0,
        ];
    }

    /**
     * @param mixed $positions
     * @return int[]
     */
    private function normalizePositions(mixed $positions): array
    {
        if (!is_array($positions)) {
            return [];
        }

        $out = [];
        foreach ($positions as $pos) {
            $pos = (int)$pos;
            if ($pos >= 0 && $pos < self::HOUR_SECONDS) {
                $out[] = $pos;
            }
        }

        sort($out);
        return array_values(array_unique($out));
    }

    private function normalizeAlgorithm(?string $algorithm): string
    {
        if (null !== $algorithm) {
            $enum = ClockWheelSlotAlgorithms::tryFrom($algorithm);
            if (null !== $enum) {
                return $enum->value;
            }
        }

        return ClockWheelSlotAlgorithms::Random->value;
    }

    /**
     * Evenly distribute music slot positions across the hour, nudging any
     * position that lands inside a reserved (non-music) block to just after it.
     *
     * @param array<int, array{0: int, 1: int}> $reserved [position, duration] pairs
     * @return int[]
     */
    private function distributeMusicPositions(int $count, array $reserved): array
    {
        $positions = [];
        $interval = (int)floor(self::HOUR_SECONDS / max(1, $count));

        for ($i = 0; $i < $count; $i++) {
            $pos = $i * $interval;

            foreach ($reserved as [$rPos, $rDur]) {
                if ($pos >= $rPos && $pos < ($rPos + $rDur)) {
                    $pos = $rPos + $rDur;
                    break;
                }
            }

            if ($pos < self::HOUR_SECONDS) {
                $positions[] = $pos;
            }
        }

        return array_values(array_unique($positions));
    }
}
