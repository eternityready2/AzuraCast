<?php

declare(strict_types=1);

namespace App\Entity\Api\ClockWheel;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'object')]
final class ClockWheelExport
{
    #[OA\Property(example: '1.0')]
    public string $export_version = '1.0';

    #[OA\Property(example: 'Morning Drive')]
    public string $name = '';

    #[OA\Property(example: '#e87722')]
    public string $color = '#e87722';

    #[OA\Property(example: 'conservative')]
    public string $fill_strategy = 'conservative';

    #[OA\Property(example: false)]
    public bool $separation_enabled = false;

    #[OA\Property(example: 45, nullable: true)]
    public ?int $separation_artist_minutes = null;

    #[OA\Property(example: 90, nullable: true)]
    public ?int $separation_title_minutes = null;

    #[OA\Property(example: null, nullable: true)]
    public ?int $burn_rate_max_plays_24h = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    #[OA\Property(type: 'array', items: new OA\Items(type: 'object'))]
    public array $slots = [];
}
