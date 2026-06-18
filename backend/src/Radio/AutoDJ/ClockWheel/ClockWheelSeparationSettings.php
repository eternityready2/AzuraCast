<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationClockWheelTemplate;

/**
 * Per-wheel separation and burn-rate limits (PR9), with optional daypart overrides (PR10).
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

    /**
     * Resolve effective settings: slot override → daypart → wheel → template.
     */
    public static function resolveForSlot(StationClockWheelSlot $slot, StationClockWheel $wheel): self
    {
        if ($slot->separation_override_enabled) {
            return new self(
                enabled: true,
                artistMinutes: max(1, $slot->separation_artist_minutes ?? 45),
                titleMinutes: max(1, $slot->separation_title_minutes ?? 90),
                burnRateMaxPlays24h: null,
            );
        }

        return self::resolveForWheel($wheel);
    }

    /**
     * Resolve effective settings: daypart override wins when enabled on the wheel's daypart.
     */
    public static function resolveForWheel(StationClockWheel $wheel): self
    {
        $daypart = $wheel->daypart;
        if ($daypart !== null && $daypart->separation_override_enabled) {
            return new self(
                enabled: $daypart->separation_enabled,
                artistMinutes: max(1, $daypart->separation_artist_minutes ?? 45),
                titleMinutes: max(1, $daypart->separation_title_minutes ?? 90),
                burnRateMaxPlays24h: $daypart->burn_rate_max_plays_24h,
            );
        }

        $fromWheel = self::fromWheel($wheel);
        if ($fromWheel->enabled || $fromWheel->burnRateMaxPlays24h !== null) {
            return $fromWheel;
        }

        $template = $wheel->template;
        if ($template instanceof StationClockWheelTemplate) {
            return self::fromTemplate($template);
        }

        return $fromWheel;
    }

    public static function fromTemplate(StationClockWheelTemplate $template): self
    {
        return new self(
            enabled: $template->separation_enabled,
            artistMinutes: max(1, $template->separation_artist_minutes ?? 45),
            titleMinutes: max(1, $template->separation_title_minutes ?? 90),
            burnRateMaxPlays24h: $template->burn_rate_max_plays_24h,
        );
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
