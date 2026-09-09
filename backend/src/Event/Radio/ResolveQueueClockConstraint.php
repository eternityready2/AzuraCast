<?php

declare(strict_types=1);

namespace App\Event\Radio;

use App\Entity\Station;
use App\Entity\StationQueue;
use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Generic extension point for absolute wall-clock interruptions that occupy
 * time outside the ordinary AutoDJ queue.
 *
 * Core AutoDJ owns queue construction and timestamp persistence. Plugins may
 * constrain one projected item by supplying the point where ordinary playout
 * must yield and the wall-clock instant when ordinary playout may resume.
 *
 * A provider may also defer an item whose projected start already falls inside
 * an externally-owned interval. This lets queue rebuilding recover stale rows
 * without manufacturing one- or two-second songs inside the reserved window.
 */
final class ResolveQueueClockConstraint extends Event
{
    private ?DateTimeImmutable $interruptAt = null;

    private ?DateTimeImmutable $resumeAt = null;

    private ?DateTimeImmutable $deferUntil = null;

    private ?string $reason = null;

    private bool $persistDurationCap = true;

    public function __construct(
        private readonly Station $station,
        private readonly DateTimeImmutable $expectedPlayAt,
        private readonly DateTimeImmutable $projectedEndAt,
        private readonly ?StationQueue $queueRow = null,
    ) {
    }

    public function getStation(): Station
    {
        return $this->station;
    }

    public function getExpectedPlayAt(): DateTimeImmutable
    {
        return $this->expectedPlayAt;
    }

    public function getProjectedEndAt(): DateTimeImmutable
    {
        return $this->projectedEndAt;
    }

    public function getQueueRow(): ?StationQueue
    {
        return $this->queueRow;
    }

    public function constrain(
        DateTimeImmutable $interruptAt,
        DateTimeImmutable $resumeAt,
        string $reason,
        bool $persistDurationCap = true,
    ): void {
        if (
            $interruptAt <= $this->expectedPlayAt
            || $interruptAt > $this->projectedEndAt
            || $resumeAt < $interruptAt
        ) {
            return;
        }

        // Multiple clock-policy providers may exist. The earliest interruption
        // wins because later content cannot consume airtime beyond it.
        if (null !== $this->interruptAt && $this->interruptAt <= $interruptAt) {
            return;
        }

        $this->interruptAt = $interruptAt;
        $this->resumeAt = $resumeAt;
        $this->deferUntil = null;
        $this->reason = $reason;
        $this->persistDurationCap = $persistDurationCap;
    }

    /**
     * Move a projected item whose start already falls inside external clock
     * ownership to the first instant normal AutoDJ may resume.
     */
    public function deferUntil(
        DateTimeImmutable $resumeAt,
        string $reason,
    ): void {
        if ($resumeAt <= $this->expectedPlayAt) {
            return;
        }

        // If several providers defer the same stale row, normal playout cannot
        // resume until all overlapping ownership windows have ended.
        if (null !== $this->deferUntil && $this->deferUntil >= $resumeAt) {
            return;
        }

        $this->interruptAt = null;
        $this->resumeAt = null;
        $this->deferUntil = $resumeAt;
        $this->reason = $reason;
        $this->persistDurationCap = false;
    }

    public function hasConstraint(): bool
    {
        return null !== $this->interruptAt && null !== $this->resumeAt;
    }

    public function hasDeferral(): bool
    {
        return null !== $this->deferUntil;
    }

    public function getInterruptAt(): ?DateTimeImmutable
    {
        return $this->interruptAt;
    }

    public function getResumeAt(): ?DateTimeImmutable
    {
        return $this->resumeAt;
    }

    public function getDeferUntil(): ?DateTimeImmutable
    {
        return $this->deferUntil;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function shouldPersistDurationCap(): bool
    {
        return $this->persistDurationCap;
    }
}
