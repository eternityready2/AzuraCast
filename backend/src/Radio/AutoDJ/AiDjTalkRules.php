<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;

/**
 * Station-wide AI DJ talk rules, stored in the backend configuration's extra-data
 * bag (no schema migration) and edited on the AI DJ page.
 */
final class AiDjTalkRules
{
    public const string CONFIG_IDENT_INTERVAL_MINUTES = 'ai_dj_ident_interval_minutes';
    public const string CONFIG_QUIET_BEFORE_HOUR_MINUTES = 'ai_dj_quiet_before_hour_minutes';
    public const string CONFIG_QUIET_AFTER_HOUR_MINUTES = 'ai_dj_quiet_after_hour_minutes';

    /** @var array<string, array{default: int, min: int, max: int}> */
    public const array LIMITS = [
        self::CONFIG_IDENT_INTERVAL_MINUTES => ['default' => 20, 'min' => 0, 'max' => 120],
        self::CONFIG_QUIET_BEFORE_HOUR_MINUTES => ['default' => 15, 'min' => 0, 'max' => 30],
        self::CONFIG_QUIET_AFTER_HOUR_MINUTES => ['default' => 3, 'min' => 0, 'max' => 15],
    ];

    /** @return array<string, int> */
    public static function all(Station $station): array
    {
        $raw = $station->backend_config->toArray(true) ?? [];

        $values = [];
        foreach (self::LIMITS as $key => $limit) {
            $value = (int)($raw[$key] ?? $limit['default']);
            $values[$key] = ($value < $limit['min'] || $value > $limit['max']) ? $limit['default'] : $value;
        }

        return $values;
    }

    /** 0 means the DJ may name herself and the station on every break. */
    public static function identIntervalSeconds(Station $station): int
    {
        return self::all($station)[self::CONFIG_IDENT_INTERVAL_MINUTES] * 60;
    }

    /** Seconds into the hour after which a DJ break must not air. */
    public static function speechCutoffSecondsIntoHour(Station $station): int
    {
        return 3600 - self::all($station)[self::CONFIG_QUIET_BEFORE_HOUR_MINUTES] * 60;
    }

    /** Minutes after :00 during which no DJ break is queued. */
    public static function quietAfterHourMinutes(Station $station): int
    {
        return self::all($station)[self::CONFIG_QUIET_AFTER_HOUR_MINUTES];
    }
}
