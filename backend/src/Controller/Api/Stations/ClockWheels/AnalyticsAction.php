<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Controller\SingleActionInterface;
use App\Entity\Api\ClockWheel\ClockWheelAnalytics;
use App\Entity\StationClockWheel;
use App\Exception;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\ClockWheel\ClockWheelAnalyticsService;
use App\Utilities\Types;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/clock-wheel/{id}/analytics',
    operationId: 'getClockWheelAnalytics',
    summary: 'Return clock wheel audit analytics for the given lookback window.',
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
            name: 'days',
            description: 'Lookback window in days (1–90, default 7).',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'integer', default: 7)
        ),
    ],
    responses: [
        new OA\Response\Success(
            content: new OA\JsonContent(ref: ClockWheelAnalytics::class)
        ),
        new OA\Response\AccessDenied(),
        new OA\Response\NotFound(),
        new OA\Response\GenericError(),
    ]
)]
final class AnalyticsAction implements SingleActionInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockWheelAnalyticsService $analyticsService,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $wheel = $this->getWheelForStation($station->id, (int)$params['id']);

        $days = Types::intOrNull($request->getQueryParam('days')) ?? 7;

        return $response->withJson(
            $this->analyticsService->getForWheel($wheel, $days)
        );
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
