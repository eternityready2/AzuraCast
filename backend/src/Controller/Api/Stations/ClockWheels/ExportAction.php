<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Controller\SingleActionInterface;
use App\Entity\Api\ClockWheel\ClockWheelExport;
use App\Entity\StationClockWheel;
use App\Exception;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\ClockWheel\ClockWheelExportService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/clock-wheel/{id}/export',
    operationId: 'exportClockWheel',
    summary: 'Export a clock wheel layout as portable JSON.',
    tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'id',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'integer', format: 'int64')
        ),
    ],
    responses: [
        new OA\Response\Success(
            content: new OA\JsonContent(ref: ClockWheelExport::class)
        ),
        new OA\Response\AccessDenied(),
        new OA\Response\NotFound(),
        new OA\Response\GenericError(),
    ]
)]
final class ExportAction implements SingleActionInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockWheelExportService $exportService,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $wheel = $this->getWheelForStation($station->id, (int)$params['id']);

        return $response->withJson($this->exportService->exportWheel($wheel));
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
