<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\Scheduler;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;

/**
 * Verifies clock wheel schedule rows use the same activation rules as playlists.
 */
final class ClockWheelScheduleActivationTest extends Unit
{
    private Scheduler $scheduler;

    private Station $station;

    protected function _inject(Module $testsModule): void
    {
        $this->scheduler = $testsModule->container->get(Scheduler::class);

        $this->station = new Station();
        $this->station->name = 'Clock Wheel Test Station';
        $this->station->short_name = 'cw_test';
        $this->station->timezone = 'UTC';
    }

    public function testActiveDuringScheduledWindow(): void
    {
        $schedule = $this->makeSchedule(900, 1700, '2026-01-01', '2026-12-31', [1, 2, 3, 4, 5]);

        $now = CarbonImmutable::parse('2026-05-26 10:00:00', 'UTC');

        self::assertTrue(
            $this->scheduler->shouldSchedulePlayNow($schedule, $this->station->getTimezoneObject(), $now)
        );
    }

    public function testInactiveOutsideScheduledWindow(): void
    {
        $schedule = $this->makeSchedule(900, 1700, '2026-01-01', '2026-12-31', [1, 2, 3, 4, 5]);

        $now = CarbonImmutable::parse('2026-05-26 20:00:00', 'UTC');

        self::assertFalse(
            $this->scheduler->shouldSchedulePlayNow($schedule, $this->station->getTimezoneObject(), $now)
        );
    }

    public function testOvernightScheduleActiveAfterMidnight(): void
    {
        $schedule = $this->makeSchedule(2200, 600, '2026-01-01', '2026-12-31', [1, 2, 3, 4, 5, 6, 7]);

        $now = CarbonImmutable::parse('2026-05-27 02:00:00', 'UTC');

        self::assertTrue(
            $this->scheduler->shouldSchedulePlayNow($schedule, $this->station->getTimezoneObject(), $now)
        );
    }

    public function testPlayOnceWindowAtSameStartAndEndTime(): void
    {
        $schedule = $this->makeSchedule(1000, 1000, '2026-05-26', '2026-05-26', [2]);

        $now = CarbonImmutable::parse('2026-05-26 10:05:00', 'UTC');

        self::assertTrue(
            $this->scheduler->shouldSchedulePlayNow($schedule, $this->station->getTimezoneObject(), $now)
        );

        $later = CarbonImmutable::parse('2026-05-26 10:20:00', 'UTC');

        self::assertFalse(
            $this->scheduler->shouldSchedulePlayNow($schedule, $this->station->getTimezoneObject(), $later)
        );
    }

    /**
     * @param int[] $days ISO weekdays (1 = Monday … 7 = Sunday)
     */
    private function makeSchedule(
        int $startTime,
        int $endTime,
        ?string $startDate,
        ?string $endDate,
        array $days,
    ): StationSchedule {
        $wheel = new StationClockWheel($this->station);
        $schedule = new StationSchedule($wheel);
        $schedule->start_time = $startTime;
        $schedule->end_time = $endTime;
        $schedule->start_date = $startDate;
        $schedule->end_date = $endDate;
        $schedule->days = $days;

        return $schedule;
    }
}
