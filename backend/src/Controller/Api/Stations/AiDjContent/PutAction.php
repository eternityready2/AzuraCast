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
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[OA\Put(
    path: '/station/{station_id}/ai-dj-content/{content_id}',
    operationId: 'updateStationAiDjContent',
    summary: 'Update an AI DJ content item.',
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
final class PutAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly AiDjContentRepository $contentRepo,
        private readonly ValidatorInterface $validator,
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

        $body = (array) $request->getParsedBody();

        if (isset($body['type'])) {
            $content->type = $body['type'];
        }
        if (isset($body['content'])) {
            $content->content = $body['content'];
        }
        if (array_key_exists('reference', $body)) {
            $content->reference = $body['reference'];
        }
        if (isset($body['is_enabled'])) {
            $content->is_enabled = (bool) $body['is_enabled'];
        }
        if (isset($body['is_global'])) {
            $content->is_global = (bool) $body['is_global'];
        }

        $errors = $this->validator->validate($content);
        if (count($errors) > 0) {
            return $response->withStatus(422)->withJson([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => (array) $errors,
            ]);
        }

        $this->em->persist($content);
        $this->em->flush();

        return $response->withJson(Status::updated());
    }
}
