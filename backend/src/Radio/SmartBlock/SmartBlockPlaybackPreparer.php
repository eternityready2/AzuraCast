<?php

declare(strict_types=1);

namespace App\Radio\SmartBlock;

use App\Entity\StationPlaylist;

/**
 * Smart blocks are their own records now (StationSmartBlock), played by
 * SmartBlockScheduler; a playlist never needs preparing. Kept so QueueBuilder's
 * call sites stay as they are.
 */
final class SmartBlockPlaybackPreparer
{
    public function beginQueueBuild(): void
    {
    }

    public function prepare(StationPlaylist $playlist): bool
    {
        return true;
    }
}
