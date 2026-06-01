<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\StationClockWheel;

/**
 * Per-wheel separation and burn-rate limits (PR9).
 */
final class ClockWheelSeparationSettings
{
    public function __construct(
        public bool $enabled = false,
        public int $artistMinutes = 45,
        public int $titleMinutes = 90,
        public ?int $burnRateMaxPlays24h = null,
    ) {
    }

    public static function fromWheel(StationClockWheel $wheel): self
    {
        return new self(
            enabled: $wheel->separation_enabled,
            artistMinutes: max(1, $wheel->separation_artist_minutes ?? 45),
            titleMinutes: max(1, $wheel->separation_title_minutes ?? 90),
            burnRateMaxPlays24h: $wheel->burn_rate_max_plays_24h,
        );
    }

    public function historyLookbackMinutes(): int
    {
        $minutes = max($this->artistMinutes, $this->titleMinutes);

        if ($this->burnRateMaxPlays24h !== null) {
            $minutes = max($minutes, 24 * 60);
        }

        return $minutes;
    }
}
