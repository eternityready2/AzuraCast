<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDjContent;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Error;
use App\Entity\Api\Status;
use App\Entity\Repository\AiDjContentRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Post(
    path: '/station/{station_id}/ai-dj-content/bulk-delete',
    operationId: 'bulkDeleteStationAiDjContent',
    summary: 'Bulk delete AI DJ content items.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    requestBody: new OA\RequestBody(
        description: 'List of content IDs to delete',
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                'ids' => new OA\Property(
                    description: 'Array of content IDs',
                    type: 'array',
                    items: new OA\Items(type: 'integer')
                ),
            ],
            required: ['ids']
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
final readonly class BulkDeleteAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private AiDjContentRepository $contentRepo,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $body = $request->getParsedBody();

        if (!is_array($body) || !isset($body['ids']) || !is_array($body['ids'])) {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'message' => 'Invalid request body. Expected {"ids": [1, 2, 3]}',
            ]);
        }

        $ids = array_filter($body['ids'], fn($id) => is_int($id) || is_numeric($id));
        if ([] === $ids) {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'message' => 'No valid IDs provided',
            ]);
        }

        $deleted = 0;
        foreach ($ids as $contentId) {
            $content = $this->contentRepo->findForStation((int) $contentId, $station);
            if (null !== $content) {
                $this->em->remove($content);
                $deleted++;
            }
        }

        $this->em->flush();

        return $response->withJson([
            'success' => true,
            'deleted' => $deleted,
        ]);
    }
}
