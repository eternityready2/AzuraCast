<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\StationClockWheel;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\ClockWheel\ClockWheelFormatGenerator;
use App\Radio\AutoDJ\ClockWheel\ClockWheelSlotWriter;
use App\Utilities\Types;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Post(
    path: '/station/{station_id}/clock-wheels/generate',
    operationId: 'generateClockWheelFromFormat',
    summary: 'Auto Format Clock Generator: create a new, fully-editable clock wheel from hour-level goals.',
    tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OA\Response\Success(),
        new OA\Response\AccessDenied(),
        new OA\Response\GenericError(),
    ]
)]
final class GenerateAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly ClockWheelFormatGenerator $generator,
        private readonly ClockWheelSlotWriter $slotWriter,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $body = (array)$request->getParsedBody();

        $name = Types::stringOrNull($body['name'] ?? null, true) ?? 'Generated Clock Wheel';

        $goals = [
            'music_percent' => Types::intOrNull($body['music_percent'] ?? null) ?? 75,
            'id_at_top' => Types::boolOrNull($body['id_at_top'] ?? null) ?? true,
            'promo_positions' => is_array($body['promo_positions'] ?? null) ? $body['promo_positions'] : [1800],
            'ad_positions' => is_array($body['ad_positions'] ?? null) ? $body['ad_positions'] : [],
            'talk_positions' => is_array($body['talk_positions'] ?? null) ? $body['talk_positions'] : [],
            'music_category_id' => Types::intOrNull($body['music_category_id'] ?? null),
            'algorithm' => Types::stringOrNull($body['algorithm'] ?? null, true),
        ];

        $slotsData = $this->generator->generate($goals);

        // Create a completely normal wheel record -- nothing locked or special.
        $wheel = new StationClockWheel($station);
        $wheel->name = $name;

        $this->em->persist($wheel);

        $this->slotWriter->replaceWheelSlots($wheel, $slotsData, false);

        $this->em->flush();

        return $response->withJson([
            'success' => true,
            'id' => $wheel->id,
            'name' => $wheel->name,
            'slots_generated' => count($slotsData),
        ]);
    }
}
