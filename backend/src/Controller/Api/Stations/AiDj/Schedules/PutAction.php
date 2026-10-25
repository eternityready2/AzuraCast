<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj\Schedules;

use App\Controller\SingleActionInterface;
use App\Entity\Repository\AiDjRepository;
use App\Entity\Repository\AiDjScheduleRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use DateTimeImmutable;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Put(
    path: '/station/{station_id}/ai-dj/{dj_id}/schedules/{schedule_id}',
    operationId: 'updateAiDjSchedule',
    summary: 'Update an AI DJ schedule.',
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
        new OA\Parameter(
            name: 'schedule_id',
            description: 'Schedule ID',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'integer')
        ),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class PutAction implements SingleActionInterface
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

        $schedule = $this->scheduleRepo->findForDj((int) $params['schedule_id'], $dj);

        if (null === $schedule) {
            return $response->withStatus(404)->withJson(['error' => 'Schedule not found.']);
        }

        $data = (array) $request->getParsedBody();

        if (isset($data['name'])) {
            $schedule->setName($data['name']);
        }
        if (isset($data['start_time'])) {
            $schedule->setStartTime(new DateTimeImmutable($data['start_time']));
        }
        if (isset($data['end_time'])) {
            $schedule->setEndTime(new DateTimeImmutable($data['end_time']));
        }
        if (isset($data['loop_days'])) {
            $schedule->setLoopDays($data['loop_days']);
        }
        if (isset($data['is_enabled'])) {
            $schedule->setIsEnabled((bool) $data['is_enabled']);
        }

        if ($this->scheduleRepo->hasOverlap($schedule, true)) {
            return $response->withStatus(400)
                ->withJson(['error' => 'Schedule overlaps with an existing DJ schedule on this station.']);
        }

        $this->scheduleRepo->save($schedule);

        return $response->withJson($schedule->api());
    }
}
