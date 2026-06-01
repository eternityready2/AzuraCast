<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Controller\SingleActionInterface;
use App\Entity\Api\ClockWheel\ClockWheelPreview;
use App\Entity\StationClockWheel;
use App\Exception;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\ClockWheel\ClockWheelPreviewSimulator;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/clock-wheel/{id}/preview',
    operationId: 'getClockWheelPreview',
    summary: 'Simulate projected playback for the next broadcast hour.',
    tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'id',
            description: 'Clock Wheel ID',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'integer', format: 'int64')
        ),
        new OA\Parameter(
            name: 'hour',
            description: 'ISO-8601 hour to simulate (defaults to next hour in station timezone).',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'string')
        ),
    ],
    responses: [
        new OA\Response\Success(
            content: new OA\JsonContent(ref: ClockWheelPreview::class)
        ),
        new OA\Response\AccessDenied(),
        new OA\Response\NotFound(),
        new OA\Response\GenericError(),
    ]
)]
final class PreviewAction implements SingleActionInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockWheelPreviewSimulator $simulator,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $wheel = $this->getWheelForStation($station->id, (int)$params['id']);

        $queryParams = $request->getQueryParams();
        if (!empty($queryParams['hour'])) {
            $tz = $station->getTimezoneObject();
            $hourStart = CarbonImmutable::parse((string)$queryParams['hour'], $tz)->startOf('hour');
            $preview = $this->simulator->simulateHour($wheel, $hourStart);
        } else {
            $preview = $this->simulator->simulateNextHour($wheel);
        }

        return $response->withJson($preview);
    }

    private function getWheelForStation(int $stationId, int $wheelId): StationClockWheel
    {
        $wheel = $this->em->find(StationClockWheel::class, $wheelId);
        if (!$wheel instanceof StationClockWheel || $wheel->station_id !== $stationId) {
            throw Exception\NotFoundException::generic();
        }

        return $wheel;
    }
}
