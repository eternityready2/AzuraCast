<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

/**
 * The single answer to "how deep is the log, and is it whole?".
 *
 * An FM log is not 24 hours deep because it contains 24 hours of audio. It is
 * 24 hours deep when it can run for 24 hours without hitting a hole. A log
 * holding 30 hours of lines with a hole at +2h is a two-hour log, and reporting
 * it as 30 is how dead air reaches the transmitter.
 *
 * Summing line durations cannot express that: overlapping or duplicated lines
 * inflate the total, and a hole subtracts from it exactly as a shorter song
 * would, so the number stays plausible while the air is broken. This measures
 * the timeline instead -- union of occupied time, clipped to the window -- so
 * the reporter, the page and the repair pass all read one set of numbers.
 */
final readonly class LinearLogCoverage
{
    /**
     * @param list<array{start: int, end: int}> $spans Merged, ordered, clipped.
     * @param list<array{start: int, end: int, duration: int}> $holes
     */
    private function __construct(
        public int $from,
        public int $until,
        public int $continuousUntil,
        public int $coveredSeconds,
        public array $spans,
        public array $holes,
    ) {
    }

    /**
     * @param iterable<array{start: int|float, end: int|float}> $lines Airable
     *     lines only: anything dropped, swapped out or otherwise not going to
     *     air must be left out by the caller, because this cannot tell a line
     *     that will play from one that merely exists.
     */
    public static function measure(iterable $lines, int $from, int $until): self
    {
        if ($until <= $from) {
            return new self($from, $from, $from, 0, [], []);
        }

        $clipped = [];
        foreach ($lines as $line) {
            $start = max((int)$line['start'], $from);
            $end = min((int)$line['end'], $until);
            if ($end > $start) {
                $clipped[] = [$start, $end];
            }
        }

        usort($clipped, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        /** @var list<array{start: int, end: int}> $spans */
        $spans = [];
        foreach ($clipped as [$start, $end]) {
            $last = array_key_last($spans);
            if (null !== $last && $start <= $spans[$last]['end']) {
                $spans[$last]['end'] = max($spans[$last]['end'], $end);
                continue;
            }
            $spans[] = ['start' => $start, 'end' => $end];
        }

        /** @var list<array{start: int, end: int, duration: int}> $holes */
        $holes = [];
        $cursor = $from;
        foreach ($spans as $span) {
            if ($span['start'] > $cursor) {
                $holes[] = ['start' => $cursor, 'end' => $span['start'], 'duration' => $span['start'] - $cursor];
            }
            $cursor = max($cursor, $span['end']);
        }
        if ($cursor < $until) {
            $holes[] = ['start' => $cursor, 'end' => $until, 'duration' => $until - $cursor];
        }

        $covered = 0;
        foreach ($spans as $span) {
            $covered += $span['end'] - $span['start'];
        }

        return new self(
            $from,
            $until,
            [] === $holes ? $until : $holes[0]['start'],
            $covered,
            $spans,
            $holes,
        );
    }

    /** How long the log can run from $from before it hits a hole. */
    public function continuousSeconds(): int
    {
        return $this->continuousUntil - $this->from;
    }

    /** True when the log can run unbroken for the horizon it promises. */
    public function satisfies(int $requiredSeconds): bool
    {
        return $this->continuousSeconds() >= $requiredSeconds;
    }

    /**
     * Where a repair has to start: the first hole, or the end of the timeline
     * when the log is whole but too short. Null when nothing needs doing.
     */
    public function repairFrom(int $requiredSeconds): ?int
    {
        return $this->satisfies($requiredSeconds) ? null : $this->continuousUntil;
    }
}
