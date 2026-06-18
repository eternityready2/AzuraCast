<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports\Overview;

use App\Entity\Api\Status;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\StationReportsAnalyticsService;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/reports/overview/heatmap',
    operationId: 'getStationReportHeatmap',
    summary: 'Get the 7×24 listener heatmap for a station.',
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
final class HeatmapAction extends AbstractReportAction
{
    public function __construct(
        private readonly StationReportsAnalyticsService $analyticsService,
    ) {
    }

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

        $queryParams = $request->getQueryParams();
        $metric = match ($queryParams['type'] ?? null) {
            'unique' => 'unique',
            default => 'average',
        };

        return $response->withJson(
            $this->analyticsService->getListenerHeatmap(
                $station,
                $dateRange,
                $stationTz,
                $metric,
            ),
        );
    }
}
