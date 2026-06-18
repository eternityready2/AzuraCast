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

        return ['playlists' => $playlists];
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
}
