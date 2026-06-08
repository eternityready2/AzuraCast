<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj\Schedules;

use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Entity\AiDjSchedule;
use App\Entity\Repository\AiDjRepository;
use App\Entity\Repository\AiDjScheduleRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;

#[\OpenApi\Attributes\Post(
    path: '/station/{station_id}/ai-dj/{dj_id}/schedules',
    operationId: 'createAiDjSchedule',
    summary: 'Create a new AI DJ schedule',
    tags: ['Station Broadcasting'],
    parameters: [
        new \OpenApi\Attributes\Parameter(ref: '#/components/parameters/station_id_required'),
        new \OpenApi\Attributes\Parameter(name: 'dj_id', description: 'AI DJ ID', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer')),
    ],
    responses: [
        new \OpenApi\Attributes\Response(response: 201, description: 'Created'),
        new \OpenApi\Attributes\Response(response: 400, description: 'Validation error'),
    ]
)]
final readonly class PostAction implements SingleActionInterface
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

        $data = (array)$request->getParsedBody();

        $schedule = new AiDjSchedule($dj);
        $schedule->setName($data['name']);
        $schedule->setStartTime(new \DateTimeImmutable($data['start_time']));
        $schedule->setEndTime(new \DateTimeImmutable($data['end_time']));
        $schedule->setLoopDays($data['loop_days']);
        $schedule->setIsEnabled($data['is_enabled'] ?? true);

        if ($this->scheduleRepo->hasOverlap($schedule)) {
            return $response->withStatus(400)
                ->withJson(Status::error('Schedule overlaps with existing schedule for this DJ'));
        }

        $this->scheduleRepo->save($schedule);

        return $response->withStatus(201)
            ->withJson(Status::created()->withData($schedule->api()));
    }
}
