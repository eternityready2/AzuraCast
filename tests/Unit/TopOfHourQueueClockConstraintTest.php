<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\ResolveQueueClockConstraint;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\AutoDJ\TopOfHour\TopOfHourMode;
use App\Radio\AutoDJ\TopOfHour\TopOfHourPlan;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use Plugin\TopOfHour\TopOfHourQueueClockConstraint;
use ReflectionClass;

require_once dirname(__DIR__, 2) . '/plugins/top_of_hour/src/TopOfHourQueueClockConstraint.php';

final class TopOfHourQueueClockConstraintTest extends Unit
{
    public function testOpenHourCutsCurrentProjectionAndResumesAfterIdOccupancy(): void
    {
        $station = $this->makeStation();
        $start = CarbonImmutable::parse('2026-09-07 22:56:40', 'UTC');
        $naturalEnd = $start->addSeconds(275); // 23:01:15
        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            $naturalEnd->toDateTimeImmutable(),
        );

        TopOfHourQueueClockConstraint::applyPlan(
            $event,
            $this->makePlan(TopOfHourMode::SoftEtm),
        );

        self::assertTrue($event->hasConstraint());
        self::assertFalse($event->shouldPersistDurationCap());
        self::assertSame(
            '2026-09-07 22:59:21.000000',
            $event->getInterruptAt()?->format('Y-m-d H:i:s.u'),
        );
        self::assertSame(
            '2026-09-07 22:59:58.825000',
            $event->getResumeAt()?->format('Y-m-d H:i:s.u'),
        );
        self::assertSame('top_of_hour_station_id', $event->getReason());
    }

    public function testHardHourKeepsCurrentProjectionOutUntilExactBoundary(): void
    {
        $station = $this->makeStation();
        $start = CarbonImmutable::parse('2026-09-07 22:56:40', 'UTC');
        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            $start->addSeconds(275)->toDateTimeImmutable(),
        );

        TopOfHourQueueClockConstraint::applyPlan(
            $event,
            $this->makePlan(TopOfHourMode::HardToh),
        );

        self::assertTrue($event->hasConstraint());
        self::assertFalse($event->shouldPersistDurationCap());
        self::assertSame(
            '2026-09-07 22:59:21.000000',
            $event->getInterruptAt()?->format('Y-m-d H:i:s.u'),
        );
        self::assertSame(
            '2026-09-07 23:00:00.000000',
            $event->getResumeAt()?->format('Y-m-d H:i:s.u'),
        );
    }

    public function testFutureRowCrossingIdReservesIntervalWithoutPersistentDurationCap(): void
    {
        $station = $this->makeStation();
        $start = CarbonImmutable::parse('2026-09-07 22:58:40', 'UTC');
        /** @var StationQueue $futureRow */
        $futureRow = (new ReflectionClass(StationQueue::class))->newInstanceWithoutConstructor();

        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            $start->addSeconds(180)->toDateTimeImmutable(),
            $futureRow,
        );

        TopOfHourQueueClockConstraint::applyPlan(
            $event,
            $this->makePlan(TopOfHourMode::SoftEtm),
        );

        self::assertTrue($event->hasConstraint());
        self::assertFalse($event->shouldPersistDurationCap());
        self::assertSame(
            '2026-09-07 22:59:21.000000',
            $event->getInterruptAt()?->format('Y-m-d H:i:s.u'),
        );
        self::assertSame(
            '2026-09-07 22:59:58.825000',
            $event->getResumeAt()?->format('Y-m-d H:i:s.u'),
        );
    }

    public function testStaleFutureRowInsideIdWindowIsDeferredInsteadOfCrammed(): void
    {
        $station = $this->makeStation();
        $start = CarbonImmutable::parse('2026-09-07 22:59:55', 'UTC');
        /** @var StationQueue $futureRow */
        $futureRow = (new ReflectionClass(StationQueue::class))->newInstanceWithoutConstructor();

        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            $start->addSeconds(240)->toDateTimeImmutable(),
            $futureRow,
        );

        TopOfHourQueueClockConstraint::applyPlan(
            $event,
            $this->makePlan(TopOfHourMode::SoftEtm),
        );

        self::assertFalse($event->hasConstraint());
        self::assertTrue($event->hasDeferral());
        self::assertSame(
            '2026-09-07 22:59:58.825000',
            $event->getDeferUntil()?->format('Y-m-d H:i:s.u'),
        );
        self::assertFalse($event->shouldPersistDurationCap());
    }

    public function testHardStaleRowInsideHoldWindowDefersToExactBoundary(): void
    {
        $station = $this->makeStation();
        $start = CarbonImmutable::parse('2026-09-07 22:59:59', 'UTC');
        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            $start->addSeconds(180)->toDateTimeImmutable(),
        );

        TopOfHourQueueClockConstraint::applyPlan(
            $event,
            $this->makePlan(TopOfHourMode::HardToh),
        );

        self::assertTrue($event->hasDeferral());
        self::assertSame(
            '2026-09-07 23:00:00.000000',
            $event->getDeferUntil()?->format('Y-m-d H:i:s.u'),
        );
    }

    public function testPlanDoesNotTouchSongThatEndsBeforeTopOfHourTarget(): void
    {
        $station = $this->makeStation();
        $start = CarbonImmutable::parse('2026-09-07 22:56:40', 'UTC');
        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            CarbonImmutable::parse('2026-09-07 22:58:50', 'UTC')->toDateTimeImmutable(),
        );

        TopOfHourQueueClockConstraint::applyPlan(
            $event,
            $this->makePlan(TopOfHourMode::SoftEtm),
        );

        self::assertFalse($event->hasConstraint());
        self::assertFalse($event->hasDeferral());
    }

    public function testSelectionBoundaryCanBePublishedWithoutChangingProjection(): void
    {
        $station = $this->makeStation();
        $start = CarbonImmutable::parse('2026-09-07 22:55:13', 'UTC');
        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            $start->addHour()->toDateTimeImmutable(),
        );

        $event->suggestSelectionBoundary(
            CarbonImmutable::parse('2026-09-07 22:59:21', 'UTC')->toDateTimeImmutable(),
            'top_of_hour_station_id',
        );

        self::assertTrue($event->hasSelectionBoundary());
        self::assertSame(
            '2026-09-07 22:59:21',
            $event->getSelectionBoundaryAt()?->format('Y-m-d H:i:s'),
        );
        self::assertSame('top_of_hour_station_id', $event->getSelectionBoundaryReason());
        self::assertFalse($event->hasDeferral());
    }

    public function testQueueBuilderHasExplicitTwentyOneSecondFullSongCramGuard(): void
    {
        $queueBuilderSource = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php',
        );

        self::assertIsString($queueBuilderSource);
        self::assertStringContainsString(
            'private const float MIN_PROTECTED_BRIDGE_AIR_FRACTION = 0.80;',
            $queueBuilderSource,
        );
        self::assertStringContainsString(
            '$airedFraction = min(1.0, $availableSeconds / $duration);',
            $queueBuilderSource,
        );
        self::assertStringContainsString(
            '$airedFraction >= self::MIN_PROTECTED_BRIDGE_AIR_FRACTION;',
            $queueBuilderSource,
        );
        self::assertStringContainsString(
            'refusing to cram a full song into the remaining pre-boundary runway.',
            $queueBuilderSource,
        );

        // The screenshot case has only 21 seconds before the ID. A normal
        // three-minute song would air less than 12% of its length, far below the
        // 80% bridge floor, so it cannot be selected as a deliberate bridge.
        self::assertLessThan(0.80, 21.0 / 180.0);
    }

    public function testDisabledTopOfHourLeavesOrdinaryTimelineUntouched(): void
    {
        $station = $this->makeStation();
        $station->backend_config->top_of_hour_id_enabled = false;

        $start = CarbonImmutable::parse('2026-09-07 22:56:40', 'UTC');
        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            $start->addSeconds(275)->toDateTimeImmutable(),
        );

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();
        (new TopOfHourQueueClockConstraint($clock))->resolve($event);

        self::assertFalse($event->hasConstraint());
        self::assertFalse($event->hasDeferral());
        self::assertNull($event->getInterruptAt());
        self::assertNull($event->getResumeAt());
        self::assertNull($event->getDeferUntil());
    }

    private function makeStation(): Station
    {
        $station = new Station();
        $station->name = 'TOH Queue Constraint Test';
        $station->short_name = 'toh_queue_constraint_test';
        $station->timezone = 'UTC';

        return $station;
    }

    private function makePlan(TopOfHourMode $mode): TopOfHourPlan
    {
        /** @var StationMedia $media */
        $media = (new ReflectionClass(StationMedia::class))->newInstanceWithoutConstructor();

        return new TopOfHourPlan(
            mode: $mode,
            boundaryAt: CarbonImmutable::parse('2026-09-07 23:00:00', 'UTC')->toDateTimeImmutable(),
            targetStartAt: CarbonImmutable::parse('2026-09-07 22:59:21', 'UTC')->toDateTimeImmutable(),
            media: $media,
            durationSeconds: 37.825,
        );
    }
}
