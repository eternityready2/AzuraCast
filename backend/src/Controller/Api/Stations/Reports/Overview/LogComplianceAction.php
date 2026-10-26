<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports\Overview;

use App\Entity\Api\Status;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use Carbon\CarbonImmutable;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * Linear Log compliance: did the log air as planned, and did each hour land
 * cleanly on its Top-of-Hour ID? Built from the saved log (planned vs as-run)
 * and song history (what Liquidsoap reported airing). Read-only.
 */
#[OA\Get(
    path: '/station/{station_id}/reports/overview/log-compliance',
    operationId: 'getStationReportLogCompliance',
    summary: 'Linear Log planned-vs-aired compliance, hour landing and as-run gaps.',
    tags: [OpenApi::TAG_STATIONS_REPORTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class LogComplianceAction extends AbstractReportAction
{
    /** A silence between two as-run items longer than this is listed. */
    private const int GAP_SECONDS = 10;

    /** The last item before the ID ending more than this early is a miss. */
    private const int LANDING_TOLERANCE_SECONDS = 10;

    /**
     * Cut by the ID only past this: a file's length includes trailing silence
     * that AutoCue trims (up to ~17s on this library), so a short "cut" is not.
     */
    private const int CUT_TOLERANCE_SECONDS = 20;

    private const int MAX_LISTED = 50;

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        if (!$this->isAnalyticsEnabled()) {
            return $response->withStatus(400)
                ->withJson(new Status(false, 'Reporting is restricted due to system analytics level.'));
        }

        $station = $request->getStation();
        $tz = $station->getTimezoneObject();
        $dateRange = $this->getDateRange($request, $tz);
        $start = $dateRange->start->getTimestamp();
        $end = min($dateRange->end->getTimestamp(), time());
        $conn = $this->em->getConnection();

        // ---- Planned vs aired -------------------------------------------------
        $lines = $conn->fetchAllAssociative(
            'SELECT planned_at, status, note FROM station_log_entries
            WHERE station_id = ? AND planned_at >= ? AND planned_at < ?
            AND status IN (?, ?, ?, ?)',
            [$station->id, $start, $end, 'aired', 'swapped', 'replaced', 'dropped']
        );

        $totals = ['as_planned' => 0, 'swapped' => 0, 'replaced' => 0, 'dropped' => 0, 'live_picks' => 0];
        $days = [];
        $reasons = [];
        foreach ($lines as $line) {
            $note = (string)($line['note'] ?? '');
            $isLive = str_starts_with($note, 'Live:');
            $outcome = match (true) {
                $isLive => 'live_picks',
                'aired' === $line['status'] => 'as_planned',
                default => (string)$line['status'],
            };

            $totals[$outcome]++;

            $day = CarbonImmutable::createFromTimestamp((int)$line['planned_at'], $tz)->format('Y-m-d');
            $days[$day] ??= ['date' => $day, 'as_planned' => 0, 'swapped' => 0, 'replaced' => 0, 'dropped' => 0, 'live_picks' => 0];
            $days[$day][$outcome]++;

            if ('dropped' === $outcome || 'replaced' === $outcome) {
                // "Dropped by log rule: "X" may not play..." -> the rule, not the playlist.
                $reason = trim(explode(';', $note)[0]);
                $reason = (string)preg_replace('/"[^"]*"/', '"…"', $reason);
                $reason = '' !== $reason ? $reason : ucfirst($outcome);
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }

        $plannedTotal = $totals['as_planned'] + $totals['swapped'] + $totals['replaced'] + $totals['dropped'];
        $percent = static fn(int $part, int $whole): ?float => $whole > 0 ? round(100 * $part / $whole, 1) : null;

        foreach ($days as &$day) {
            $dayPlanned = $day['as_planned'] + $day['swapped'] + $day['replaced'] + $day['dropped'];
            $day['compliance_percent'] = $percent($day['as_planned'], $dayPlanned);
        }
        unset($day);
        ksort($days);

        arsort($reasons);
        $reasonRows = [];
        foreach (array_slice($reasons, 0, 15, true) as $reason => $count) {
            $reasonRows[] = ['reason' => $reason, 'count' => $count];
        }

        // ---- As-run: hour landing and gaps -------------------------------------
        // Song history closes each item when the next one starts, so a cut or a
        // silence never shows as a gap between rows. What gives them away is
        // how long an item really held the air against its own length: a song
        // the ID cut ran short; an item followed by dead air or a stuck hold ran
        // long (the 10:00 ID "lasted" 59 minutes on Mon 2026-10-05).
        $history = $conn->fetchAllAssociative(
            'SELECT UNIX_TIMESTAMP(h.timestamp_start) AS started, UNIX_TIMESTAMP(h.timestamp_end) AS ended,
                h.duration, h.text, m.type AS media_type
            FROM song_history h
            LEFT JOIN station_media m ON m.id = h.media_id
            WHERE h.station_id = ? AND h.timestamp_start >= FROM_UNIXTIME(?) AND h.timestamp_start < FROM_UNIXTIME(?)
            ORDER BY h.timestamp_start',
            [$station->id, $start, $end]
        );

        $gaps = [];
        $landings = [];
        $count = count($history);
        for ($i = 0; $i < $count; $i++) {
            $item = $history[$i];
            $next = $history[$i + 1] ?? null;
            $length = (float)($item['duration'] ?? 0);
            if (null === $next || null === $item['ended'] || $length <= 0) {
                continue;
            }

            $started = (float)$item['started'];
            $held = (float)$item['ended'] - $started;
            $over = (int)round($held - $length);

            $nextIsId = in_array($next['media_type'], ['id', 'legal_id'], true)
                || str_contains(strtolower((string)$next['text']), 'legal id');
            $nextAt = (int)round((float)$next['started']);
            $boundary = (int)(round($nextAt / 3600) * 3600);
            $atTopOfHour = $nextIsId && abs($nextAt - $boundary) <= 300;

            if ($atTopOfHour) {
                $landings[] = [
                    'hour' => $boundary,
                    'id_at' => $nextAt,
                    'previous' => (string)$item['text'],
                    // > 0: air left over before the ID; < 0: the item was cut by the ID.
                    'gap_seconds' => $over,
                ];
            }

            // A relayed programme stream reports its titles without a library
            // file and with gaps between them; that air is the show, not silence.
            $nextIsStream = null === $next['media_type'] && !$nextIsId;

            if ($over > self::GAP_SECONDS && !$nextIsStream) {
                $gaps[] = [
                    'at' => (int)round($started + $length),
                    'seconds' => $over,
                    'after' => (string)$item['text'],
                    'before' => (string)$next['text'],
                ];
            }
        }

        $clean = 0;
        $early = 0;
        $cut = 0;
        $misses = [];
        foreach ($landings as $landing) {
            if ($landing['gap_seconds'] > self::LANDING_TOLERANCE_SECONDS) {
                $early++;
                $misses[] = $landing + ['kind' => 'gap'];
            } elseif ($landing['gap_seconds'] < -self::CUT_TOLERANCE_SECONDS) {
                $cut++;
                $misses[] = $landing + ['kind' => 'cut'];
            } else {
                $clean++;
            }
        }

        usort($gaps, static fn(array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return $response->withJson([
            'summary' => [
                'planned_lines' => $plannedTotal,
                'compliance_percent' => $percent($totals['as_planned'], $plannedTotal),
                'as_planned' => $totals['as_planned'],
                'swapped' => $totals['swapped'],
                'replaced' => $totals['replaced'],
                'dropped' => $totals['dropped'],
                'live_picks' => $totals['live_picks'],
                'hours_landed' => count($landings),
                'hours_clean' => $clean,
                'hours_clean_percent' => $percent($clean, count($landings)),
                'hours_early' => $early,
                'hours_cut' => $cut,
                'gaps' => count($gaps),
                'gap_seconds' => array_sum(array_column($gaps, 'seconds')),
            ],
            'tolerance_seconds' => self::LANDING_TOLERANCE_SECONDS,
            'cut_tolerance_seconds' => self::CUT_TOLERANCE_SECONDS,
            'gap_threshold_seconds' => self::GAP_SECONDS,
            'days' => array_values($days),
            'reasons' => $reasonRows,
            'landing_misses' => array_slice(array_reverse($misses), 0, self::MAX_LISTED),
            'gap_list' => array_slice($gaps, 0, self::MAX_LISTED),
        ]);
    }
}
