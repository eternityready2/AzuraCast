<?php

declare(strict_types=1);

namespace Unit;

use App\Sync\Task\AiDjShiftLifecycleRuntimeTask;
use Codeception\Test\Unit;
use ReflectionClass;

final class AiDjRuntimeCadenceTest extends Unit
{
    public function testProductionCalibratedCadenceIntervals(): void
    {
        $reflection = new ReflectionClass(AiDjShiftLifecycleRuntimeTask::class);
        $task = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('getTalkIntervalSeconds');

        // Confirmed DJ frequencies from production settings:
        // Bella = 75%  -> 270 / 0.75 = 360s (~6 min)
        self::assertSame(360, $method->invoke($task, 0.75));

        // Onyx = 100% -> 270 / 1.00 = 270s (~4.5 min)
        self::assertSame(270, $method->invoke($task, 1.00));

        // Lower boundary check: 50% -> 270 / 0.50 = 540s (~9 min)
        self::assertSame(540, $method->invoke($task, 0.50));
    }

    public function testProductionHourlyTalkCeiling(): void
    {
        $reflection = new ReflectionClass(AiDjShiftLifecycleRuntimeTask::class);

        // Onyx at 100% legitimately hits 10-12 on-air breaks per hour.
        // Ceiling raised from 8 to 12 to stop Onyx going silent mid-hour.
        self::assertSame(12, $reflection->getConstant('MAX_TALK_BREAKS_PER_HOUR'));
    }
}
