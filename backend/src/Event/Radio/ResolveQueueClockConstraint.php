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
 * Core contains no knowledge of the policy that created the constraint.
 */
final class ResolveQueueClockConstraint extends Event
{
    private ?DateTimeImmutable $interruptAt = null;

    private ?DateTimeImmutable $resumeAt = null;

    private ?string $reason = null;

    private bool $projectionOnly = false;

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

    /**
     * @param bool $projectionOnly Move the projection cursor across the
     *   interruption without capping the item. For future rows: the runtime
     *   performs the real cut, and a cap persisted on a row outlives the timing
     *   it was computed from.
     */
    public function constrain(
        DateTimeImmutable $interruptAt,
        DateTimeImmutable $resumeAt,
        string $reason,
        bool $projectionOnly = false,
    ): void {
        // The interruption must touch this item: start inside it, or begin
        // while the item is playing.
        if (
            $interruptAt > $this->projectedEndAt
            || $resumeAt < $interruptAt
            || $resumeAt <= $this->expectedPlayAt
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
        $this->reason = $reason;
        $this->projectionOnly = $projectionOnly;
    }

    public function isProjectionOnly(): bool
    {
        return $this->projectionOnly;
    }

    public function hasConstraint(): bool
    {
        return null !== $this->interruptAt && null !== $this->resumeAt;
    }

    public function getInterruptAt(): ?DateTimeImmutable
    {
        return $this->interruptAt;
    }

    public function getResumeAt(): ?DateTimeImmutable
    {
        return $this->resumeAt;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
