<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum ClockWheelDurationEnforcement: string
{
    /** Track selection only; no playback cap via AutoDJ annotations. */
    case Php = 'php';

    /** Apply cue_out caps through the standard AnnotateNextSong / Liquidsoap path. */
    case Annotate = 'annotate';
}
