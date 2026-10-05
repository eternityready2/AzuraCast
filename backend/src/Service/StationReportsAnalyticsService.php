<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\AnalyticsIntervals;
use App\Entity\Repository\AnalyticsRepository;
use App\Entity\Repository\ClockWheelEventRepository;
use App\Entity\Repository\ListenerRepository;
use App\Entity\Song;
use App\Entity\Station;
use App\Entity\ApiGenerator\SongApiGenerator;
use App\Entity\StationClockWheel;
use App\Radio\AutoDJ\ClockWheel\ClockWheelAnalyticsService;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Utilities\DateRange;
use Carbon\CarbonImmutable;
use DateTimeZone;

final class StationReportsAnalyticsService
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly AnalyticsRepository $analyticsRepo,
        private readonly ClockWheelEventRepository $eventRepo,
        private readonly ListenerRepository $listenerRepo,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly SongApiGenerator $songApiGenerator,
        private readonly ClockWheelAnalyticsService $wheelAnalytics,
    ) {
    }

    private function shouldExcludeBots(Station $station): bool
    {
        return $station->backend_config->analytics_exclude_bots;
    }

    /**
     * @return array{
     *     metric: string,
     *     day_labels: array<int, string>,
     *     hour_labels: array<int, string>,
     *     cells: array<int, array<int, float>>,
     *     max_value: float
     * }
     */
    public function getListenerHeatmap(
        Station $station,
        DateRange $dateRange,
        DateTimeZone $stationTz,
        string $metric = 'average',
    ): array {
        $statKey = 'average' === $metric ? 'number_avg' : 'number_unique';

        $hourlyStats = $this->analyticsRepo->findForStationInRange(
            $station,
            $dateRange,
            AnalyticsIntervals::Hourly,
        );

        $cells = [];
        for ($day = 0; $day < 7; $day++) {
            $cells[$day] = array_fill(0, 24, 0.0);
        }

        $counts = [];
        for ($day = 0; $day < 7; $day++) {
            $counts[$day] = array_fill(0, 24, 0);
        }

        foreach ($hourlyStats as $stat) {
            $statTime = CarbonImmutable::instance($stat['moment']);
            $statTime = $statTime->shiftTimezone($stationTz);

            $day = (int)$statTime->format('N') - 1;
            $hour = $statTime->hour;
            $value = (float)$stat[$statKey];

            if ('number_unique' === $statKey) {
                $cells[$day][$hour] += $value;
            } else {
                $cells[$day][$hour] += $value;
                $counts[$day][$hour]++;
            }
        }

        if ('number_avg' === $statKey) {
            for ($day = 0; $day < 7; $day++) {
                for ($hour = 0; $hour < 24; $hour++) {
                    if ($counts[$day][$hour] > 0) {
                        $cells[$day][$hour] = round(
                            $cells[$day][$hour] / $counts[$day][$hour],
                            2,
                        );
                    }
                }
            }
        }

        $maxValue = 0.0;
        foreach ($cells as $row) {
            foreach ($row as $value) {
                $maxValue = max($maxValue, $value);
            }
        }

        return [
            'metric' => $metric,
            'day_labels' => [
                __('Monday'),
                __('Tuesday'),
                __('Wednesday'),
                __('Thursday'),
                __('Friday'),
                __('Saturday'),
                __('Sunday'),
            ],
            'hour_labels' => array_map(
                static fn (int $hour): string => $hour . ':00',
                range(0, 23),
            ),
            'cells' => $cells,
            'max_value' => round($maxValue, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getClockPerformance(
        Station $station,
        DateRange $dateRange,
    ): array {
        $since = $dateRange->start;
        $tolerance = $this->hourBoundaryPlanner->getComplianceToleranceSeconds($station);

        $summary = $this->eventRepo->getStationAnalyticsSummary($station, $since);
        $byWheel = $this->eventRepo->getStationAnalyticsByWheel($station, $since);
        $legalIdCompliance = $this->eventRepo->getStationLegalIdComplianceSummary(
            $station,
            $since,
            $tolerance,
        );
        $topOfHourCompliance = $this->eventRepo->getStationTopOfHourLegalIdComplianceSummary(
            $station,
            $since,
            $tolerance,
        );

        // Scorecard columns per wheel, from the same analytics as the wheel's
        // own How it aired tab, plus how much of what it put in the Linear Log
        // aired as planned.
        $days = max(1, (int)ceil(($dateRange->end->getTimestamp() - $since->getTimestamp()) / 86400));
        foreach ($byWheel as &$row) {
            $wheelId = $row['wheel_id'] ?? null;
            $wheel = null !== $wheelId ? $this->em->find(StationClockWheel::class, (int)$wheelId) : null;
            if (!$wheel instanceof StationClockWheel) {
                continue;
            }

            $a = $this->wheelAnalytics->getForWheel($wheel, $days);
            $row['avg_drift'] = $a->avg_drift_seconds;
            $row['legal_id_compliance_percent'] = $a->legal_id_compliance_percent;
            $row['effectiveness_score'] = $a->effectiveness_score;
            $row['effectiveness_grade'] = $a->effectiveness_grade;
            $row['avg_listeners'] = $a->avg_listeners;
            $reasons = $a->fallback_reasons;
            arsort($reasons);
            $row['top_fallback_reason'] = array_key_first($reasons);

            $log = $this->em->getConnection()->fetchAssociative(
                'SELECT COUNT(DISTINCT FLOOR(planned_at / 3600)) AS hours,
                    SUM(status = ? AND (note IS NULL OR note NOT LIKE ?)) AS as_planned,
                    SUM(status IN (?, ?, ?) AND (note IS NULL OR note NOT LIKE ?)) AS planned
                FROM station_log_entries
                WHERE station_id = ? AND planned_at >= ? AND planned_at < ?
                AND JSON_VALUE(payload, ?) = ?',
                [
                    'aired', 'Live:%',
                    'aired', 'swapped', 'replaced', 'Live:%',
                    $station->id, $since->getTimestamp(), min(time(), $dateRange->end->getTimestamp()),
                    '$.clock_wheel_id', (string)$wheel->id,
                ]
            ) ?: [];
            $planned = (int)($log['planned'] ?? 0);
            $row['hours_aired'] = (int)($log['hours'] ?? 0);
            $row['log_compliance_percent'] = $planned > 0
                ? round(100 * (int)$log['as_planned'] / $planned, 1)
                : null;
        }
        unset($row);

        return [
            'summary' => $summary,
            'wheels' => $byWheel,
            'legal_id_compliance' => $legalIdCompliance,
            'top_of_hour_compliance' => $topOfHourCompliance,
        ];
    }

    /**
     * @return array{
     *     playlists: array<int, array{
     *         id: int,
     *         name: string,
     *         play_count: int,
     *         avg_delta: float|null,
     *         avg_unique_listeners: float|null,
     *         tune_outs: int,
     *         rotation_equity_percent: float|null,
     *         min_track_plays: int|null,
     *         max_track_plays: int|null
     *     }>
     * }
     */
    public function getPlaylistPerformance(
        Station $station,
        DateRange $dateRange,
    ): array {
        /** @var array<array{
         *     id: int,
         *     name: string,
         *     play_count: string,
         *     avg_delta: string|null,
         *     avg_unique_listeners: string|null,
         *     tune_outs: string
         * }> $rows
         */
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT p.id, p.name, p.rotation_goal_days,
                    COUNT(sh.id) AS play_count,
                    AVG(sh.delta_total) AS avg_delta,
                    AVG(sh.unique_listeners) AS avg_unique_listeners,
                    SUM(sh.delta_negative) AS tune_outs
                FROM App\Entity\SongHistory sh
                JOIN sh.playlist p
                WHERE sh.station = :station
                AND sh.is_visible = 1
                AND sh.playlist IS NOT NULL
                AND sh.timestamp_start <= :end
                AND sh.timestamp_end >= :start
                GROUP BY p.id, p.name, p.rotation_goal_days
                ORDER BY play_count DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('start', $dateRange->start)
            ->setParameter('end', $dateRange->end)
            ->getArrayResult();

        $equityByPlaylist = $this->getRotationEquityByPlaylist($station, $dateRange);

        $playlists = [];
        foreach ($rows as $row) {
            $playlistId = (int)$row['id'];
            $equity = $equityByPlaylist[$playlistId] ?? null;

            $playlists[] = [
                'id' => $playlistId,
                'name' => $row['name'],
                'play_count' => (int)$row['play_count'],
                'avg_delta' => is_numeric($row['avg_delta'])
                    ? round((float)$row['avg_delta'], 2)
                    : null,
                'avg_unique_listeners' => is_numeric($row['avg_unique_listeners'])
                    ? round((float)$row['avg_unique_listeners'], 2)
                    : null,
                'tune_outs' => (int)$row['tune_outs'],
                'rotation_equity_percent' => $equity['equity_percent'] ?? null,
                'min_track_plays' => $equity['min_plays'] ?? null,
                'max_track_plays' => $equity['max_plays'] ?? null,
                'rotation_goal_days' => isset($row['rotation_goal_days'])
                    ? (int)$row['rotation_goal_days']
                    : null,
            ];
        }

        return [
            'playlists' => $playlists,
            ...$this->getRotationHealth($station, $dateRange),
        ];
    }

    /**
     * Music-director view of rotation: the most-played songs, songs in active
     * music playlists that never aired, and repeats inside the station's
     * duplicate-prevention window, from what actually aired.
     *
     * @return array{over_played: list<array<string, mixed>>, never_played_count: int, never_played: list<array<string, mixed>>, repeats: list<array<string, mixed>>, repeat_window_minutes: int}
     */
    private function getRotationHealth(Station $station, DateRange $dateRange): array
    {
        $conn = $this->em->getConnection();
        $from = $dateRange->start->utc()->format('Y-m-d H:i:s');
        $to = $dateRange->end->utc()->format('Y-m-d H:i:s');

        $overPlayed = $conn->fetchAllAssociative(
            'SELECT h.media_id, MAX(h.text) AS text, COUNT(*) AS plays, MAX(p.name) AS playlist
            FROM song_history h
            JOIN station_media m ON m.id = h.media_id AND m.type = ?
            LEFT JOIN station_playlists p ON p.id = h.playlist_id
            WHERE h.station_id = ? AND h.timestamp_start BETWEEN ? AND ?
            GROUP BY h.media_id
            ORDER BY plays DESC
            LIMIT 15',
            ['music', $station->id, $from, $to]
        );

        $neverSql = 'FROM station_playlist_media spm
            JOIN station_playlists p ON p.id = spm.playlist_id
            JOIN station_media m ON m.id = spm.media_id AND m.type = ?
            WHERE p.station_id = ? AND p.is_enabled = 1 AND p.is_jingle = 0
            AND NOT EXISTS (
                SELECT 1 FROM song_history h
                WHERE h.station_id = p.station_id AND h.media_id = m.id
                AND h.timestamp_start BETWEEN ? AND ?
            )';
        $neverArgs = ['music', $station->id, $from, $to];
        $neverCount = (int)$conn->fetchOne('SELECT COUNT(DISTINCT m.id) ' . $neverSql, $neverArgs);
        $neverPlayed = $conn->fetchAllAssociative(
            'SELECT m.id AS media_id, MAX(m.artist) AS artist, MAX(m.title) AS title, MAX(p.name) AS playlist '
            . $neverSql . ' GROUP BY m.id ORDER BY MAX(m.artist), MAX(m.title) LIMIT 25',
            $neverArgs
        );

        $window = max(1, $station->backend_config->duplicate_prevention_time_range);
        $repeats = $conn->fetchAllAssociative(
            'SELECT a.text, UNIX_TIMESTAMP(a.timestamp_start) AS first_at, UNIX_TIMESTAMP(b.timestamp_start) AS again_at,
                ROUND((UNIX_TIMESTAMP(b.timestamp_start) - UNIX_TIMESTAMP(a.timestamp_start)) / 60) AS minutes_apart
            FROM song_history a
            JOIN song_history b ON b.station_id = a.station_id AND b.media_id = a.media_id
                AND b.timestamp_start > a.timestamp_start
                AND b.timestamp_start <= a.timestamp_start + INTERVAL ? MINUTE
            JOIN station_media m ON m.id = a.media_id AND m.type = ?
            WHERE a.station_id = ? AND a.timestamp_start BETWEEN ? AND ?
            AND NOT EXISTS (
                SELECT 1 FROM song_history c
                WHERE c.station_id = a.station_id AND c.media_id = a.media_id
                AND c.timestamp_start > a.timestamp_start AND c.timestamp_start < b.timestamp_start
            )
            ORDER BY a.timestamp_start DESC
            LIMIT 50',
            [$window, 'music', $station->id, $from, $to]
        );

        return [
            'over_played' => $overPlayed,
            'never_played_count' => $neverCount,
            'never_played' => $neverPlayed,
            'repeats' => $repeats,
            'repeat_window_minutes' => $window,
        ];
    }

    /**
     * @return array<int, array{equity_percent: float|null, min_plays: int, max_plays: int}>
     */
    private function getRotationEquityByPlaylist(
        Station $station,
        DateRange $dateRange,
    ): array {
        /** @var array<array{playlist_id: int, media_id: int, play_count: string}> $trackRows */
        $trackRows = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(sh.playlist) AS playlist_id, sh.media_id, COUNT(sh.id) AS play_count
                FROM App\Entity\SongHistory sh
                WHERE sh.station = :station
                AND sh.is_visible = 1
                AND sh.playlist IS NOT NULL
                AND sh.media_id IS NOT NULL
                AND sh.timestamp_start <= :end
                AND sh.timestamp_end >= :start
                GROUP BY sh.playlist, sh.media_id
            DQL
        )->setParameter('station', $station)
            ->setParameter('start', $dateRange->start)
            ->setParameter('end', $dateRange->end)
            ->getArrayResult();

        $byPlaylist = [];
        foreach ($trackRows as $row) {
            $playlistId = (int)$row['playlist_id'];
            $plays = (int)$row['play_count'];
            $byPlaylist[$playlistId][] = $plays;
        }

        $result = [];
        foreach ($byPlaylist as $playlistId => $playCounts) {
            $minPlays = min($playCounts);
            $maxPlays = max($playCounts);
            $equityPercent = $maxPlays > 0
                ? round(($minPlays / $maxPlays) * 100, 1)
                : null;

            $result[$playlistId] = [
                'equity_percent' => $equityPercent,
                'min_plays' => $minPlays,
                'max_plays' => $maxPlays,
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *     songs: array<int, array{
     *         song: mixed,
     *         play_count: int,
     *         dropout_count: int,
     *         dropout_rate_percent: float|null
     *     }>
     * }
     */
    public function getSongDropouts(
        Station $station,
        DateRange $dateRange,
    ): array {
        $botFilter = $this->shouldExcludeBots($station)
            ? ' AND l.device_is_bot = 0'
            : '';

        $statsRaw = $this->em->getConnection()->fetchAllAssociative(
            <<<SQL
                SELECT sh.song_id, sh.text, sh.artist, sh.title, sh.media_id,
                       COUNT(DISTINCT sh.id) AS play_count,
                       COUNT(DISTINCT l.id) AS dropout_count
                FROM song_history sh
                INNER JOIN listener l ON l.station_id = sh.station_id
                    AND l.timestamp_end IS NOT NULL
                    AND l.timestamp_start <= sh.timestamp_start
                    AND l.timestamp_end >= sh.timestamp_start
                    AND l.timestamp_end <= DATE_ADD(sh.timestamp_start, INTERVAL 30 SECOND)
                    {$botFilter}
                WHERE sh.station_id = :station_id
                    AND sh.is_visible = 1
                    AND sh.timestamp_start >= :start
                    AND sh.timestamp_start <= :end
                GROUP BY sh.song_id, sh.text, sh.artist, sh.title, sh.media_id
                HAVING dropout_count > 0
                ORDER BY dropout_count DESC
                LIMIT 25
            SQL,
            [
                'station_id' => $station->id,
                'start' => $dateRange->start,
                'end' => $dateRange->end,
            ],
        );

        $songs = [];
        foreach ($statsRaw as $row) {
            $playCount = (int)$row['play_count'];
            $dropoutCount = (int)$row['dropout_count'];

            $song = $this->songApiGenerator->__invoke(
                Song::createFromArray($row),
                $station,
            );

            $songs[] = [
                'song' => $song,
                'play_count' => $playCount,
                'dropout_count' => $dropoutCount,
                'dropout_rate_percent' => $playCount > 0
                    ? round(($dropoutCount / $playCount) * 100, 1)
                    : null,
            ];
        }

        return ['songs' => $songs];
    }

    /**
     * @return array<string, mixed>
     */
    public function getListenerInsights(
        Station $station,
        DateRange $dateRange,
    ): array {
        $excludeBots = $this->shouldExcludeBots($station);

        return [
            'analytics_exclude_bots' => $excludeBots,
            'session_breakdown' => $this->listenerRepo->getSessionBreakdown(
                $station,
                $dateRange->start,
                $dateRange->end,
            ),
            'loyalty' => $this->listenerRepo->getListenerLoyaltyStats(
                $station,
                $dateRange->start,
                $dateRange->end,
                $excludeBots,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getGrowthTrend(
        Station $station,
        DateRange $dateRange,
    ): array {
        $midpoint = $dateRange->start->add(
            new \DateInterval('PT' . (int) floor(
                ($dateRange->end->getTimestamp() - $dateRange->start->getTimestamp()) / 2,
            ) . 'S'),
        );

        return [
            'analytics_exclude_bots' => $this->shouldExcludeBots($station),
            'first_period_start' => $dateRange->start->format(\DateTimeInterface::ATOM),
            'first_period_end' => $midpoint->format(\DateTimeInterface::ATOM),
            'second_period_start' => $midpoint->format(\DateTimeInterface::ATOM),
            'second_period_end' => $dateRange->end->format(\DateTimeInterface::ATOM),
            'hourly' => $this->listenerRepo->getHourlyGrowthTrend(
                $station,
                $dateRange->start,
                $dateRange->end,
                $this->shouldExcludeBots($station),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRetentionCurve(
        Station $station,
        DateRange $dateRange,
    ): array {
        $excludeBots = $this->shouldExcludeBots($station);

        return [
            'analytics_exclude_bots' => $excludeBots,
            ...$this->listenerRepo->getRetentionCurve(
                $station,
                $dateRange->start,
                $dateRange->end,
                $excludeBots,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDaypartAudience(
        Station $station,
        DateRange $dateRange,
    ): array {
        /** @var array<array{id: int, name: string, start_hour: int, end_hour: int, is_active: bool}> $dayparts */
        $dayparts = $this->em->createQuery(
            <<<'DQL'
                SELECT d.id, d.name, d.start_hour, d.end_hour, d.is_active
                FROM App\Entity\StationClockDaypart d
                WHERE d.station = :station
                ORDER BY d.start_hour ASC
            DQL
        )->setParameter('station', $station)
            ->getArrayResult();

        return [
            'analytics_exclude_bots' => $this->shouldExcludeBots($station),
            'dayparts' => $this->listenerRepo->getDaypartAudienceStats(
                $station,
                $dateRange->start,
                $dateRange->end,
                $dayparts,
                $this->shouldExcludeBots($station),
            ),
        ];
    }
}
