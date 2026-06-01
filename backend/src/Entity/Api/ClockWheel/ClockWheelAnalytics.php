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
}
