<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Container\LoggerAwareTrait;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

final class HourTimeline
{
    use LoggerAwareTrait;

    public function planNext(
        StationClockWheel $wheel,
        DateTimeImmutable $expectedPlayTime
    ): ?TimelinePlan {
        $slots = $this->slotsByPosition($wheel);
        if ($slots === []) {
            return null;
        }

        $tz = $wheel->station->getTimezoneObject();
        $expected = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $t = $this->computeT($expected);

        $idx = null;
        foreach ($slots as $i => $slot) {
            if ($slot->position_seconds >= $t) {
                $idx = $i;
                break;
            }
        }

        if ($idx === null) {
            return null;
        }

        $chosen = $slots[$idx];
        $nextAnchor = $slots[$idx + 1]->position_seconds ?? 3600;
        $available = max(0, $nextAnchor - max($t, $chosen->position_seconds));

        return new TimelinePlan($chosen, $available, $t);
    }

    /** @return StationClockWheelSlot[] */
    public function slotsByPosition(StationClockWheel $wheel): array
    {
        $slots = $wheel->slots->toArray();
        usort(
            $slots,
            static fn(StationClockWheelSlot $a, StationClockWheelSlot $b): int
                => ($a->position_seconds <=> $b->position_seconds)
                    ?: ($a->slot_order <=> $b->slot_order)
        );
        return array_values($slots);
    }

    private function computeT(CarbonImmutable $expected): int
    {
        $hourStart = $expected->setTime((int)$expected->format('H'), 0, 0);
        return max(0, min(3599, (int)$expected->diffInSeconds($hourStart, false)));
    }
}
