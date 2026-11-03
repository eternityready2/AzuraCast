<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDjContent;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\AiDjContent;
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
        description: 'The content type to clear, optionally removing the category tab',
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                'type' => new OA\Property(description: 'Content type slug', type: 'string'),
                'library' => new OA\Property(
                    description: "Only delete from this library: 'shared' or a DJ id",
                    type: 'string'
                ),
                'remove_category' => new OA\Property(
                    description: 'When true, also hide/remove the category tab (not just wipe items)',
                    type: 'boolean'
                ),
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
                    'category_removed' => new OA\Property(type: 'boolean'),
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
        $removeCategory = is_array($body) && filter_var($body['remove_category'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($type === '') {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'message' => 'Missing "type".',
            ]);
        }

        if (AiDjContent::isRequiredType($type)) {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'message' => 'This content category is required for song playback and cannot be deleted.',
            ]);
        }

        // library: 'shared' or a DJ id limits the delete to that library;
        // removing the whole category clears every library.
        $library = is_array($body) && !$removeCategory ? ($body['library'] ?? null) : null;
        $libraryDql = match (true) {
            'shared' === $library => ' AND c.ai_djs IS EMPTY',
            is_numeric($library) => ' AND :dj MEMBER OF c.ai_djs',
            default => '',
        };

        // Efficient bulk delete (no entity hydration) — handles large categories
        // like tens of thousands of Bible verses in a single query.
        $query = $this->em->createQuery(
            'DELETE FROM App\Entity\AiDjContent c WHERE c.station = :station AND c.type = :type' . $libraryDql
        )->setParameter('station', $station)
            ->setParameter('type', $type);
        if (is_numeric($library)) {
            $query->setParameter('dj', (int) $library);
        }
        $deleted = $query->execute();

        $categoryRemoved = false;
        if ($removeCategory) {
            $backendConfig = $station->backend_config;
            $removed = $backendConfig->ai_dj_removed_content_types;
            if (!in_array($type, $removed, true)) {
                $removed[] = $type;
                $backendConfig->ai_dj_removed_content_types = $removed;
                $station->backend_config = $backendConfig;
                $this->em->persist($station);
                $this->em->flush();
            }
            $categoryRemoved = true;
        }

        return $response->withJson([
            'success' => true,
            'deleted' => (int)$deleted,
            'category_removed' => $categoryRemoved,
        ]);
    }
}
