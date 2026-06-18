<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\HolidayOverrides;

use App\Controller\Api\Stations\AbstractStationApiCrudController;
use App\Entity\StationClockWheel;
use App\Entity\StationHolidayOverride;
use App\Entity\StationPlaylist;
use App\Exception\ValidationException;
use App\OpenApi;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @extends AbstractStationApiCrudController<StationHolidayOverride>
 */
#[
    OA\Get(
        path: '/station/{station_id}/holiday-overrides',
        operationId: 'getHolidayOverrides',
        summary: 'List holiday programming overrides.',
        tags: [OpenApi::TAG_STATIONS],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [new OA\Response\Success()]
    ),
    OA\Post(
        path: '/station/{station_id}/holiday-overrides',
        operationId: 'addHolidayOverride',
        summary: 'Create a holiday override.',
        tags: [OpenApi::TAG_STATIONS],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [new OA\Response\Success()]
    )
]
final class HolidayOverridesController extends AbstractStationApiCrudController
{
    protected string $entityClass = StationHolidayOverride::class;
    protected string $resourceRouteName = 'api:stations:holiday-override';

    public function __construct(
        Serializer $serializer,
        ValidatorInterface $validator,
    ) {
        parent::__construct($serializer, $validator);
    }

    protected function createRecord(\App\Http\ServerRequest $request, array $data): object
    {
        $station = $request->getStation();
        $dateRaw = $data['override_date'] ?? null;
        if (!is_string($dateRaw) || $dateRaw === '') {
            throw new InvalidArgumentException('override_date is required.');
        }

        $tz = $station->getTimezoneObject();
        $date = CarbonImmutable::parse($dateRaw, $tz)->startOfDay()->toDateTimeImmutable();

        return $this->editRecord(
            $data,
            new StationHolidayOverride($station, $date),
        );
    }

    protected function editRecord(?array $data, ?object $record = null, array $context = []): object
    {
        if (null === $data) {
            throw new InvalidArgumentException('Could not parse input data.');
        }

        assert($record instanceof StationHolidayOverride || $record === null);
        $station = $record?->station;

        if (isset($data['override_date']) && $record instanceof StationHolidayOverride) {
            $tz = $record->station->getTimezoneObject();
            $record->override_date = CarbonImmutable::parse((string)$data['override_date'], $tz)
                ->startOfDay()
                ->toDateTimeImmutable();
            unset($data['override_date']);
        }

        if (array_key_exists('clock_wheel_id', $data) && $record instanceof StationHolidayOverride) {
            $wheelId = is_numeric($data['clock_wheel_id']) ? (int)$data['clock_wheel_id'] : null;
            $record->clock_wheel = null;
            if ($wheelId !== null && $wheelId > 0) {
                $wheel = $this->em->find(StationClockWheel::class, $wheelId);
                if ($wheel instanceof StationClockWheel && $wheel->station_id === $record->station_id) {
                    $record->clock_wheel = $wheel;
                }
            }
            unset($data['clock_wheel_id']);
        }

        if (array_key_exists('playlist_id', $data) && $record instanceof StationHolidayOverride) {
            $playlistId = is_numeric($data['playlist_id']) ? (int)$data['playlist_id'] : null;
            $record->playlist = null;
            if ($playlistId !== null && $playlistId > 0) {
                $playlist = $this->em->find(StationPlaylist::class, $playlistId);
                if ($playlist instanceof StationPlaylist && $playlist->station_id === $record->station_id) {
                    $record->playlist = $playlist;
                }
            }
            unset($data['playlist_id']);
        }

        /** @var StationHolidayOverride $record */
        $record = $this->fromArray($data, $record, $context);

        $errors = $this->validator->validate($record);
        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        $this->em->persist($record);
        $this->em->flush();
        $record->syncReadOnlyForeignKeys();

        return $record;
    }

    protected function viewRecord(object $record, \App\Http\ServerRequest $request): mixed
    {
        assert($record instanceof StationHolidayOverride);

        $return = $this->toArray($record);
        $return['override_date'] = $record->override_date->format('Y-m-d');

        return $return;
    }
}
