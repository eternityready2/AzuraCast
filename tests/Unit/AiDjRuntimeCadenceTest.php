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

        // Production main history: Bella (50%) lands around every 10 minutes.
        self::assertSame(600, $method->invoke($task, 0.50));

        // Production main history: Onyx (75%) lands around every 6m40s / 7 min.
        self::assertSame(400, $method->invoke($task, 0.75));

        self::assertSame(300, $method->invoke($task, 1.00));
    }

    public function testProductionHourlyTalkCeiling(): void
    {
        $reflection = new ReflectionClass(AiDjShiftLifecycleRuntimeTask::class);

        // Production Onyx commonly lands around 7-8 heard breaks/hour while Bella
        // naturally remains lower because her configured 50% cadence is slower.
        self::assertSame(8, $reflection->getConstant('MAX_TALK_BREAKS_PER_HOUR'));
    }
}
