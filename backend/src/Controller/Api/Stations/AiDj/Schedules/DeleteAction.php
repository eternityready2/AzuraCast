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

#[\OpenApi\Attributes\Delete(
    path: '/station/{station_id}/ai-dj/{dj_id}/schedules/{schedule_id}',
    operationId: 'deleteAiDjSchedule',
    summary: 'Delete an AI DJ schedule',
    tags: ['Station Broadcasting'],
    parameters: [
        new \OpenApi\Attributes\Parameter(ref: '#/components/parameters/station_id_required'),
        new \OpenApi\Attributes\Parameter(name: 'dj_id', description: 'AI DJ ID', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer')),
        new \OpenApi\Attributes\Parameter(name: 'schedule_id', description: 'Schedule ID', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer')),
    ],
    responses: [
        new \OpenApi\Attributes\Response(response: 200, description: 'Deleted'),
        new \OpenApi\Attributes\Response(response: 404, description: 'Schedule not found'),
    ]
)]
final readonly class DeleteAction implements SingleActionInterface
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

        if (null === $dj || $dj->getStationId() !== $station->getId()) {
            return $response->withStatus(404)->withJson(Status::error('AI DJ not found'));
        }

        $schedule = $this->scheduleRepo->findOneBy([
            'id' => (int)$params['schedule_id'],
            'ai_dj' => $dj,
        ]);

        if (null === $schedule) {
            return $response->withStatus(404)->withJson(Status::error('Schedule not found'));
        }

        $this->scheduleRepo->delete($schedule);

        return $response->withJson(Status::deleted());
    }
}
