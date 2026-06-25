<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum PlaylistOrders: string
{
    case Random = 'random';
    case Shuffle = 'shuffle';
    case Sequential = 'sequential';
    /** @deprecated Legacy DB value — treated as {@see Shuffle} in AutoDJ; use Avoid Duplicate Artists/Songs on the playlist instead. */
    case SmartShuffle = 'smart_shuffle';
}
