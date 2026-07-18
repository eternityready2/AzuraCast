<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\StationMedia;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Checks per-song time restrictions (e.g. "weekends only", "not before 6am")
 * set directly on a StationMedia track, independent of playlist/clock wheel
 * scheduling. Empty/null settings mean "no restriction" on that axis.
 */
final class SongSchedulingRestrictionChecker
{
    public function isEligibleAt(
        StationMedia $media,
        DateTimeImmutable $now,
        DateTimeZone $tz,
    ): bool {
        $local = CarbonImmutable::instance($now)->setTimezone($tz);

        if (!empty($media->allowed_days)) {
            $isoDay = (int)$local->dayOfWeekIso;
            if (!in_array($isoDay, $media->allowed_days, true)) {
                return false;
            }
        }

        $minuteOfDay = $local->hour * 60 + $local->minute;

        $start = $media->allowed_start_minute;
        $end = $media->allowed_end_minute;

        if (null === $start && null === $end) {
            return true;
        }

        $start ??= 0;
        $end ??= (24 * 60) - 1;

        if ($start <= $end) {
            // Normal same-day window, e.g. 6am-11pm.
            return $minuteOfDay >= $start && $minuteOfDay <= $end;
        }

        // Overnight window, e.g. 10pm-2am.
        return $minuteOfDay >= $start || $minuteOfDay <= $end;
    }
}
