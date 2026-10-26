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
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[OA\Post(
    path: '/station/{station_id}/ai-dj-content',
    operationId: 'createStationAiDjContent',
    summary: 'Create a new AI DJ content item.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class PostAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $body = (array) $request->getParsedBody();

        $content = new AiDjContent($station);

        $content->type = $body['type'] ?? AiDjContent::TYPE_SONG_INTRO_TEMPLATE;
        $content->content = $body['content'] ?? '';
        $content->reference = $body['reference'] ?? null;
        $content->is_enabled = $body['is_enabled'] ?? true;
        $content->is_global = $body['is_global'] ?? false;

        $errors = $this->validator->validate($content);
        if (count($errors) > 0) {
            return $response->withStatus(422)->withJson([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => (array) $errors,
            ]);
        }

        $this->em->persist($content);

        // Creating content for a removed optional category restores its tab.
        $backendConfig = $station->backend_config;
        $removed = $backendConfig->ai_dj_removed_content_types;
        if (!AiDjContent::isRequiredType($content->type) && in_array($content->type, $removed, true)) {
            $backendConfig->ai_dj_removed_content_types = array_values(array_filter(
                $removed,
                static fn(string $slug): bool => $slug !== $content->type
            ));
            $station->backend_config = $backendConfig;
            $this->em->persist($station);
        }

        $this->em->flush();

        return $response->withJson($content->api())->withStatus(201);
    }
}
