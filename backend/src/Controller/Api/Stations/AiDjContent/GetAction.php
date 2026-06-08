<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDjContent;

use App\Controller\SingleActionInterface;
use App\Entity\Api\Error;
use App\Entity\Repository\AiDjContentRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/ai-dj-content/{content_id}',
    operationId: 'getStationAiDjContent',
    summary: 'Get a single AI DJ content item.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'content_id',
            description: 'Content ID',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'integer', format: 'int64')
        ),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final readonly class GetAction implements SingleActionInterface
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
        /** @var string $contentId */
        $contentId = $params['content_id'];

        $station = $request->getStation();
        $content = $this->contentRepo->findForStation($contentId, $station);

        if (null === $content) {
            return $response->withStatus(404)->withJson(Error::notFound());
        }

        return $response->withJson($content);
    }
}
