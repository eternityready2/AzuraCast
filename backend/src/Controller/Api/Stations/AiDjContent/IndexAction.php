<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDjContent;

use App\Controller\SingleActionInterface;
use App\Entity\AiDjContent;
use App\Entity\Repository\AiDjContentRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/ai-dj-content',
    operationId: 'getStationAiDjContentList',
    summary: 'List all AI DJ content for a station.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'type',
            description: 'Filter by content type (built-in or custom)',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'string')
        ),
        new OA\Parameter(
            name: 'enabled',
            description: 'Filter by enabled status (1 or 0)',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'integer', enum: [0, 1])
        ),
        new OA\Parameter(
            name: 'global',
            description: 'Filter by global status (1 or 0)',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'integer', enum: [0, 1])
        ),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final readonly class IndexAction implements SingleActionInterface
{
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
        $queryParams = $request->getQueryParams();

        $type = $queryParams['type'] ?? null;
        $enabled = isset($queryParams['enabled']) ? (bool) $queryParams['enabled'] : null;
        $global = isset($queryParams['global']) ? (bool) $queryParams['global'] : null;

        $content = $this->contentRepo->findByStation((int) $station->id);
        if (null !== $type) {
            $content = array_filter($content, fn(AiDjContent $item) => $item->type === $type);
        }

        if (null !== $enabled) {
            $content = array_filter($content, fn(AiDjContent $item) => $item->is_enabled === $enabled);
        }

        if (null !== $global) {
            $content = array_filter($content, fn(AiDjContent $item) => $item->is_global === $global);
        }

        $result = array_map(fn(AiDjContent $item) => [
            'id' => $item->id,
            'type' => $item->type,
            'content' => $item->content,
            'reference' => $item->reference,
            'is_enabled' => $item->is_enabled,
            'is_global' => $item->is_global,
        ], array_values($content));

        return $response->withJson($result);
    }
}
