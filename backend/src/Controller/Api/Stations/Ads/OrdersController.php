<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Ads;

use App\Controller\Api\AbstractApiCrudController;
use App\Entity\AdAdvertiser;
use App\Entity\AdOrder;
use App\Entity\Api\Error;
use App\Entity\Api\Status;
use App\Entity\StationMedia;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\Ads\AdOrderPlayoutSync;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Sold airtime. Saving an order also re-points the playout behind it, so what is on the
 * air always matches what was sold.
 *
 * @extends AbstractApiCrudController<AdOrder>
 */
#[
    OA\Get(
        path: '/station/{station_id}/ads/orders',
        operationId: 'getAdOrders',
        summary: 'List sold airtime for this station.',
        tags: [OpenApi::TAG_STATIONS],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: AdOrder::class))
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Post(
        path: '/station/{station_id}/ads/orders',
        operationId: 'addAdOrder',
        summary: 'Book airtime.',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: AdOrder::class)),
        tags: [OpenApi::TAG_STATIONS],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(content: new OA\JsonContent(ref: AdOrder::class)),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Put(
        path: '/station/{station_id}/ads/order/{id}',
        operationId: 'editAdOrder',
        summary: 'Change a booking.',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: AdOrder::class)),
        tags: [OpenApi::TAG_STATIONS],
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
        path: '/station/{station_id}/ads/order/{id}',
        operationId: 'deleteAdOrder',
        summary: 'Delete a booking and take it off the air.',
        tags: [OpenApi::TAG_STATIONS],
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
final class OrdersController extends AbstractApiCrudController
{
    protected string $entityClass = AdOrder::class;
    protected string $resourceRouteName = 'api:stations:ads:order';

    public function __construct(
        Serializer $serializer,
        ValidatorInterface $validator,
        private readonly AdOrderPlayoutSync $playoutSync,
    ) {
        parent::__construct($serializer, $validator);
    }

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $query = $this->em->createQuery(
            <<<'DQL'
                SELECT o, a FROM App\Entity\AdOrder o
                JOIN o.advertiser a
                WHERE o.station = :station
                ORDER BY o.starts_at DESC, o.id DESC
            DQL
        )->setParameter('station', $request->getStation());

        return $this->listPaginatedFromQuery($request, $response, $query);
    }

    protected function createRecord(ServerRequest $request, array $data): object
    {
        $station = $request->getStation();

        $advertiserId = (int)($data['advertiser_id'] ?? 0);
        $advertiser = $this->em->find(AdAdvertiser::class, $advertiserId);

        if (null === $advertiser || $advertiser->station->id !== $station->id) {
            throw new InvalidArgumentException('Choose which advertiser this booking is for.');
        }

        $record = new AdOrder($station, $advertiser);
        $this->applySpotFile($request, $record, $data);
        $record = $this->editRecord($this->writableFields($data), $record);

        $this->playoutSync->sync($record);

        return $record;
    }

    public function editAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $record = $this->getRecord($request, $params);

        if (!$record instanceof AdOrder) {
            return $response->withStatus(404)->withJson(Error::notFound());
        }

        $data = (array)$request->getParsedBody();
        $this->applySpotFile($request, $record, $data);
        $record = $this->editRecord($this->writableFields($data), $record);

        // The sold terms just changed, so the thing delivering them has to change with it.
        $this->playoutSync->sync($record);

        return $response->withJson(Status::updated());
    }

    protected function getRecord(ServerRequest $request, array $params): ?object
    {
        $record = parent::getRecord($request, $params);

        if ($record instanceof AdOrder && $record->station->id !== $request->getStation()->id) {
            return null;
        }

        return $record;
    }

    /**
     * The stored columns alone cannot tell the page who bought it or whether it is
     * actually airing, so the row carries those answers with it.
     */
    protected function viewRecord(object $record, ServerRequest $request): mixed
    {
        /** @var AdOrder $record */
        $row = parent::viewRecord($record, $request);

        if (is_array($row)) {
            $row['advertiser_id'] = $record->advertiser->id;
            $row['advertiser_name'] = $record->advertiser->name;
            $row['media_id'] = $record->media?->id;
            $row['media_title'] = $record->getMediaTitle();
            $row['playlist_id'] = $record->playlist?->id;
            $row['spots_per_day_effective'] = $record->getEffectiveSpotsPerDay();
            $row['is_on_air'] = $record->isOnAir();
            $row['has_creative'] = $record->hasCreative();
        }

        return $row;
    }

    /**
     * Point the order at its uploaded spot. `media_id` comes from the station media library,
     * so it is checked against this station before being believed.
     */
    private function applySpotFile(ServerRequest $request, AdOrder $record, array $data): void
    {
        if (!array_key_exists('media_id', $data)) {
            return;
        }

        $mediaId = $data['media_id'];

        if (null === $mediaId || '' === $mediaId) {
            $record->media = null;
            return;
        }

        $media = $this->em->find(StationMedia::class, (int)$mediaId);
        $storageLocation = $request->getStation()->media_storage_location;

        if (null === $media || $media->storage_location->id !== $storageLocation->id) {
            throw new InvalidArgumentException('That audio file is not in this station\'s library.');
        }

        $record->media = $media;
    }

    /**
     * Drop the fields the serializer must not try to write back: relations handled above,
     * and the flattened read-only columns the list view gets for free.
     */
    private function writableFields(array $data): array
    {
        unset(
            $data['advertiser_id'],
            $data['advertiser_name'],
            $data['media_id'],
            $data['media_title'],
            $data['playlist_id'],
            $data['spots_per_day_effective'],
            $data['is_on_air'],
            $data['has_creative'],
            $data['id'],
            $data['created_at'],
            $data['links'],
        );

        return $data;
    }

    protected function deleteRecord(object $record): void
    {
        if ($record instanceof AdOrder) {
            // Take it off the air before the booking disappears, or the playlist is orphaned
            // and keeps airing a spot nobody is paying for.
            $this->playoutSync->removePlaylist($record);
        }

        parent::deleteRecord($record);
    }
}
