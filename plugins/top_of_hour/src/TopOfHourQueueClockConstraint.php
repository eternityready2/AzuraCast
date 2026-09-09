<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\ResolveQueueClockConstraint;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\AutoDJ\TopOfHour\TopOfHourPlan;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects the plugin-owned legal-ID interval into the ordinary AutoDJ timeline
 * without rewriting media duration or AutoCue cue-out values.
 *
 * Runtime Liquidsoap remains the final frame-accurate authority. Queue planning
 * only reserves the wall-clock interval so Upcoming Programming cannot cram
 * multiple ordinary songs into seconds that are actually owned by the ID/hard
 * boundary. A future song crossing the target advances the next-play cursor to
 * the end of the reserved interval, while a stale row already inside the window
 * is deferred to that same resume instant.
 */
final class TopOfHourQueueClockConstraint implements EventSubscriberInterface
{
    public function __construct(
        private readonly TopOfHourClock $clock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ResolveQueueClockConstraint::class => 'resolve',
        ];
    }

    public function resolve(ResolveQueueClockConstraint $event): void
    {
        $station = $event->getStation();
        if (!$this->clock->isEnabled($station)) {
            return;
        }

        $plan = $this->clock->plan($station, $event->getExpectedPlayAt());
        if (!$plan instanceof TopOfHourPlan) {
            return;
        }

        if ($this->clock->clockWheelOwnsBoundary($station, $plan->boundaryAt)) {
            return;
        }

        self::applyPlan($event, $plan);
    }

    /**
     * Apply an already-resolved TOH plan to one current/future projection row.
     */
    public static function applyPlan(
        ResolveQueueClockConstraint $event,
        TopOfHourPlan $plan,
    ): void {
        $start = CarbonImmutable::instance($event->getExpectedPlayAt());
        $projectedEnd = CarbonImmutable::instance($event->getProjectedEndAt());
        $target = CarbonImmutable::instance($plan->targetStartAt);

        if ($plan->isHard()) {
            // A rigid programme owns :00. Ordinary AutoDJ projection resumes at
            // the boundary; the rigid runtime remains the actual on-air owner.
            $resumeAt = CarbonImmutable::instance($plan->boundaryAt);
        } else {
            // Open hour: reserve the ID's real file duration even though the ID
            // lives in the plugin-owned Liquidsoap lane rather than AutoDJ queue.
            $resumeAt = $target->addMilliseconds(
                (int)round($plan->durationSeconds * 1000)
            );
        }

        // Recover an already-built stale row whose projected start was inside
        // the legal-ID/hard-hold interval. Move the row; do not create a tiny
        // duration cap just to fill the remaining seconds of the reservation.
        if ($start >= $target && $start < $resumeAt) {
            $event->deferUntil(
                $resumeAt->toDateTimeImmutable(),
                'top_of_hour_station_id',
            );
            return;
        }

        if ($target <= $start || $target > $projectedEnd) {
            return;
        }

        // Runtime TOH performs the actual destructive cut. This constraint is
        // projection-only: never persist the cap into StationQueue::duration or
        // a media cue_out, because that was the source of the historical 1-5s
        // song-cramming failure around :59.
        $event->constrain(
            $target->toDateTimeImmutable(),
            $resumeAt->toDateTimeImmutable(),
            'top_of_hour_station_id',
            false,
        );
    }
}
