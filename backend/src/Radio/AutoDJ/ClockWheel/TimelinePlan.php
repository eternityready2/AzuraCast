<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\StationClockWheelSlot;

final readonly class TimelinePlan
{
    public function __construct(
        public StationClockWheelSlot $slot,
        public int $availableSeconds,
        public int $currentT,
    ) {
    }
}
