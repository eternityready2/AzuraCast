<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Controller\Api\Stations\AbstractStationApiCrudController;
use App\Controller\Api\Traits\AcceptsDateRange;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelEvent;
use App\Entity\StationSchedule;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * CRUD for Clock Wheel scheduling events + a FullCalendar-compatible schedule feed.
 *
 * @extends AbstractStationApiCrudController<StationClockWheelEvent>
 */
#[
    OA\Get(
        path: '/station/{station_id}/clock-wheel-events',
        operationId: 'getClockWheelEvents',
        summary: 'List all clock wheel schedule events for a station.',
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: StationClockWheelEvent::class)
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Post(
        path: '/station/{station_id}/clock-wheel-events',
        operationId: 'addClockWheelEvent',
        summary: 'Create a new clock wheel schedule event.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: StationClockWheelEvent::class)
        ),
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(ref: StationClockWheelEvent::class)
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Get(
        path: '/station/{station_id}/clock-wheel-event/{id}',
        operationId: 'getClockWheelEvent',
        summary: 'Retrieve a single clock wheel schedule event.',
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(ref: StationClockWheelEvent::class)
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Put(
        path: '/station/{station_id}/clock-wheel-event/{id}',
        operationId: 'editClockWheelEvent',
        summary: 'Update an existing clock wheel schedule event.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: StationClockWheelEvent::class)
        ),
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Delete(
        path: '/station/{station_id}/clock-wheel-event/{id}',
        operationId: 'deleteClockWheelEvent',
        summary: 'Delete a clock wheel schedule event.',
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final class ClockWheelEventsController extends AbstractStationApiCrudController
{
    use AcceptsDateRange;

    protected string $entityClass = StationClockWheelEvent::class;
    protected string $resourceRouteName = 'api:stations:clock-wheel-event';

    // ------------------------------------------------------------------
    // List (override — filter via JOIN through clock_wheel → station)
    // ------------------------------------------------------------------

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();

        $query = $this->em->createQuery(
            'SELECT e
             FROM App\Entity\StationClockWheelEvent e
             JOIN e.clock_wheel cw
             WHERE cw.station = :station'
        )->setParameter('station', $station);

        return $this->listPaginatedFromQuery($request, $response, $query);
    }

    // ------------------------------------------------------------------
    // Single record lookup (override — security check via station)
    // ------------------------------------------------------------------

    protected function getRecord(ServerRequest $request, array $params): ?object
    {
        $station = $request->getStation();
        $id = (int)($params['id'] ?? 0);

        return $this->em->createQuery(
            'SELECT e
             FROM App\Entity\StationClockWheelEvent e
             JOIN e.clock_wheel cw
             WHERE e.id = :id AND cw.station = :station'
        )->setParameters(['id' => $id, 'station' => $station])
         ->getOneOrNullResult();
    }

    // ------------------------------------------------------------------
    // Create (override — resolve clock_wheel_id → entity)
    // ------------------------------------------------------------------

    protected function createRecord(ServerRequest $request, array $data): object
    {
        $station = $request->getStation();
        $clockWheel = $this->resolveClockWheel($data, $station->id);

        unset($data['clock_wheel_id']);

        $event = new StationClockWheelEvent($clockWheel);
        return $this->editRecord($data, $event);
    }

    // ------------------------------------------------------------------
    // Edit (override — allow updating clock_wheel_id)
    // ------------------------------------------------------------------

    protected function editRecord(?array $data, ?object $record = null, array $context = []): object
    {
        if (null === $data) {
            throw new InvalidArgumentException('Could not parse input data.');
        }

        if (isset($data['clock_wheel_id']) && $record instanceof StationClockWheelEvent) {
            $stationId = $record->clock_wheel->station->id;
            $record->clock_wheel = $this->resolveClockWheel($data, $stationId);
        }

        unset($data['clock_wheel_id']);

        return parent::editRecord($data, $record, $context);
    }

    // ------------------------------------------------------------------
    // FullCalendar schedule feed
    // ------------------------------------------------------------------

    public function scheduleAction(
        ServerRequest $request,
        Response $response
    ): ResponseInterface {
        $station = $request->getStation();
        $tz = $station->getTimezoneObject();

        $dateRange = $this->getDateRange($request, $tz);

        /** @var StationClockWheelEvent[] $events */
        $events = $this->em->createQuery(
            'SELECT e, cw
             FROM App\Entity\StationClockWheelEvent e
             JOIN e.clock_wheel cw
             WHERE cw.station = :station AND cw.is_active = 1'
        )->setParameter('station', $station)->getResult();

        $calendarEvents = [];

        $loopStart = $dateRange->start->subDay()->startOf('day');
        $loopEnd   = $dateRange->end->endOf('day');

        foreach ($events as $event) {
            $days = $event->getDaysArray();

            $i = $loopStart;
            while ($i <= $loopEnd) {
                $dow = $i->dayOfWeekIso; // 1=Mon, 7=Sun

                if (in_array($dow, $days, true)) {
                    $rowStart = StationSchedule::getDateTime($event->start_time, $tz, $i->toDateTimeImmutable());
                    $rowEnd   = StationSchedule::getDateTime($event->end_time, $tz, $i->toDateTimeImmutable());

                    // Handle overnight events
                    if ($rowEnd <= $rowStart) {
                        $rowEnd = $rowEnd->addDay();
                    }

                    if ($rowStart <= $dateRange->end && $rowEnd >= $dateRange->start) {
                        $calendarEvents[] = [
                            'id'              => $event->id . '_' . $rowStart->getTimestamp(),
                            'title'           => $event->clock_wheel->name,
                            'start'           => $rowStart->toIso8601String(),
                            'end'             => $rowEnd->toIso8601String(),
                            'backgroundColor' => $event->clock_wheel->color,
                            'borderColor'     => $event->clock_wheel->color,
                            'extendedProps'   => [
                                'event_id'      => $event->id,
                                'clock_wheel_id' => $event->clock_wheel_id,
                            ],
                        ];
                    }
                }

                $i = $i->addDay();
            }
        }

        return $response->withJson($calendarEvents);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function resolveClockWheel(array $data, int $stationId): StationClockWheel
    {
        $clockWheelId = (int)($data['clock_wheel_id'] ?? 0);

        /** @var StationClockWheel|null $clockWheel */
        $clockWheel = $this->em->getRepository(StationClockWheel::class)->findOneBy([
            'id'         => $clockWheelId,
            'station_id' => $stationId,
        ]);

        if (null === $clockWheel) {
            throw new InvalidArgumentException('Clock wheel not found or does not belong to this station.');
        }

        return $clockWheel;
    }
}
