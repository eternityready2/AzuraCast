<?php

declare(strict_types=1);

namespace App\Entity\Api\ClockWheel;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'object')]
final class ClockWheelAnalytics
{
    #[OA\Property(example: 7)]
    public int $days = 7;

    #[OA\Property(example: 42)]
    public int $tracks_queued = 0;

    #[OA\Property(example: 3)]
    public int $deferred = 0;

    #[OA\Property(example: 5)]
    public int $fallbacks = 0;

    #[OA\Property(example: 12.5, nullable: true)]
    public ?float $avg_drift_seconds = null;

    #[OA\Property(example: 0)]
    public int $separation_relaxed_count = 0;

    #[OA\Property(example: 0)]
    public int $burn_rate_warning_count = 0;

    /**
     * @var array<string, int>
     */
    #[OA\Property(
        type: 'object',
        additionalProperties: new OA\AdditionalProperties(type: 'integer')
    )]
    public array $fallback_reasons = [];

    #[OA\Property(example: 10)]
    public int $legal_id_tolerance_seconds = 10;

    #[OA\Property(example: 168)]
    public int $legal_id_hours_logged = 0;

    #[OA\Property(example: 160)]
    public int $legal_id_on_time_count = 0;

    #[OA\Property(example: 8)]
    public int $legal_id_late_count = 0;

    #[OA\Property(example: 95.2, nullable: true)]
    public ?float $legal_id_compliance_percent = null;

    /**
     * @var array<int, array{expected_play_at: string, actual_play_at: string|null, drift_seconds: int|null, media_id: int|null}>
     */
    #[OA\Property(type: 'array', items: new OA\Items(type: 'object'))]
    public array $legal_id_late_events = [];
}
