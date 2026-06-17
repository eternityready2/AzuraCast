<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\Api\ClockWheel\ClockWheelAnalytics;
use App\Entity\Repository\ClockWheelEventRepository;
use App\Entity\StationClockWheel;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use Carbon\CarbonImmutable;

final class ClockWheelAnalyticsService
{
    public function __construct(
        private readonly ClockWheelEventRepository $eventRepo,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
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

        $compliance = $this->eventRepo->getLegalIdComplianceSummary(
            $wheel,
            $since,
            $this->hourBoundaryPlanner->getComplianceToleranceSeconds($wheel->station),
        );

        $analytics->legal_id_tolerance_seconds = $compliance['tolerance_seconds'];
        $analytics->legal_id_hours_logged = $compliance['hours_with_legal_id'];
        $analytics->legal_id_on_time_count = $compliance['on_time_count'];
        $analytics->legal_id_late_count = $compliance['late_count'];
        $analytics->legal_id_compliance_percent = $compliance['compliance_percent'];
        $analytics->legal_id_late_events = $compliance['late_events'];

        return $analytics;
    }
}
