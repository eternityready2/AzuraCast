<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Entity\StationStreamer;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

final class ScheduleConflictChecker
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    /** @return StationSchedule[] */
    public function findConflicts(
        Station $station,
        StationSchedule $proposed,
        StationPlaylist|StationStreamer|StationClockWheel|null $excludeRelation = null,
        ?DateTimeImmutable $now = null
    ): array {
        $tz = $station->getTimezoneObject();
        $now = CarbonImmutable::instance($now ?? new DateTimeImmutable('now', $tz));
        [$rangeStart, $rangeEnd] = [$now->subDay(), $now->addDays(35)];

        $proposedOccurrences = $this->expandOccurrences($proposed, $tz, $rangeStart, $rangeEnd);
        if ($proposedOccurrences === []) {
            return [];
        }

        $conflicts = [];
        foreach ($this->loadAllSchedulesForStation($station) as $other) {
            if ($other->id !== null && $other->id === $proposed->id) {
                continue;
            }
            if ($excludeRelation !== null && $this->scheduleBelongsToRelation($other, $excludeRelation)) {
                continue;
            }

            $otherOccurrences = $this->expandOccurrences($other, $tz, $rangeStart, $rangeEnd);
            if ($otherOccurrences === []) {
                continue;
            }

            foreach ($proposedOccurrences as $p) {
                foreach ($otherOccurrences as $o) {
                    if ($this->rangesOverlap($p, $o)) {
                        $conflicts[] = $other;
                        continue 3;
                    }
                }
            }
        }

        return $conflicts;
    }

    public function isNonWheelScheduleActiveAt(Station $station, DateTimeImmutable $at): bool
    {
        $tz = $station->getTimezoneObject();
        $atTz = CarbonImmutable::instance($at)->setTimezone($tz);
        [$rangeStart, $rangeEnd] = [$atTz->subHours(2), $atTz->addHours(2)];

        foreach ($this->loadAllSchedulesForStation($station) as $item) {
            if ($item->clock_wheel !== null) {
                continue;
            }
            foreach ($this->expandOccurrences($item, $tz, $rangeStart, $rangeEnd) as $r) {
                if ($atTz->greaterThanOrEqualTo($r['start']) && $atTz->lessThan($r['end'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return StationSchedule[] */
    private function loadAllSchedulesForStation(Station $station): array
    {
        return $this->em->createQuery(
            <<<'DQL'
                SELECT ssc, sp, sst, scw
                FROM App\Entity\StationSchedule ssc
                LEFT JOIN ssc.playlist sp
                LEFT JOIN ssc.streamer sst
                LEFT JOIN ssc.clock_wheel scw
                WHERE (sp.station = :station AND sp.is_jingle = 0 AND sp.is_enabled = 1)
                   OR (sst.station = :station AND sst.is_active = 1)
                   OR (scw.station = :station AND scw.is_active = 1)
            DQL
        )->setParameter('station', $station)->getResult();
    }

    private function scheduleBelongsToRelation(
        StationSchedule $schedule,
        StationPlaylist|StationStreamer|StationClockWheel $relation
    ): bool {
        return match (true) {
            $relation instanceof StationPlaylist   => $schedule->playlist?->id === $relation->id,
            $relation instanceof StationStreamer   => $schedule->streamer?->id === $relation->id,
            $relation instanceof StationClockWheel => $schedule->clock_wheel?->id === $relation->id,
        };
    }

    /** @return array<array{start: CarbonImmutable, end: CarbonImmutable}> */
    private function expandOccurrences(
        StationSchedule $item,
        DateTimeZone $tz,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd
    ): array {
        if (ScheduleRecurrence::hasRecurrence($item)) {
            return array_map(
                static fn($dr) => [
                    'start' => CarbonImmutable::instance($dr->start),
                    'end'   => CarbonImmutable::instance($dr->end),
                ],
                ScheduleRecurrence::getOccurrencesInRange($item, $tz, $rangeStart, $rangeEnd, 200)
            );
        }

        $ranges = [];
        $i = $rangeStart->startOf('day');

        while ($i->lessThanOrEqualTo($rangeEnd)) {
            $days = $item->days;
            $applies = $days === [] || in_array((int)$i->format('N'), $days, true);

            if ($applies && $this->dateWithinStartEnd($item, $tz, $i)) {
                $start = CarbonImmutable::instance(StationSchedule::getDateTime($item->start_time, $tz, $i));
                $end = CarbonImmutable::instance(StationSchedule::getDateTime($item->end_time, $tz, $i));
                $end = $end->lessThanOrEqualTo($start) ? $end->addDay() : $end;
                $ranges[] = ['start' => $start, 'end' => $end];
            }

            $i = $i->addDay();
        }

        return $ranges;
    }

    private function dateWithinStartEnd(StationSchedule $item, DateTimeZone $tz, CarbonImmutable $on): bool
    {
        $onDate = $on->startOf('day');

        if (!empty($item->start_date)) {
            $sd = CarbonImmutable::createFromFormat('Y-m-d', $item->start_date, $tz);
            if ($sd !== false && $onDate->lessThan($sd->startOf('day'))) {
                return false;
            }
        }

        if (!empty($item->end_date)) {
            $ed = CarbonImmutable::createFromFormat('Y-m-d', $item->end_date, $tz);
            if ($ed !== false && $onDate->greaterThan($ed->startOf('day'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{start: CarbonImmutable, end: CarbonImmutable} $a
     * @param array{start: CarbonImmutable, end: CarbonImmutable} $b
     */
    private function rangesOverlap(array $a, array $b): bool
    {
        return $a['start']->lessThan($b['end']) && $b['start']->lessThan($a['end']);
    }
}
