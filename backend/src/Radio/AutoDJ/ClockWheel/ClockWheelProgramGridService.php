<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\Api\ClockWheel\ClockWheelProgramGrid;
use App\Entity\Api\ClockWheel\ClockWheelProgramGridCell;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\Scheduler;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds a 7×24 weekly program grid from daypart wheels and calendar schedules (D1).
 */
final class ClockWheelProgramGridService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StationScheduleRepository $scheduleRepo,
        private readonly Scheduler $scheduler,
    ) {
    }

    public function getGrid(Station $station, ?DateTimeImmutable $weekStart = null): ClockWheelProgramGrid
    {
        $tz = $station->getTimezoneObject();
        $start = CarbonImmutable::instance($weekStart ?? new DateTimeImmutable('now', $tz))
            ->setTimezone($tz)
            ->startOfWeek(CarbonImmutable::MONDAY)
            ->startOfDay();

        $response = new ClockWheelProgramGrid();
        $response->week_start = $start->format('Y-m-d');
        $response->week_end = $start->addDays(6)->format('Y-m-d');

        /** @var array<string, ClockWheelProgramGridCell> $cellMap */
        $cellMap = [];

        for ($day = 1; $day <= 7; $day++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $key = $day . '_' . $hour;
                $cell = new ClockWheelProgramGridCell();
                $cell->day_of_week = $day;
                $cell->hour = $hour;
                $cellMap[$key] = $cell;
            }
        }

        $this->fillFromDayparts($station, $cellMap);
        $this->fillFromSchedules($station, $start, $cellMap);

        $response->cells = array_values($cellMap);

        return $response;
    }

    /**
     * @param array<string, ClockWheelProgramGridCell> $cellMap
     */
    private function fillFromDayparts(Station $station, array &$cellMap): void
    {
        $wheels = $this->em->createQuery(
            <<<'DQL'
                SELECT w, d
                FROM App\Entity\StationClockWheel w
                JOIN w.daypart d
                WHERE w.station = :station
                AND w.is_active = 1
                AND d.is_active = 1
                AND w.hour_of_day IS NOT NULL
            DQL
        )->setParameter('station', $station)
            ->execute();

        foreach ($wheels as $wheel) {
            if (!$wheel instanceof StationClockWheel || $wheel->daypart === null) {
                continue;
            }

            $hour = $wheel->hour_of_day;
            if ($hour === null) {
                continue;
            }

            for ($day = 1; $day <= 7; $day++) {
                $key = $day . '_' . $hour;
                if (!isset($cellMap[$key]) || $cellMap[$key]->source === 'schedule') {
                    continue;
                }

                $cell = $cellMap[$key];
                $cell->wheel_id = $wheel->id;
                $cell->wheel_name = $wheel->name;
                $cell->wheel_color = $wheel->color;
                $cell->source = 'daypart';
                $cell->daypart_name = $wheel->daypart->name;
            }
        }
    }

    /**
     * @param array<string, ClockWheelProgramGridCell> $cellMap
     */
    private function fillFromSchedules(
        Station $station,
        CarbonImmutable $weekStart,
        array &$cellMap,
    ): void {
        $tz = $station->getTimezoneObject();

        foreach ($this->scheduleRepo->getAllScheduledItemsForStation($station) as $schedule) {
            if (!$schedule instanceof StationSchedule || $schedule->clock_wheel === null) {
                continue;
            }

            $wheel = $schedule->clock_wheel;
            if (!$wheel->is_active) {
                continue;
            }

            for ($offset = 0; $offset < 7; $offset++) {
                $dayDate = $weekStart->addDays($offset);
                $dayOfWeek = $dayDate->dayOfWeekIso;

                if (!$this->scheduler->shouldSchedulePlayOnCurrentDate($schedule, $tz, $dayDate)) {
                    continue;
                }

                if (!$this->scheduler->isScheduleScheduledToPlayToday($schedule, $dayOfWeek)) {
                    continue;
                }

                $rowStart = StationSchedule::getDateTime($schedule->start_time, $tz, $dayDate);
                $rowEnd = StationSchedule::getDateTime($schedule->end_time, $tz, $dayDate);
                if ($rowEnd < $rowStart) {
                    $rowEnd = $rowEnd->addDay();
                }

                $cursor = $rowStart->copy();
                while ($cursor->lessThan($rowEnd)) {
                    $hour = (int)$cursor->format('G');
                    $key = $dayOfWeek . '_' . $hour;

                    if (isset($cellMap[$key])) {
                        $cell = $cellMap[$key];
                        $cell->wheel_id = $wheel->id;
                        $cell->wheel_name = $wheel->name;
                        $cell->wheel_color = $wheel->color;
                        $cell->source = 'schedule';
                        $cell->daypart_name = null;
                    }

                    $cursor = $cursor->addHour();
                }
            }
        }
    }
}
