<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiNews;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Error;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\AiNewsGenerator;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Throwable;

#[
    OA\Post(
        path: '/station/{station_id}/ai-news/test',
        operationId: 'testStationAiNews',
        summary: 'Trigger a test AI news bulletin generation.',
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
    )
]
final class TestPostAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private AiNewsGenerator $generator,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $this->em->refetch($request->getStation());

        try {
            $this->generator->generate($station, true);
            $station = $this->em->refetch($station);
            $backendConfig = $station->backend_config;

            return $response->withJson([
                'success' => true,
                'message' => __('AI news bulletin generated successfully.'),
                'ai_news_last_generation_status' => $backendConfig->ai_news_last_generation_status,
                'ai_news_last_generation_time' => $backendConfig->ai_news_last_generation_time,
                'ai_news_last_error' => $backendConfig->ai_news_last_error,
                'dashboard' => GetAction::buildDashboardPayload($station),
            ]);
        } catch (Throwable $e) {
            return $response->withStatus(500)->withJson(Error::fromException($e));
        }
    }
}
