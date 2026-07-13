<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

/**
 * How a clock wheel slot uses a pinned playlist.
 *
 * - restrict_pool: filter candidates from the playlist, then apply the slot algorithm.
 * - playlist_rotation: delegate track order to the playlist's AutoDJ rotation rules.
 */
#[OA\Schema(type: 'string')]
enum ClockWheelSlotPoolModes: string
{
    case RestrictPool = 'restrict_pool';
    case PlaylistRotation = 'playlist_rotation';
}
