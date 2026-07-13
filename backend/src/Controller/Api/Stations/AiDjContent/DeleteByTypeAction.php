<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDjContent;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Post(
    path: '/station/{station_id}/ai-dj-content/delete-by-type',
    operationId: 'deleteByTypeStationAiDjContent',
    summary: 'Delete ALL AI DJ content items of a given type (category) for a station.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    requestBody: new OA\RequestBody(
        description: 'The content type to clear',
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                'type' => new OA\Property(description: 'Content type slug', type: 'string'),
            ],
            required: ['type']
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Success',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    'success' => new OA\Property(type: 'boolean'),
                    'deleted' => new OA\Property(type: 'integer'),
                ]
            )
        ),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class DeleteByTypeAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $body = $request->getParsedBody();

        $type = is_array($body) ? trim((string)($body['type'] ?? '')) : '';
        if ($type === '') {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'message' => 'Missing "type".',
            ]);
        }

        // Efficient bulk delete (no entity hydration) — handles large categories
        // like tens of thousands of Bible verses in a single query.
        $deleted = $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\AiDjContent c
                WHERE c.station = :station AND c.type = :type
            DQL
        )->setParameter('station', $station)
            ->setParameter('type', $type)
            ->execute();

        return $response->withJson([
            'success' => true,
            'deleted' => (int)$deleted,
        ]);
    }
}
