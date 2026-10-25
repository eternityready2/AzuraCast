<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Entity\StationClockWheel;
use App\Entity\StationLogEntry;
use App\Entity\StationPlaylist;
use App\Radio\AutoDJ\Scheduler;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Whether the schedule lets a log line's source play at a given time: the test
 * ScheduleWindowGuard applies to the line's queue row, asked of the line.
 *
 * A line is planned for one time and can be projected at another: the queue
 * re-times its rows on every pass, and that projection has been off by an hour
 * behind a stream show and by a minute at a block edge. The plan is only wrong
 * when the line may not play at its own planned time. Playout and the guard
 * both ask here, so a line that is right where the log has it is never offered
 * for, or thrown out at, a time it was not planned for.
 */
final class LinearLogLineWindow
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
    }

    public function mayAirAt(StationLogEntry $entry, DateTimeInterface $at): bool
    {
        $at = DateTimeImmutable::createFromInterface($at);

        // A wheel's tracks come from member playlists that usually carry no
        // schedule of their own: the wheel that owns the slot is what is judged.
        $clockWheelId = $entry->payload['clock_wheel_id'] ?? null;
        if (null !== $clockWheelId) {
            $clockWheel = $this->em->find(StationClockWheel::class, (int)$clockWheelId);
            if ($clockWheel instanceof StationClockWheel && $clockWheel->station->id === $entry->station->id) {
                return $this->scheduler->isClockWheelAllowedAt($clockWheel, $at);
            }
        }

        $playlist = $entry->playlist;

        return !$playlist instanceof StationPlaylist
            || $this->scheduler->isPlaylistAllowedAt($playlist, $at);
    }

    public function mayAirAsPlanned(StationLogEntry $entry): bool
    {
        return $this->mayAirAt($entry, new DateTimeImmutable('@' . $entry->planned_at));
    }
}
