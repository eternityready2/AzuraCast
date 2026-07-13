<?php

declare(strict_types=1);

namespace App\Entity\Api\ClockWheel;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'object')]
final class ClockWheelProgramGridCell
{
    #[OA\Property(example: 1)]
    public int $day_of_week = 1;

    #[OA\Property(example: 9)]
    public int $hour = 0;

    #[OA\Property(example: 12, nullable: true)]
    public ?int $wheel_id = null;

    #[OA\Property(example: 'Morning Drive', nullable: true)]
    public ?string $wheel_name = null;

    #[OA\Property(example: '#e87722', nullable: true)]
    public ?string $wheel_color = null;

    #[OA\Property(example: 'daypart', nullable: true)]
    public ?string $source = null;

    #[OA\Property(example: 'Morning Drive', nullable: true)]
    public ?string $daypart_name = null;
}
