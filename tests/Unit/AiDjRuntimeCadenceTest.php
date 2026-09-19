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

        // Calibrated against the production (main branch) 13-day playback log,
        // Sept 6-18, deduplicated by unique on-air timestamp:
        //   Onyx  (100% / 1.0)  averaged 5.67 breaks/hr (range 5.0-6.3/hr)
        //   Bella ( 75% / 0.75) averaged 5.00 breaks/hr (range 4.2-5.9/hr)
        // Bella = 75%  -> 600 / 0.75 = 800s (~13.3 min, -> ~4.5/hr)
        self::assertSame(800, $method->invoke($task, 0.75));

        // Onyx = 100% -> 600 / 1.00 = 600s (~10 min, -> ~6.0/hr)
        self::assertSame(600, $method->invoke($task, 1.00));

        // Lower boundary check: 50% -> 600 / 0.50 = 1200s (~20 min)
        self::assertSame(1200, $method->invoke($task, 0.50));
    }

    public function testProductionHourlyTalkCeiling(): void
    {
        $reflection = new ReflectionClass(AiDjShiftLifecycleRuntimeTask::class);

        // Production's own observed maximum across the 13-day log was 7.67
        // breaks/hr (Onyx, Sept 11) and 5.86 breaks/hr (Bella, Sept 14).
        // Ceiling set to 8 as a runaway backstop for the wall-clock catch-up
        // path used during Strict playlists (Hymns & Favorites), where no
        // real BuildQueue song-boundary event fires to drive normal cadence.
        self::assertSame(8, $reflection->getConstant('MAX_TALK_BREAKS_PER_HOUR'));
    }
}
