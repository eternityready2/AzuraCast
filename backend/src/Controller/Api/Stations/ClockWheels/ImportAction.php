<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Controller\SingleActionInterface;
use App\Entity\StationClockWheel;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\ClockWheel\ClockWheelExportService;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Serializer;

#[OA\Post(
    path: '/station/{station_id}/clock-wheels/import',
    operationId: 'importClockWheel',
    summary: 'Create a new clock wheel from exported JSON.',
    tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    requestBody: new OA\RequestBody(
        content: new OA\JsonContent(type: 'object')
    ),
    responses: [
        new OA\Response\Success(
            content: new OA\JsonContent(ref: StationClockWheel::class)
        ),
        new OA\Response\AccessDenied(),
        new OA\Response\GenericError(),
    ]
)]
final class ImportAction implements SingleActionInterface
{
    public function __construct(
        private readonly ClockWheelExportService $exportService,
        private readonly Serializer $serializer,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $payload = $request->getParsedBody();

        if (!is_array($payload)) {
            $payload = [];
        }

        $wheel = $this->exportService->importWheel($station, $payload);

        return $response->withJson(
            $this->serializer->normalize(
                $wheel,
                null,
                [
                    AbstractNormalizer::IGNORED_ATTRIBUTES => ['station', 'slots', 'schedule_items', 'daypart', 'template'],
                ]
            )
        );
    }
}
