<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj\Schedules;

use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Entity\Repository\AiDjRepository;
use App\Entity\Repository\AiDjScheduleRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;

#[\OpenApi\Attributes\Get(
    path: '/station/{station_id}/ai-dj/{dj_id}/schedules',
    operationId: 'getAiDjSchedules',
    summary: 'List all schedules for an AI DJ',
    tags: ['Station Broadcasting'],
    parameters: [
        new \OpenApi\Attributes\Parameter(ref: '#/components/parameters/station_id_required'),
        new \OpenApi\Attributes\Parameter(name: 'dj_id', description: 'AI DJ ID', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer')),
    ],
    responses: [new \OpenApi\Attributes\Response(response: 200, description: 'Success')]
)]
final readonly class IndexAction implements SingleActionInterface
{
    public function __construct(
        private AiDjRepository $djRepo,
        private AiDjScheduleRepository $scheduleRepo
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $dj = $this->djRepo->find((int)$params['dj_id']);

        if (null === $dj || $dj->getStationId() !== $station->id) {
            return $response->withStatus(404)->withJson(['error' => 'AI DJ not found']);
        }

        $schedules = $this->scheduleRepo->findByDj($dj->id);

        return $response->withJson(array_map(
            fn($schedule) => $schedule->api(),
            $schedules
        ));
    }
}
