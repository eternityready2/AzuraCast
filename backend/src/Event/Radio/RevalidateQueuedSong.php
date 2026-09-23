<?php

declare(strict_types=1);

namespace App\Event\Radio;

use App\Entity\Station;
use App\Entity\StationQueue;
use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired once per already-queued, not-yet-sent row on every AutoDJ queue
 * rebuild cycle, right after that row's projected play time has been
 * refreshed against actual current playback.
 *
 * Core AutoDJ owns queue construction; a plugin may freely replace the
 * row's song in place (it is safe to mutate $queueRow directly, e.g. via
 * StationQueue::setSong()/->media=) when live drift means a choice made
 * at build time -- such as a Top-of-Hour duration match -- no longer
 * holds. Core makes no assumption about why a replacement happens and
 * simply persists whatever the row looks like afterward.
 */
final class RevalidateQueuedSong extends Event
{
    public function __construct(
        private readonly Station $station,
        private readonly StationQueue $queueRow,
        private readonly DateTimeImmutable $expectedPlayAt,
    ) {
    }

    public function getStation(): Station
    {
        return $this->station;
    }

    public function getQueueRow(): StationQueue
    {
        return $this->queueRow;
    }

    public function getExpectedPlayAt(): DateTimeImmutable
    {
        return $this->expectedPlayAt;
    }
}
