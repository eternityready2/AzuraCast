<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj;

use App\Controller\SingleActionInterface;
use App\Entity\Repository\AiDjRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/ai-dj/{id}',
    operationId: 'getStationAiDj',
    summary: 'Get a single AI DJ profile.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'id',
            description: 'AI DJ ID',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'integer')
        ),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
    ]
)]
final class GetAction implements SingleActionInterface
{
    public function __construct(
        private readonly AiDjRepository $aiDjRepository,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $djId = (int)($params['id'] ?? 0);

        $dj = $this->aiDjRepository->find($djId);

        if (null === $dj || $dj->getStationId() !== $station->getId()) {
            return $response->withStatus(404)->withJson([
                'error' => 'AI DJ not found.',
            ]);
        }

        return $response->withJson([
            'id' => $dj->getId(),
            'name' => $dj->getName(),
            'is_enabled' => $dj->isEnabled(),
            'voice_model_path' => $dj->getVoiceModelPath(),
            'shift_intro_template' => $dj->getShiftIntroTemplate(),
        ]);
    }
}
