<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\Api\ClockWheel\ClockWheelAnalytics;
use App\Entity\Repository\ClockWheelEventRepository;
use App\Entity\StationClockWheel;
use Carbon\CarbonImmutable;

final class ClockWheelAnalyticsService
{
    public function __construct(
        private readonly ClockWheelEventRepository $eventRepo,
    ) {
    }

    public function getForWheel(StationClockWheel $wheel, int $days = 7): ClockWheelAnalytics
    {
        $days = max(1, min(90, $days));
        $tz = $wheel->station->getTimezoneObject();
        $since = CarbonImmutable::now($tz)->subDays($days)->startOfDay();

        $summary = $this->eventRepo->getAnalyticsSummary($wheel, $since);

        $analytics = new ClockWheelAnalytics();
        $analytics->days = $days;
        $analytics->tracks_queued = $summary['tracks_queued'];
        $analytics->deferred = $summary['deferred'];
        $analytics->fallbacks = $summary['fallbacks'];
        $analytics->avg_drift_seconds = $summary['avg_drift'];
        $analytics->separation_relaxed_count = $summary['separation_relaxed'];
        $analytics->burn_rate_warning_count = $summary['burn_rate_warning'];
        $analytics->fallback_reasons = $summary['fallback_reasons'];

        return $analytics;
    }
}
