<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Controller\SingleActionInterface;
use App\Entity\Enums\ClockWheelEventKind;
use App\Entity\Repository\ClockWheelEventRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Utilities\Types;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/clock-wheels/reconciliation-log/export',
    operationId: 'exportClockWheelReconciliationLog',
    summary: 'Export the clock wheel reconciliation log as CSV.',
    tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
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
        new OA\Response\Success(),
        new OA\Response\AccessDenied(),
        new OA\Response\GenericError(),
    ]
)]
final class ReconciliationLogExportAction implements SingleActionInterface
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

        $wheelId = Types::intOrNull($query['wheel_id'] ?? null);

        $eventKind = null;
        if (!empty($query['event_kind']) && is_string($query['event_kind'])) {
            $eventKind = ClockWheelEventKind::tryFrom($query['event_kind']);
        }

        // Export the full matching set, not just one page.
        $result = $this->eventRepo->getReconciliationLog(
            $station,
            100000,
            0,
            $wheelId,
            $eventKind,
        );

        $csvStream = fopen('php://temp', 'r+');

        fputcsv($csvStream, [
            'Time',
            'Wheel',
            'Kind',
            'Type',
            'Code',
            'Drift (seconds)',
            'Reason',
            'Expected Play At',
            'Actual Play At',
            'Media ID',
        ]);

        foreach ($result['rows'] as $row) {
            fputcsv($csvStream, [
                $row['event_timestamp'] ?? '',
                $row['clock_wheel_name'] ?? '',
                $row['event_kind'] ?? '',
                $row['anchor_type'] ?? '',
                $row['sound_code'] ?? '',
                $row['drift_seconds'] ?? '',
                $row['fallback_reason'] ?? '',
                $row['expected_play_at'] ?? '',
                $row['actual_play_at'] ?? '',
                $row['media_id'] ?? '',
            ]);
        }

        rewind($csvStream);
        $csvContent = stream_get_contents($csvStream);
        fclose($csvStream);

        $filename = 'clock-wheel-reconciliation-' . $station->short_name . '-' . date('Y-m-d') . '.csv';

        return $response->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->write($csvContent ?: '');
    }
}
