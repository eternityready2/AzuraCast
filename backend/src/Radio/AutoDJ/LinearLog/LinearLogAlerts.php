<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use App\Radio\AutoDJ\Scheduler;
use Carbon\CarbonImmutable;

/**
 * What an operator has to be told about the log before it reaches the air: a
 * hole, an hour running short of its Station ID, and songs the AutoDJ had to
 * pick itself because the log had nothing for that time.
 *
 * Holes and short hours are read from LinearLogCoverage, the same measurement
 * the page's depth figure and the repair pass use, so an alert never disagrees
 * with them.
 */
final class LinearLogAlerts
{
    use EntityManagerAwareTrait;

    public const string TYPE_HOLE = 'hole';
    public const string TYPE_SHORT_HOUR = 'short_hour';
    public const string TYPE_AUTODJ = 'autodj';

    /** How far ahead a problem is worth an alert: close enough to act on, and to be real. */
    private const int LOOKAHEAD_SECONDS = 3 * 3600;

    /** Under this, a gap between two lines is rounding in the projection, not a hole. */
    private const int MIN_HOLE_SECONDS = 30;

    /** Under this, the end of an hour is the Top-of-Hour swap's to fit (LinearLogRefill tops up from here). */
    private const int MIN_SHORT_HOUR_SECONDS = 120;

    /** How far back a song the AutoDJ picked itself is still reported. */
    private const int AUTODJ_LOOKBACK_SECONDS = 3600;

