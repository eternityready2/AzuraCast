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
    path: '/station/{station_id}/ai-dj',
    operationId: 'getStationAiDjList',
    summary: 'List all AI DJs for a station.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
    ]
)]
final class IndexAction implements SingleActionInterface
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
        $djList = $this->aiDjRepository->findByStation($station->id);

        $result = array_map(
            static fn(\App\Entity\AiDj $dj): array => [
                'id' => $dj->id,
                'name' => $dj->getName(),
                'is_enabled' => $dj->isEnabled(),
                'voice_model_path' => $dj->getVoiceModelPath(),
                'shift_intro_template' => $dj->getShiftIntroTemplate(),
            ],
            $djList
        );

        return $response->withJson($result);
    }
}
