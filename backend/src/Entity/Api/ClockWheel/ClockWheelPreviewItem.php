<?php

declare(strict_types=1);

namespace App\Entity\Api\ClockWheel;

use App\OpenApi;
use OpenApi\Attributes as OA;

#[OA\Schema(type: 'object')]
final class ClockWheelPreviewItem
{
    #[OA\Property(example: 120)]
    public int $position_seconds;

    #[OA\Property(example: '2:00')]
    public string $position_label;

    #[OA\Property(example: '2026-06-08T14:02:05+00:00', nullable: true)]
    public ?string $projected_play_at = null;

    #[OA\Property(example: 'music')]
    public string $slot_type;

    #[OA\Property(example: 'Example Song')]
    public ?string $title = null;

    #[OA\Property(example: 'Example Artist')]
    public ?string $artist = null;

    #[OA\Property(example: 180)]
    public ?int $duration_seconds = null;

    #[OA\Property(example: 5)]
    public int $drift_seconds = 0;

    /**
     * @var string[]
     */
    #[OA\Property(
        type: 'array',
        items: new OA\Items(type: 'string')
    )]
    public array $warnings = [];
}