    /** How far ahead the page lists gaps; past LOOKAHEAD_SECONDS they are only projected. */
    private const int GAP_LIST_SECONDS = 24 * 3600;

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
    }

    /**
     * @return list<array{type: string, level: string, at: int, seconds: int, message: string}>
     */
    public function forStation(Station $station, ?LinearLogCoverage $coverage): array
    {
        $alerts = [];
        $now = time();
        $tz = $station->getTimezoneObject();
        $time = static fn(int $at): string => CarbonImmutable::createFromTimestamp($at, $tz)->format('g:i:s A');

        foreach ($this->gaps($station, $coverage, $now + self::LOOKAHEAD_SECONDS) as $gap) {
            $alerts[] = self::TYPE_SHORT_HOUR === $gap['type']
                ? [
                    'type' => self::TYPE_SHORT_HOUR,
                    'level' => 'warning',
                    'at' => $gap['start'],
                    'seconds' => $gap['seconds'],
                    'message' => sprintf(
                        'The %s hour is running short: the log ends at %s, %s before the Station ID.',
                        CarbonImmutable::createFromTimestamp($gap['start'], $tz)->format('g A'),
                        $time($gap['start']),
                        self::span($gap['seconds'])
                    ),
                ]
                : [
                    'type' => self::TYPE_HOLE,
                    'level' => 'danger',
                    'at' => $gap['start'],
                    'seconds' => $gap['seconds'],
                    'message' => sprintf(
                        'Hole in the log at %s: nothing is planned for %s.',
                        $time($gap['start']),
                        self::span($gap['seconds'])
                    ),
                ];
        }

        $picked = $this->pickedByAutoDj($station, $now);
        if ([] !== $picked) {
            $first = $picked[0];
            $alerts[] = [
                'type' => self::TYPE_AUTODJ,
                'level' => 'warning',
                'at' => (int)$first['at'],
                'seconds' => (int)array_sum(array_column($picked, 'duration')),
                'message' => sprintf(
                    1 === count($picked)
                        ? 'AutoDJ had to step in: it picked %d song itself in the last hour, where the log had nothing (%s at %s).'
                        : 'AutoDJ had to step in: it picked %d songs itself in the last hour, where the log had nothing (first: %s at %s).',
                    count($picked),
                    (string)$first['text'],
                    $time((int)$first['at'])
                ),
            ];
        }

        return $alerts;
    }

    /**
     * Every gap in the next 24 hours, for the page's gap list and its GAP rows:
     * where it is, how long, and the scheduled block it sits in. The same holes
     * and short hours the alerts report, so the list never disagrees with them;
     * one further out than an alert reaches is marked projected, because the
     * log is still topped up and re-timed before that hour airs.
     *
     * @return list<array{type: string, start: int, end: int, seconds: int, show: string|null, projected: bool}>
     */
    public function gapsAhead(Station $station, ?LinearLogCoverage $coverage): array
    {
        $now = time();
        $tz = $station->getTimezoneObject();

        $spans = $coverage->spans ?? [];
        $planEnds = [] === $spans ? $now : $spans[array_key_last($spans)]['end'];

        $gaps = [];
        foreach ($this->gaps($station, $coverage, $now + self::GAP_LIST_SECONDS) as $gap) {
            $projected = $gap['start'] > $now + self::LOOKAHEAD_SECONDS;

            // Past the alerts' reach, the open end of the log is where the
            // plan stops for now, not a gap in it.
            if ($projected && $gap['start'] >= $planEnds) {
                continue;
            }

            $gaps[] = [
                ...$gap,
                'show' => $this->openBlockName(
                    $station,
                    CarbonImmutable::createFromTimestamp($gap['start'], $tz)
                ),
                'projected' => $projected,
            ];
        }

        return $gaps;
    }

    /**
     * Holes worth telling an operator about, up to $until.
     *
     * @return list<array{type: string, start: int, end: int, seconds: int}>
     */
    private function gaps(Station $station, ?LinearLogCoverage $coverage, int $until): array
    {
        $tz = $station->getTimezoneObject();

        $gaps = [];
        foreach ($coverage->holes ?? [] as $hole) {
            if ($hole['start'] > $until) {
                break;
            }

            // A hole that runs up to the Station ID is an hour running short;
            // anywhere else it is a hole in the middle of the log.
            $hourEnd = CarbonImmutable::createFromTimestamp($hole['start'], $tz)->startOfHour()->addHour()->getTimestamp();
            $runsToId = $hole['end'] >= $hourEnd - 1;
            $end = min($hole['end'], $hourEnd);
            $seconds = $end - $hole['start'];

            if ($seconds < ($runsToId ? self::MIN_SHORT_HOUR_SECONDS : self::MIN_HOLE_SECONDS)) {
                continue;
            }

            $gaps[] = [
                'type' => $runsToId ? self::TYPE_SHORT_HOUR : self::TYPE_HOLE,
                'start' => $hole['start'],
                'end' => $end,
                'seconds' => $seconds,
            ];
        }

        return $gaps;
    }

    /** Name of a scheduled playlist whose window is open at $at. */
    private function openBlockName(Station $station, CarbonImmutable $at): ?string
    {
        foreach ($station->playlists as $playlist) {
            if (
                $playlist->schedule_items->count() > 0
                && $playlist->is_enabled
                && $this->scheduler->isPlaylistScheduledToPlayNow($playlist, $at, excludeSpecialRules: true)
            ) {
                return $playlist->name;
            }
        }

        return null;
    }

    /**
     * Songs in the queue that no log line stands behind: the AutoDJ's own picks
     * for time the log left open. IDs, spots, AI DJ clips, streams and listener
     * requests are never log lines' business.
     *
     * @return list<array{at: int, text: string, duration: float}>
     */
    private function pickedByAutoDj(Station $station, int $now): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', sq.timestamp_played) AS played_at,
                    sq.text, sq.title, sq.duration
                FROM station_queue sq
                JOIN station_media sm ON sm.id = sq.media_id
                WHERE sq.station_id = ?
                AND sq.log_entry_id IS NULL
                AND sq.request_id IS NULL
                AND sq.autodj_custom_uri IS NULL
                AND sq.top_of_hour_legal_id = 0
                AND sq.clock_wheel_legal_id_substitute = 0
                AND sm.type = 'music'
                AND sq.timestamp_played >= ?
                AND (sq.is_played = 1 OR sq.sent_to_autodj = 1)
                ORDER BY sq.timestamp_played ASC
            SQL,
            // Queue times are stored in UTC.
            [$station->id, gmdate('Y-m-d H:i:s', $now - self::AUTODJ_LOOKBACK_SECONDS)]
        );

        return array_map(
            static fn(array $row): array => [
                'at' => (int)$row['played_at'],
                'text' => (string)($row['text'] ?? $row['title'] ?? 'a song'),
                'duration' => (float)($row['duration'] ?? 0.0),
            ],
            $rows
        );
    }

    private static function span(int $seconds): string
    {
        return $seconds >= 60
            ? sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60)
            : sprintf('%d s', $seconds);
    }
}
