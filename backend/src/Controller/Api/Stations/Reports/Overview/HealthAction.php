<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports\Overview;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\StationHealthService;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/reports/overview/health',
    operationId: 'getStationHealthReport',
    summary: 'Station operational health dashboard aggregates.',
    tags: [OpenApi::TAG_STATIONS_REPORTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OA\Response\Success(),
        new OA\Response\AccessDenied(),
        new OA\Response\GenericError(),
    ]
)]
final class HealthAction implements SingleActionInterface
{
    public function __construct(
        private readonly StationHealthService $healthService,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        return $response->withJson(
            $this->healthService->getReport($request->getStation())
        );
    }
}
