<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Radio\AutoDJ\ClockWheel\HourTimeline;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeZone;
use Mockery;
use Monolog\Logger;

class HourTimelineTest extends Unit
{
    private HourTimeline $timeline;

    private Station $station;

    protected function _before(): void
    {
        $this->timeline = new HourTimeline();
        $this->timeline->setLogger(new Logger('test'));

        /** @var Station $station */
        $station = Mockery::mock(Station::class);
        $station->shouldReceive('getTimezoneObject')->andReturn(new DateTimeZone('UTC'));
        $this->station = $station;
    }

    public function testEmptyWheelReturnsNull(): void
    {
        $wheel = new StationClockWheel($this->station);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour());

        self::assertNull($plan);
    }

    public function testPicksFirstSlotAtTopOfHour(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0],
            ['position' => 1800, 'order' => 1],
        ]);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour());

        self::assertNotNull($plan);
        self::assertSame(0,    $plan->slot->position_seconds);
        self::assertSame(0,    $plan->currentT);
        self::assertSame(1800, $plan->availableSeconds);
    }

    public function testPicksUpcomingSlotWhenTimeIsBeforeIt(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0],
            ['position' => 1800, 'order' => 1],
            ['position' => 2700, 'order' => 2],
        ]);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour()->addMinutes(15));

        self::assertNotNull($plan);
        self::assertSame(1800, $plan->slot->position_seconds);
        self::assertSame(900,  $plan->currentT);
        self::assertSame(900,  $plan->availableSeconds);
    }

    public function testPicksSlotWhenTimeMatchesPositionExactly(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0],
            ['position' => 1800, 'order' => 1],
        ]);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour()->addSeconds(1800));

        self::assertNotNull($plan);
        self::assertSame(1800, $plan->slot->position_seconds);
        self::assertSame(1800, $plan->availableSeconds);
    }

    public function testReturnsNullWhenTimePastFinalAnchor(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0],
            ['position' => 1800, 'order' => 1],
        ]);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour()->addSeconds(3000));

        self::assertNull($plan);
    }

    public function testAvailableSecondsCapsAt3600(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0, 'order' => 0],
        ]);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour());

        self::assertNotNull($plan);
        self::assertSame(3600, $plan->availableSeconds);
    }

    public function testSlotOrderTiebreakerWhenSamePosition(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 1800, 'order' => 2],
            ['position' => 1800, 'order' => 0],
            ['position' => 1800, 'order' => 1],
        ]);

        $sorted = $this->timeline->slotsByPosition($wheel);

        self::assertCount(3, $sorted);
        self::assertSame(0, $sorted[0]->slot_order);
        self::assertSame(1, $sorted[1]->slot_order);
        self::assertSame(2, $sorted[2]->slot_order);
    }

    public function testSlotsByPositionSortsAcrossDifferentPositions(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 2700, 'order' => 0],
            ['position' => 0,    'order' => 0],
            ['position' => 1800, 'order' => 0],
        ]);

        $sorted = $this->timeline->slotsByPosition($wheel);

        self::assertSame([0, 1800, 2700], array_map(
            static fn(StationClockWheelSlot $s): int => $s->position_seconds,
            $sorted
        ));
    }

    public function testAdvancesToNextSlotWhenCurrentJustEnded(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0],
            ['position' => 600,  'order' => 1],
            ['position' => 1200, 'order' => 2],
        ]);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour()->addSeconds(601));

        self::assertNotNull($plan);
        self::assertSame(1200, $plan->slot->position_seconds);
        self::assertSame(601,  $plan->currentT);
        self::assertSame(599,  $plan->availableSeconds);
    }

    public function testHonoursStationTimezoneWhenComputingHourOffset(): void
    {
        /** @var Station $tzStation */
        $tzStation = Mockery::mock(Station::class);
        $tzStation->shouldReceive('getTimezoneObject')->andReturn(new DateTimeZone('Africa/Johannesburg'));

        $wheel = new StationClockWheel($tzStation);
        $wheel->slots->add($this->buildSlot($wheel, 0, 0));
        $wheel->slots->add($this->buildSlot($wheel, 1800, 1));

        $playAtUtc = CarbonImmutable::create(2026, 5, 23, 17, 30, 0, new DateTimeZone('UTC'));

        $plan = $this->timeline->planNext($wheel, $playAtUtc);

        self::assertNotNull($plan);
        self::assertSame(1800, $plan->currentT);
        self::assertSame(1800, $plan->slot->position_seconds);
    }

    /**
     * @param array<int, array{position:int, order:int}> $entries
     */
    private function buildWheel(array $entries): StationClockWheel
    {
        $wheel = new StationClockWheel($this->station);
        foreach ($entries as $entry) {
            $wheel->slots->add($this->buildSlot($wheel, $entry['position'], $entry['order']));
        }
        return $wheel;
    }

    private function buildSlot(StationClockWheel $wheel, int $position, int $order): StationClockWheelSlot
    {
        $slot = new StationClockWheelSlot($wheel);
        $slot->position_seconds = $position;
        $slot->slot_order       = $order;
        return $slot;
    }

    private function topOfHour(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 5, 23, 19, 0, 0, new DateTimeZone('UTC'));
    }
}
