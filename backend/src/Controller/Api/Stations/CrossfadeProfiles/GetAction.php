<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\CrossfadeProfiles;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\ContentTypeCrossfadeService;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[
    OA\Get(
        path: '/station/{station_id}/crossfade-profiles',
        operationId: 'getStationCrossfadeProfiles',
        summary: 'Get content-type crossfade matrix and named profiles.',
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
final class GetAction implements SingleActionInterface
{
    public function __construct(
        private readonly ContentTypeCrossfadeService $crossfadeService,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        return $response->withJson(
            $this->crossfadeService->getSettingsForStation($request->getStation()),
        );
    }
}
