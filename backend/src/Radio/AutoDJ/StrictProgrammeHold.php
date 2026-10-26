<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\RevalidateQueuedSong;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A show's first item projected just before its window opens starts when the
 * window opens, and a queued row due while a strict programme holds the air
 * starts when the programme is done, the way the Top-of-Hour listener moves a
 * row past the ID and news. The queue, Playing Next, the Linear Log and the schedule guard all
 * read that one air time (see StrictProgrammeClock for why it was wrong).
 *
 * At the hand-off to Liquidsoap such a row also waits: loaded before the show
 * starts, it would be thrown away when the strict lane takes the air.
 */
final class StrictProgrammeHold implements EventSubscriberInterface
{
    public function __construct(
        private readonly StrictProgrammeClock $clock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the Top-of-Hour listener (-1), which may move the row past the
        // ID and news first; before ScheduleWindowGuard (-10) and
        // QueuedRepeatGuard (-11), which judge the row where it really airs.
        return [
            RevalidateQueuedSong::class => ['onRevalidateQueuedSong', -5],
        ];
    }

    public function onRevalidateQueuedSong(RevalidateQueuedSong $event): void
    {
        $row = $event->getQueueRow();
        if ($row->sent_to_autodj || $row->is_played) {
            return;
        }

        if (null !== $row->playlist && $this->clock->isPlayedByStrictLane($row->playlist)) {
            return;
        }

        $airsAt = $event->getOpensAfter() ?? $event->getExpectedPlayAt();
        if ($airsAt < $event->getExpectedPlayAt()) {
            $airsAt = $event->getExpectedPlayAt();
        }

        // Moved past the boundary its cap was cut for (here or by the
        // Top-of-Hour hold): it now starts after that boundary, so the cap no
        // longer applies.
        self::releaseStaleBoundaryCap($row, $event->getExpectedPlayAt(), $airsAt);

        // A show's first item projected just before its window opens airs
        // when the window opens (after the ID and news on the hour). Only for
        // the far-ahead projection: at the hand-off the start time is exact,
        // and an item that would really start before its window is the
        // guard's to refuse (the Top-of-Hour hold covers the ID itself).
        if (null !== $row->playlist && !$event->isHandOff()) {
            $opensAt = $this->clock->programmeOpensAt($event->getStation(), $row->playlist, $airsAt);
            if (null !== $opensAt) {
                $event->opensAfter($opensAt->toDateTimeImmutable());
                $airsAt = $opensAt->toDateTimeImmutable();
                self::releaseStaleBoundaryCap($row, $event->getExpectedPlayAt(), $airsAt);
            }
        }

        $hold = $this->clock->holdAt($event->getStation(), $airsAt);
        if (null === $hold) {
            return;
        }

        $event->opensAfter($hold['end']->toDateTimeImmutable());
        self::releaseStaleBoundaryCap($row, $event->getExpectedPlayAt(), $hold['end']->toDateTimeImmutable());

        if ($event->isHandOff() && $hold['start']->greaterThan(CarbonImmutable::now('UTC'))) {
            $event->holdBack();
        }
    }

    /**
     * A row moved past the boundary airs after it, so a cap fitting it into
     * the seconds before that boundary no longer applies. Left on, Altered
     * Stories (28 minutes), projected at 18:59:54, kept a 5-second cap after
     * it was moved to 19:00:37: every song behind it was timed inside the show
     * and pulled (Wed 2026-10-07). Same restore as
     * Queue::applyBroadcastClockCapToQueuedRow().
     */
    private static function releaseStaleBoundaryCap(
        StationQueue $row,
        DateTimeImmutable $projectedAt,
        DateTimeImmutable $airsAt,
    ): void {
        $media = $row->media;
        if (
            !$row->hour_boundary_enforce_cap
            || null === $row->hour_boundary_max_play_seconds
            || !$media instanceof StationMedia
        ) {
            return;
        }

        // The cap ends the row at the boundary it was projected before.
        $cappedEnd = $projectedAt->getTimestamp() + $row->hour_boundary_max_play_seconds;
        if ($airsAt->getTimestamp() < $cappedEnd) {
            return;
        }

        $naturalDuration = $media->getCalculatedLength();
        if (
            $row->clock_wheel_enforce_cap
            && null !== $row->clock_wheel_max_play_seconds
            && $row->clock_wheel_max_play_seconds > 0
        ) {
            $row->duration = min($naturalDuration, (float)$row->clock_wheel_max_play_seconds);
        } elseif (null !== $row->clock_wheel_stretch_ratio && $row->clock_wheel_stretch_ratio > 0.0) {
            $row->duration = $naturalDuration / $row->clock_wheel_stretch_ratio;
        } else {
            $row->duration = $naturalDuration;
        }

        $row->hour_boundary_enforce_cap = false;
        $row->hour_boundary_max_play_seconds = null;
    }
}
