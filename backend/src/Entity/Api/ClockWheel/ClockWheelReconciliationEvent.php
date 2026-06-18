<?php

declare(strict_types=1);

namespace App\Entity\Api\ClockWheel;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'object')]
final class ClockWheelReconciliationEvent
{
    #[OA\Property(example: 1)]
    public int $id;

    #[OA\Property(example: '2026-06-09T14:05:00-05:00')]
    public string $event_timestamp;

    #[OA\Property(example: 'track_queued')]
    public string $event_kind;

    #[OA\Property(example: 'hard_anchor_missed', nullable: true)]
    public ?string $fallback_reason = null;

    #[OA\Property(example: 12, nullable: true)]
    public ?int $clock_wheel_id = null;

    #[OA\Property(example: 'Morning Drive', nullable: true)]
    public ?string $clock_wheel_name = null;

    #[OA\Property(example: 45, nullable: true)]
    public ?int $slot_id = null;

    #[OA\Property(example: 'music', nullable: true)]
    public ?string $anchor_type = null;

    #[OA\Property(example: 'P1', nullable: true)]
    public ?string $sound_code = null;

    #[OA\Property(example: 85, nullable: true)]
    public ?int $research_score = null;

    #[OA\Property(example: -3, nullable: true)]
    public ?int $drift_seconds = null;

    #[OA\Property(example: '2026-06-09T14:00:00-05:00', nullable: true)]
    public ?string $expected_play_at = null;

    #[OA\Property(example: '2026-06-09T14:00:03-05:00', nullable: true)]
    public ?string $actual_play_at = null;

    #[OA\Property(example: 101, nullable: true)]
    public ?int $media_id = null;
}
