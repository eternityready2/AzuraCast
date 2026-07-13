<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\StationMedia;
use DateTimeImmutable;

/**
 * Per-song do-not-play (DNP) eligibility for AutoDJ and clock wheels (Phase E).
 */
final class MediaPlayability
{
    public static function isEligibleForPlayback(
        StationMedia $media,
        ?DateTimeImmutable $referenceTime = null,
    ): bool {
        if (!$media->do_not_play) {
            return true;
        }

        if ($media->do_not_play_until === null) {
            return false;
        }

        $at = $referenceTime ?? new DateTimeImmutable('now');

        return $at >= $media->do_not_play_until;
    }
}
