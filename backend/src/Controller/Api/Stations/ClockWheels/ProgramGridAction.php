<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Controller\SingleActionInterface;
use App\Entity\Api\ClockWheel\ClockWheelProgramGrid;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\ClockWheel\ClockWheelProgramGridService;
use Carbon\CarbonImmutable;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/clock-wheels/program-grid',
    operationId: 'getClockWheelProgramGrid',
    summary: 'Weekly 7×24 program grid from dayparts and calendar schedules.',
    tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'week',
            description: 'ISO date (any day) within the target week; defaults to current week.',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'string')
        ),
    ],
    responses: [
        new OA\Response\Success(
            content: new OA\JsonContent(ref: ClockWheelProgramGrid::class)
        ),
        new OA\Response\AccessDenied(),
        new OA\Response\GenericError(),
    ]
)]
final class ProgramGridAction implements SingleActionInterface
{
    public function __construct(
        private readonly ClockWheelProgramGridService $gridService,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $weekRaw = $request->getQueryParam('week');

        $weekStart = null;
        if (is_string($weekRaw) && $weekRaw !== '') {
            $tz = $station->getTimezoneObject();
            $weekStart = CarbonImmutable::parse($weekRaw, $tz)->startOfDay()->toDateTimeImmutable();
        }

        return $response->withJson(
            $this->gridService->getGrid($station, $weekStart)
        );
    }
}
