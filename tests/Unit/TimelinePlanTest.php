<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Radio\AutoDJ\ClockWheel\TimelinePlan;
use Codeception\Test\Unit;
use Mockery;

class TimelinePlanTest extends Unit
{
    public function testDtoExposesConstructorArguments(): void
    {
        /** @var Station $station */
        $station = Mockery::mock(Station::class);
        $wheel   = new StationClockWheel($station);
        $slot    = new StationClockWheelSlot($wheel);
        $slot->position_seconds = 1800;

        $plan = new TimelinePlan($slot, 600, 1800);

        self::assertSame($slot, $plan->slot);
        self::assertSame(600,   $plan->availableSeconds);
        self::assertSame(1800,  $plan->currentT);
    }

    public function testDtoIsReadOnly(): void
    {
        self::assertTrue(
            (new \ReflectionClass(TimelinePlan::class))->isReadOnly(),
            'TimelinePlan must be readonly so callers cannot mutate the plan after construction.'
        );
    }
}
