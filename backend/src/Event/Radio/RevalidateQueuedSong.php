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
    /** Set by a plugin when this row must not be handed to the backend yet. */
    private bool $holdBack = false;

    /** When a held row will actually start (e.g. after the Top-of-Hour ID and news). */
    private ?DateTimeImmutable $opensAfter = null;

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

    /**
     * Keep the row queued but unsent: at the hand-off it would start now and
     * be cut (e.g. by the Top-of-Hour ID), so it waits and opens the next
     * segment instead. Only the hand-off check honours this.
     */
    public function holdBack(): void
    {
        $this->holdBack = true;
    }

    public function isHeldBack(): bool
    {
        return $this->holdBack;
    }

    /**
     * The row waits for something that owns the air first (the Top-of-Hour ID
     * and news): project it from $time so the queue, Playing Next and the
     * Linear Log show it where it will really air.
     */
    public function opensAfter(DateTimeImmutable $time): void
    {
        $this->opensAfter = $time;
    }

    public function getOpensAfter(): ?DateTimeImmutable
    {
        return $this->opensAfter;
    }
}
