<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\ResolveQueueClockConstraint;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\AutoDJ\TopOfHour\TopOfHourPlan;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes the near-term ordinary AutoDJ projection consume the same station clock
 * event that the plugin enforces on air without rewriting ordinary queued-media
 * durations or AutoCue cue-out values.
 *
 * The authoritative runtime cut is performed by Liquidsoap. The song actually
 * on air is capped at the TOH target. Future rows are never capped (a stale
 * forecast must not manufacture 2- or 3-second music rows around :59), but the
 * projection still moves past the ID's airtime for them, or every later hour
 * of the Linear Log ran ~37s ahead of the air.
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

        $start = CarbonImmutable::instance($event->getExpectedPlayAt());
        $projectedEnd = CarbonImmutable::instance($event->getProjectedEndAt());

        // Look the ID up from just before this item, so an item projected to
        // start while the ID is still on air (:59:59 to ~:00:38) is matched to
        // that ID rather than to the next hour's.
        $lookupFrom = $start->subSeconds(TopOfHourClock::MAX_ID_MAX_SECONDS);

        $boundary = CarbonImmutable::instance($this->clock->getNextBoundary($station, $lookupFrom));
        $candidateTarget = $boundary
            ->subMinute()
            ->startOfMinute()
            ->addSeconds($this->clock->getIdStartSecond($station));

        if ($candidateTarget > $projectedEnd) {
            return;
        }

        $plan = $this->clock->plan($station, $lookupFrom->toDateTimeImmutable());
        if (!$plan instanceof TopOfHourPlan) {
            return;
        }

        if ($this->clock->clockWheelOwnsBoundary($station, $plan->boundaryAt)) {
            return;
        }

        // Future rows only move the projection across the ID. Capping them
        // persisted a hard duration into the row that outlived later timing
        // recalculations and collapsed Upcoming Programming into tiny slots; the
        // Liquidsoap runtime performs the real cut for whatever is on air.
        self::applyPlan($event, $plan, null !== $event->getQueueRow());
    }

    /**
     * Apply an already-resolved TOH plan to one projected item.
     */
    public static function applyPlan(
        ResolveQueueClockConstraint $event,
        TopOfHourPlan $plan,
        bool $projectionOnly = false,
    ): void {
        $start = CarbonImmutable::instance($event->getExpectedPlayAt());
        $projectedEnd = CarbonImmutable::instance($event->getProjectedEndAt());
        $target = CarbonImmutable::instance($plan->targetStartAt);

        // The ID plays in full in every hour. At a HARD boundary the programme
        // that owns :00 starts at :00 or when the ID lane releases, whichever is
        // later (rigid_schedule_toh_lane_owns_air): on 2026-09-28 the ID ran
        // 10:59:59-11:00:36 and the 11:00 programme started at 11:00:36.
        $resumeAt = $target->addMilliseconds(
            (int)round($plan->durationSeconds * 1000)
        );
        if ($plan->isHard()) {
            $resumeAt = $resumeAt->max(CarbonImmutable::instance($plan->boundaryAt));
        }

        if ($target > $projectedEnd || $resumeAt <= $start) {
            return;
        }

        $event->constrain(
            $target->toDateTimeImmutable(),
            $resumeAt->toDateTimeImmutable(),
            'top_of_hour_station_id',
            $projectionOnly,
        );
    }
}
