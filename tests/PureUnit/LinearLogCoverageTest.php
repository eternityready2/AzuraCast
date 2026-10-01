<?php

declare(strict_types=1);

namespace PureUnit;

use App\Radio\AutoDJ\LinearLog\LinearLogCoverage;
use PHPUnit\Framework\TestCase;

final class LinearLogCoverageTest extends TestCase
{
    private const int DAY = 86400;
    private const int T0 = 1_790_000_000;

    /** @param list<array{int, int}> $lines */
    private static function measure(array $lines, int $seconds = self::DAY): LinearLogCoverage
    {
        return LinearLogCoverage::measure(
            array_map(static fn(array $l): array => ['start' => $l[0], 'end' => $l[1]], $lines),
            self::T0,
            self::T0 + $seconds,
        );
    }

    public function testAnUnbrokenDayIsADayDeep(): void
    {
        $coverage = self::measure([[self::T0, self::T0 + self::DAY]]);

        self::assertSame(self::DAY, $coverage->continuousSeconds());
        self::assertSame(self::DAY, $coverage->coveredSeconds);
        self::assertSame([], $coverage->holes);
        self::assertTrue($coverage->satisfies(self::DAY));
        self::assertNull($coverage->repairFrom(self::DAY));
    }

    public function testBackToBackLinesLeaveNoHole(): void
    {
        $lines = [];
        for ($at = self::T0; $at < self::T0 + self::DAY; $at += 300) {
            $lines[] = [$at, $at + 300];
        }

        self::assertSame(self::DAY, self::measure($lines)->continuousSeconds());
    }

    /**
     * The defect this class exists to stop: plenty of audio, broken air. A log
     * holding well over a day of lines is only two hours deep when there is a
     * hole at +2h, and it must not report otherwise.
     */
    public function testContentPastAHoleDoesNotCountTowardDepth(): void
    {
        $coverage = self::measure([
            [self::T0, self::T0 + 7200],
            [self::T0 + 7500, self::T0 + self::DAY + 21600],
        ]);

        self::assertSame(7200, $coverage->continuousSeconds());
        self::assertFalse($coverage->satisfies(self::DAY));
        self::assertSame(self::T0 + 7200, $coverage->repairFrom(self::DAY));
        self::assertCount(1, $coverage->holes);
        self::assertSame(300, $coverage->holes[0]['duration']);
    }

    /**
     * Overlapping lines must not inflate the measurement. Summing durations
     * reported a full day here; the air is only half a day long.
     */
    public function testOverlapIsCountedOnce(): void
    {
        $coverage = self::measure([
            [self::T0, self::T0 + self::DAY / 2],
            [self::T0, self::T0 + self::DAY / 2],
        ]);

        self::assertSame(self::DAY / 2, $coverage->coveredSeconds);
        self::assertSame(self::DAY / 2, $coverage->continuousSeconds());
        self::assertFalse($coverage->satisfies(self::DAY));
    }

    public function testOutOfOrderLinesAreMeasuredInTimeOrder(): void
    {
        $coverage = self::measure([
            [self::T0 + 600, self::T0 + self::DAY],
            [self::T0, self::T0 + 600],
        ]);

        self::assertSame(self::DAY, $coverage->continuousSeconds());
        self::assertSame([], $coverage->holes);
    }

    public function testLinesStraddlingTheWindowAreClipped(): void
    {
        $coverage = self::measure([[self::T0 - 99999, self::T0 + self::DAY + 99999]]);

        self::assertSame(self::DAY, $coverage->coveredSeconds);
        self::assertSame(self::DAY, $coverage->continuousSeconds());
    }

    public function testAShortLogIsRepairedFromItsEndNotFromZero(): void
    {
        $coverage = self::measure([[self::T0, self::T0 + 72000]]);

        self::assertSame(72000, $coverage->continuousSeconds());
        self::assertSame(self::T0 + 72000, $coverage->repairFrom(self::DAY));
        self::assertCount(1, $coverage->holes, 'the unfilled tail is itself a hole');
        self::assertSame(self::DAY - 72000, $coverage->holes[0]['duration']);
    }

    public function testAnEmptyLogIsZeroDeepAndRepairsFromTheStart(): void
    {
        $coverage = self::measure([]);

        self::assertSame(0, $coverage->continuousSeconds());
        self::assertSame(0, $coverage->coveredSeconds);
        self::assertSame(self::T0, $coverage->repairFrom(self::DAY));
    }

    public function testZeroLengthLinesCannotMaskAHole(): void
    {
        $coverage = self::measure([
            [self::T0, self::T0 + 3600],
            [self::T0 + 5000, self::T0 + 5000],
            [self::T0 + 7200, self::T0 + self::DAY],
        ]);

        self::assertSame(3600, $coverage->continuousSeconds());
        self::assertSame(3600, $coverage->holes[0]['duration']);
    }

    public function testTouchingLinesAreContinuous(): void
    {
        $coverage = self::measure([
            [self::T0, self::T0 + 43200],
            [self::T0 + 43200, self::T0 + self::DAY],
        ]);

        self::assertSame([], $coverage->holes);
        self::assertSame(self::DAY, $coverage->continuousSeconds());
    }

    public function testAnInvertedWindowMeasuresNothingRatherThanThrowing(): void
    {
        $coverage = LinearLogCoverage::measure([], self::T0, self::T0 - 1);

        self::assertSame(0, $coverage->continuousSeconds());
        self::assertSame([], $coverage->holes);
    }
}
