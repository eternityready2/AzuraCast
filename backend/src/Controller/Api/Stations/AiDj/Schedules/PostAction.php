<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj\Schedules;

use App\Controller\SingleActionInterface;
use App\Entity\AiDjSchedule;
use App\Entity\Repository\AiDjRepository;
use App\Entity\Repository\AiDjScheduleRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use DateTimeImmutable;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Post(
    path: '/station/{station_id}/ai-dj/{dj_id}/schedules',
    operationId: 'createAiDjSchedule',
    summary: 'Create a new AI DJ schedule.',
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
        new OpenApi\Response\GenericError(),
    ]
)]
final class PostAction implements SingleActionInterface
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

        $data = (array) $request->getParsedBody();

        $schedule = new AiDjSchedule($dj);
        $schedule->setName($data['name']);
        $schedule->setStartTime(new DateTimeImmutable($data['start_time']));
        $schedule->setEndTime(new DateTimeImmutable($data['end_time']));
        $schedule->setLoopDays($data['loop_days']);
        $schedule->setIsEnabled($data['is_enabled'] ?? true);

        if ($this->scheduleRepo->hasOverlap($schedule)) {
            return $response->withStatus(400)
                ->withJson(['error' => 'Schedule overlaps with an existing DJ schedule on this station.']);
        }

        $this->scheduleRepo->save($schedule);

        return $response->withStatus(201)
            ->withJson($schedule->api());
    }
}
