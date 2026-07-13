<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\CrossfadeProfiles;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Utilities\Types;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[
    OA\Put(
        path: '/station/{station_id}/crossfade-profiles',
        operationId: 'putStationCrossfadeProfiles',
        summary: 'Save content-type crossfade matrix and named profiles.',
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
    )
]
final class PutAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $body = (array)$request->getParsedBody();

        $station = $this->em->refetch($request->getStation());
        $backendConfig = $station->backend_config;

        if (array_key_exists('enabled', $body)) {
            $backendConfig->content_type_crossfade_enabled = Types::bool($body['enabled']);
        }

        if (array_key_exists('matrix', $body) && is_array($body['matrix'])) {
            $backendConfig->content_type_crossfade_matrix = $body['matrix'];
        }

        if (array_key_exists('profiles', $body) && is_array($body['profiles'])) {
            $backendConfig->crossfade_profiles = $body['profiles'];
        }

        $station->backend_config = $backendConfig;
        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(Status::updated());
    }
}
