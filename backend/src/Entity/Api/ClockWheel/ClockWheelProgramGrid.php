<?php

declare(strict_types=1);

namespace App\Entity\Api\ClockWheel;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'object')]
final class ClockWheelProgramGrid
{
    #[OA\Property(example: '2026-06-09')]
    public string $week_start;

    #[OA\Property(example: '2026-06-15')]
    public string $week_end;

    /**
     * @var ClockWheelProgramGridCell[]
     */
    #[OA\Property(
        type: 'array',
        items: new OA\Items(ref: ClockWheelProgramGridCell::class)
    )]
    public array $cells = [];
}
