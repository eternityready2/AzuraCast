<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\TopOfHour;

/**
 * Where the Top-of-Hour numbers start counting, when the server sets one:
 * TOP_OF_HOUR_STATS_START in azuracast.env (any date strtotime() reads, e.g.
 * 2026-10-26T01:38:45Z). Hours aired before it ran on older ID timing code
 * and dragged the scores far below what the station does now (production on
 * Oct 29, 2026: 51.8% shown, 89.6% since its Oct 25 update).
 *
 * Not set (the default, and the test server): the stats reach back as far as
 * the period asks.
 */
final class TopOfHourStatsStart
{
    public const string ENV_NAME = 'TOP_OF_HOUR_STATS_START';

    /** Unix time the stats start, or null when the server sets none. */
    public static function get(): ?int
    {
        $value = getenv(self::ENV_NAME);
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $start = strtotime(trim($value));

        return false === $start ? null : $start;
    }

    /** $from, moved up to the start when it is earlier. */
    public static function clamp(int $from): int
    {
        return max($from, self::get() ?? $from);
    }
}
