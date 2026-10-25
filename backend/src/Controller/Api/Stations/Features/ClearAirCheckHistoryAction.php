<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Features;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Delete(
    path: '/station/{station_id}/features/aircheck/history',
    operationId: 'clearStationAirCheckHistory',
    summary: 'Clear the AirCheck intervention history for a station.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
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
final class ClearAirCheckHistoryAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $needsRestartBefore = $station->needs_restart;
        $config = $station->backend_config;

        $config->aircheck_interventions = [];
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;

        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(Status::updated());
    }
}
