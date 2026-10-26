<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports\Overview;

use App\Entity\Api\Status;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/reports/overview/sponsor-plays',
    operationId: 'getStationReportSponsorPlays',
    summary: 'Get the Sponsor Play Report -- proof-of-delivery for sponsor/ad spots.',
    tags: [OpenApi::TAG_STATIONS_REPORTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class SponsorPlaysAction extends AbstractReportAction
{
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
        $stationTz = $station->getTimezoneObject();
        $dateRange = $this->getDateRange($request, $stationTz);

        $sponsorPlaylistIds = [];
        $sponsorNamesByPlaylistId = [];
        $guaranteedPerDayByPlaylistId = [];

        foreach ($station->playlists as $playlist) {
            if ($playlist->is_sponsor) {
                $sponsorPlaylistIds[] = $playlist->id;
                $sponsorNamesByPlaylistId[$playlist->id] = $playlist->sponsor_name ?? $playlist->name;
                $guaranteedPerDayByPlaylistId[$playlist->id] = $playlist->sponsor_guaranteed_plays_per_day;
            }
        }

        if (empty($sponsorPlaylistIds)) {
            return $response->withJson([
                'sponsors' => [],
                'plays' => [],
                'promo_delivery' => $this->getPromoDelivery($station->id, $dateRange->start, $dateRange->end),
            ]);
        }

        $playsRaw = $this->em->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT sh.playlist_id,
                       sh.timestamp_start,
                       sh.title,
                       sh.artist,
                       sh.listeners_start,
                       sh.listeners_end
                FROM song_history sh
                WHERE sh.station_id = :station_id
                AND sh.playlist_id IN (:playlist_ids)
                AND sh.timestamp_start >= :start
                AND sh.timestamp_start <= :end
                ORDER BY sh.timestamp_start DESC
            SQL,
            [
                'station_id' => $station->id,
                'playlist_ids' => $sponsorPlaylistIds,
                'start' => $dateRange->start,
                'end' => $dateRange->end,
            ],
            [
                'playlist_ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ]
        );

        $playsBySponsor = [];
        $plays = [];

        foreach ($playsRaw as $row) {
            $playlistId = (int)$row['playlist_id'];
            $sponsorName = $sponsorNamesByPlaylistId[$playlistId] ?? 'Unknown Sponsor';

            $playsBySponsor[$sponsorName] = ($playsBySponsor[$sponsorName] ?? 0) + 1;

            $plays[] = [
                'sponsor_name' => $sponsorName,
                'played_at' => $row['timestamp_start'],
                'title' => $row['title'],
                'artist' => $row['artist'],
                'listeners' => $row['listeners_start'],
            ];
        }

        $sponsors = [];
        foreach ($sponsorNamesByPlaylistId as $playlistId => $sponsorName) {
            $sponsors[] = [
                'sponsor_name' => $sponsorName,
                'guaranteed_plays_per_day' => $guaranteedPerDayByPlaylistId[$playlistId],
                'total_plays_in_range' => $playsBySponsor[$sponsorName] ?? 0,
            ];
        }

        return $response->withJson([
            'sponsors' => $sponsors,
            'plays' => $plays,
            'promo_delivery' => $this->getPromoDelivery($station->id, $dateRange->start, $dateRange->end),
        ]);
    }

    /**
     * How promos and ads actually reached air: how many aired, breaks where
     * three or more ran back to back, and any the Top-of-Hour ID cut short.
     * Song history closes an item when the next starts, so "cut" means it held
     * the air clearly less than its own length and the ID came next.
     *
     * @return array<string, mixed>
     */
    private function getPromoDelivery(int $stationId, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT UNIX_TIMESTAMP(h.timestamp_start) AS started, UNIX_TIMESTAMP(h.timestamp_end) AS ended,
                h.duration, h.text, m.type AS media_type
            FROM song_history h
            LEFT JOIN station_media m ON m.id = h.media_id
            WHERE h.station_id = ? AND h.timestamp_start BETWEEN ? AND ?
            ORDER BY h.timestamp_start',
            [
                $stationId,
                gmdate('Y-m-d H:i:s', $start->getTimestamp()),
                gmdate('Y-m-d H:i:s', $end->getTimestamp()),
            ]
        );

        $isPromo = static fn(array $r): bool => in_array($r['media_type'], ['promo', 'ad'], true);
        $isId = static fn(?array $r): bool => null !== $r
            && (in_array($r['media_type'], ['id', 'legal_id'], true) || str_contains(strtolower((string)$r['text']), 'legal id'));

        $aired = 0;
        $stacks = [];
        $cut = [];
        $run = [];
        $count = count($rows);
        for ($i = 0; $i < $count; $i++) {
            $row = $rows[$i];
            $next = $rows[$i + 1] ?? null;

            if (!$isPromo($row)) {
                if (count($run) >= 3) {
                    $stacks[] = ['at' => (int)round((float)$run[0]['started']), 'count' => count($run), 'items' => array_column($run, 'text')];
                }
                $run = [];
                continue;
            }

            $aired++;
            $run[] = $row;

            $length = (float)($row['duration'] ?? 0);
            if ($length > 0 && null !== $row['ended'] && $isId($next)) {
                $held = (float)$row['ended'] - (float)$row['started'];
                if ($held < $length - 5) {
                    $cut[] = [
                        'at' => (int)round((float)$row['started']),
                        'text' => (string)$row['text'],
                        'cut_seconds' => (int)round($length - $held),
                    ];
                }
            }
        }
        if (count($run) >= 3) {
            $stacks[] = ['at' => (int)round((float)$run[0]['started']), 'count' => count($run), 'items' => array_column($run, 'text')];
        }

        return [
            'aired' => $aired,
            'stacks' => array_reverse($stacks),
            'cut_at_id' => array_reverse($cut),
        ];
    }
}
