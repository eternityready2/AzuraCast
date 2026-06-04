<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\StationMedia;

/**
 * Result of applying separation / burn-rate rules to slot candidates.
 */
final readonly class SeparationFilterResult
{
    /**
     * @param StationMedia[] $candidates
     */
    public function __construct(
        public array $candidates,
        public bool $separationRelaxed = false,
        public bool $burnRateWarning = false,
    ) {
    }
}
