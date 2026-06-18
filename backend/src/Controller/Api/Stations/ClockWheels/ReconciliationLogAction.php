<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Controller\SingleActionInterface;
use App\Entity\Api\ClockWheel\ClockWheelReconciliationEvent;
use App\Entity\Api\ClockWheel\ClockWheelReconciliationLog;
use App\Entity\Enums\ClockWheelEventKind;
use App\Entity\Repository\ClockWheelEventRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Utilities\Types;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/clock-wheels/reconciliation-log',
    operationId: 'getClockWheelReconciliationLog',
    summary: 'Paginated clock wheel audit / reconciliation log.',
    tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'limit',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'integer', default: 50)
        ),
        new OA\Parameter(
            name: 'offset',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'integer', default: 0)
        ),
        new OA\Parameter(
            name: 'wheel_id',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'integer')
        ),
        new OA\Parameter(
            name: 'event_kind',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'string')
        ),
    ],
    responses: [
        new OA\Response\Success(
            content: new OA\JsonContent(ref: ClockWheelReconciliationLog::class)
        ),
        new OA\Response\AccessDenied(),
        new OA\Response\GenericError(),
    ]
)]
final class ReconciliationLogAction implements SingleActionInterface
{
    public function __construct(
        private readonly ClockWheelEventRepository $eventRepo,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $query = $request->getQueryParams();

        $limit = Types::intOrNull($query['limit'] ?? null) ?? 50;
        $offset = Types::intOrNull($query['offset'] ?? null) ?? 0;
        $wheelId = Types::intOrNull($query['wheel_id'] ?? null);

        $eventKind = null;
        if (!empty($query['event_kind']) && is_string($query['event_kind'])) {
            $eventKind = ClockWheelEventKind::tryFrom($query['event_kind']);
        }

        $result = $this->eventRepo->getReconciliationLog(
            $station,
            $limit,
            $offset,
            $wheelId,
            $eventKind,
        );

        $log = new ClockWheelReconciliationLog();
        $log->total = $result['total'];

        foreach ($result['rows'] as $row) {
            $event = new ClockWheelReconciliationEvent();
            $event->id = (int)$row['id'];
            $event->event_timestamp = (string)$row['event_timestamp'];
            $event->event_kind = (string)$row['event_kind'];
            $event->fallback_reason = $row['fallback_reason'] !== null ? (string)$row['fallback_reason'] : null;
            $event->clock_wheel_id = $row['clock_wheel_id'] !== null ? (int)$row['clock_wheel_id'] : null;
            $event->clock_wheel_name = $row['clock_wheel_name'] !== null ? (string)$row['clock_wheel_name'] : null;
            $event->slot_id = $row['slot_id'] !== null ? (int)$row['slot_id'] : null;
            $event->anchor_type = $row['anchor_type'] !== null ? (string)$row['anchor_type'] : null;
            $event->sound_code = $row['sound_code'] !== null ? (string)$row['sound_code'] : null;
            $event->research_score = $row['research_score'] !== null ? (int)$row['research_score'] : null;
            $event->drift_seconds = $row['drift_seconds'] !== null ? (int)$row['drift_seconds'] : null;
            $event->expected_play_at = $row['expected_play_at'] !== null ? (string)$row['expected_play_at'] : null;
            $event->actual_play_at = $row['actual_play_at'] !== null ? (string)$row['actual_play_at'] : null;
            $event->media_id = $row['media_id'] !== null ? (int)$row['media_id'] : null;
            $log->rows[] = $event;
        }

        return $response->withJson($log);
    }
}
