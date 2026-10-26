<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Entity\StationQueue;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * The clock hour an item airs in, as the Top-of-Hour ID decides it.
 *
 * A song due in the last 45 seconds before the ID is never sent: the swap holds
 * it, and it opens the hour after the ID. Playout counted only the last 5
 * seconds that way and the hour guard judged the held start, so the slot 35s
 * before the ID behind a show was given a song of the hour that was ending; the
 * swap held it for the next hour, the guard sent it back, and the queue picked
 * again, about 260 times from 10:06 to 11:00am on Mon 2026-10-12. A stream show
 * due 2s before the ID was judged outside its own window and thrown out every
 * 10s from 9:50 to 10pm on Sun 2026-10-11. Every Linear Log rule that asks which
 * hour an item is in asks here.
 */
final class LinearLogHour
{
    /**
     * The swap's hold window before the ID: TopOfHourSongSwapSelector::HOLD_WINDOW_SECONDS,
     * a private constant of the Top-of-Hour plugin; the two must stay equal.
     */
    public const float ID_HOLD_WINDOW_SECONDS = 45.0;

    public function __construct(
        private readonly TopOfHourClock $clock,
    ) {
    }

    /**
     * True when an item running $seconds that is due at $at cannot air before
     * the ID: it is due under the ID, or in the hold window and runs into it.
     * It airs once the ID hands the air back, in the next hour. Only an ID that
     * leads into the hour does that; one moved earlier gives the air back
     * inside the same hour.
     */
    public function opensNextHour(Station $station, int $at, float $seconds = INF): bool
    {
        if (!$this->clock->isEnabled($station) || $this->clock->getIdStartMinuteOffset($station) > 0) {
            return false;
        }

        $from = new DateTimeImmutable('@' . $at);
        if ($this->clock->clockWheelOwnsBoundary($station, $this->clock->getNextBoundary($station, $from))) {
            return false;
        }

        $gap = $this->clock->getTargetStartFor($station, $from)->getTimestamp() - $at;
        if ($gap > self::ID_HOLD_WINDOW_SECONDS) {
            return false;
        }

        return $gap <= 0 || $seconds > $gap + 1.0;
    }

    /**
     * End of the clock hour, in station time, that an item running $seconds
     * and due at $at airs in.
     */
    public function endOf(Station $station, int $at, float $seconds = INF): int
    {
        $end = CarbonImmutable::createFromTimestamp($at, $station->getTimezoneObject())
            ->startOfHour()
            ->addHour()
            ->getTimestamp();

        return $this->opensNextHour($station, $at, $seconds) ? $end + 3600 : $end;
    }

    /**
     * True when a row starting at $startsAt plays something other than $line
     * and airs in an earlier hour than it: the Top-of-Hour swap put a short
     * item that ends on the ID in the slot the next hour's line was given.
     */
    public function isFillerBeforeLine(Station $station, StationQueue $row, int $startsAt, StationLogEntry $line): bool
    {
        return $row->media?->id !== $line->media?->id
            && $this->endOf($station, $startsAt, (float)($row->duration ?? INF))
                < $this->endOf($station, $line->planned_at, $line->duration);
    }
}
