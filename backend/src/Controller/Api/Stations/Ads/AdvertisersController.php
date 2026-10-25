<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Ads;

use App\Controller\Api\AbstractApiCrudController;
use App\Entity\AdAdvertiser;
use App\Entity\Api\Error;
use App\Entity\Api\Status;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * The people paying for airtime. Thin CRUD; the money and scheduling live on their orders.
 *
 * @extends AbstractApiCrudController<AdAdvertiser>
 */
#[
    OA\Get(
        path: '/station/{station_id}/ads/advertisers',
        operationId: 'getAdvertisers',
        summary: 'List everyone buying airtime on this station.',
        tags: [OpenApi::TAG_STATIONS],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: AdAdvertiser::class))
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Post(
        path: '/station/{station_id}/ads/advertisers',
        operationId: 'addAdvertiser',
        summary: 'Add an advertiser.',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: AdAdvertiser::class)),
        tags: [OpenApi::TAG_STATIONS],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(content: new OA\JsonContent(ref: AdAdvertiser::class)),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Put(
        path: '/station/{station_id}/ads/advertiser/{id}',
        operationId: 'editAdvertiser',
        summary: 'Update an advertiser.',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: AdAdvertiser::class)),
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
        path: '/station/{station_id}/ads/advertiser/{id}',
        operationId: 'deleteAdvertiser',
        summary: 'Remove an advertiser.',
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
final class AdvertisersController extends AbstractApiCrudController
{
    protected string $entityClass = AdAdvertiser::class;
    protected string $resourceRouteName = 'api:stations:ads:advertiser';

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $query = $this->em->createQuery(
            <<<'DQL'
                SELECT a FROM App\Entity\AdAdvertiser a
                WHERE a.station = :station
                ORDER BY a.name ASC
            DQL
        )->setParameter('station', $request->getStation());

        return $this->listPaginatedFromQuery($request, $response, $query);
    }

    protected function createRecord(ServerRequest $request, array $data): object
    {
        $record = new AdAdvertiser($request->getStation());

        return $this->editRecord($data, $record);
    }

    protected function getRecord(ServerRequest $request, array $params): ?object
    {
        $record = parent::getRecord($request, $params);

        // Never let one station read or edit another station's customer list.
        if ($record instanceof AdAdvertiser && $record->station->id !== $request->getStation()->id) {
            return null;
        }

        return $record;
    }

    public function deleteAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $record = $this->getRecord($request, $params);

        if (!$record instanceof AdAdvertiser) {
            return $response->withStatus(404)->withJson(Error::notFound());
        }

        // Deleting the advertiser would cascade their whole booking history away, which is
        // not what anyone means by "remove this contact". Make them clear the orders first.
        if (!$record->orders->isEmpty()) {
            return $response->withStatus(400)->withJson(
                new Status(
                    false,
                    'This advertiser still has orders. Delete or end those first.'
                )
            );
        }

        $this->deleteRecord($record);

        return $response->withJson(Status::deleted());
    }
}
