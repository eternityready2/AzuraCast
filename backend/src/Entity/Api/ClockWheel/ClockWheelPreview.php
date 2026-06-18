<?php

declare(strict_types=1);

namespace App\Entity\Api\ClockWheel;

use App\OpenApi;
use OpenApi\Attributes as OA;

#[OA\Schema(type: 'object')]
final class ClockWheelPreview
{
    #[OA\Property(example: OpenApi::SAMPLE_TIMESTAMP)]
    public int $hour_start_timestamp;

    #[OA\Property(example: '2026-05-30T14:00:00-05:00')]
    public string $hour_start;

    /**
     * @var ClockWheelPreviewItem[]
     */
    #[OA\Property(
        type: 'array',
        items: new OA\Items(ref: ClockWheelPreviewItem::class)
    )]
    public array $items = [];

    /**
     * @var string[]
     */
    #[OA\Property(
        type: 'array',
        items: new OA\Items(type: 'string')
    )]
    public array $warnings = [];

    #[OA\Property(example: 3540, nullable: true)]
    public ?int $estimated_loop_seconds = null;

    #[OA\Property(example: true)]
    public bool $is_valid = true;
}
