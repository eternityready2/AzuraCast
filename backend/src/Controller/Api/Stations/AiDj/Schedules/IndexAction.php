<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj\Schedules;

use App\Controller\SingleActionInterface;
use App\Entity\Repository\AiDjRepository;
use App\Entity\Repository\AiDjScheduleRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/ai-dj/{dj_id}/schedules',
    operationId: 'getAiDjSchedules',
    summary: 'List all schedules for an AI DJ.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'dj_id',
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
final class IndexAction implements SingleActionInterface
{
    public function __construct(
        private readonly AiDjRepository $djRepo,
        private readonly AiDjScheduleRepository $scheduleRepo,
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $dj = $this->djRepo->findForStation((int) $params['dj_id'], $station->id);

        if (null === $dj) {
            return $response->withStatus(404)->withJson(['error' => 'AI DJ not found.']);
        }

        $schedules = $this->scheduleRepo->findByDj($dj->id);

        return $response->withJson(array_map(
            static fn($schedule) => $schedule->api(),
            $schedules
        ));
    }
}
