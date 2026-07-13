<?php

declare(strict_types=1);

namespace App\Entity\Api\ClockWheel;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'object')]
final class ClockWheelReconciliationLog
{
    /**
     * @var ClockWheelReconciliationEvent[]
     */
    #[OA\Property(
        type: 'array',
        items: new OA\Items(ref: ClockWheelReconciliationEvent::class)
    )]
    public array $rows = [];

    #[OA\Property(example: 42)]
    public int $total = 0;
}
