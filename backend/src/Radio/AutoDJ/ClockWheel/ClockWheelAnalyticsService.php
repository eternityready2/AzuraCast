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

        $analytics->effectiveness_score = $this->computeEffectivenessScore($analytics);
        $analytics->effectiveness_grade = $this->gradeFromScore($analytics->effectiveness_score);

        $listenerStats = $this->eventRepo->getListenerOverlayForWheel($wheel, $since);
        $analytics->avg_listeners = $listenerStats['avg_listeners'];
        $analytics->peak_listeners = $listenerStats['peak_listeners'];

        return $analytics;
    }

    private function computeEffectivenessScore(ClockWheelAnalytics $analytics): ?float
    {
        if ($analytics->tracks_queued === 0 && $analytics->fallbacks === 0) {
            return null;
        }

        $score = 100.0;
        $score -= min(40.0, $analytics->fallbacks * 2.0);
        $score -= min(20.0, $analytics->deferred * 1.0);
        $score -= min(15.0, ($analytics->avg_drift_seconds ?? 0.0) / 2.0);
        $score -= min(25.0, $analytics->legal_id_late_count * 5.0);
        $score -= min(10.0, $analytics->separation_relaxed_count * 0.5);

        return max(0.0, min(100.0, round($score, 1)));
    }

    private function gradeFromScore(?float $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }
}
