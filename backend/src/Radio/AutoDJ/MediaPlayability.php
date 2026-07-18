<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\StationMedia;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Per-song do-not-play (DNP) and time-restriction eligibility for AutoDJ and
 * clock wheels (Phase E).
 */
final class MediaPlayability
{
    public static function isEligibleForPlayback(
        StationMedia $media,
        ?DateTimeImmutable $referenceTime = null,
        ?DateTimeZone $tz = null,
    ): bool {
        if ($media->do_not_play) {
            if ($media->do_not_play_until === null) {
                return false;
            }

            $at = $referenceTime ?? new DateTimeImmutable('now');
            if ($at < $media->do_not_play_until) {
                return false;
            }
        }

        $hasTimeRestriction = !empty($media->allowed_days)
            || null !== $media->allowed_start_minute
            || null !== $media->allowed_end_minute;

        if ($hasTimeRestriction) {
            $at = $referenceTime ?? new DateTimeImmutable('now');
            $checker = new SongSchedulingRestrictionChecker();

            if (!$checker->isEligibleAt($media, $at, $tz ?? new DateTimeZone('UTC'))) {
                return false;
            }
        }

        return true;
    }
}
