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
        ]);
    }
}
