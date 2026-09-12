<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use Carbon\CarbonImmutable;

final readonly class RigidScheduleForecastItem
{
    public function __construct(
        public StationPlaylist $playlist,
        public StationSchedule $schedule,
        public StationMedia $media,
        public CarbonImmutable $playedAt,
        public float $duration,
    ) {
    }
}
