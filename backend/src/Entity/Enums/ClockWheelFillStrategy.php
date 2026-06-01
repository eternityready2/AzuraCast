<?php

declare(strict_types=1);

namespace App\Entity\Enums;

/**
 * How the preview simulator (and future planner tuning) handles tight windows.
 */
enum ClockWheelFillStrategy: string
{
    /** Skip or defer slots when the window before the next anchor is too small. */
    case Conservative = 'conservative';

    /** Prefer the shortest fitting track to fill tight windows. */
    case Aggressive = 'aggressive';
}
