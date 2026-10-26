<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiNews;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\AiNewsDashboardService;
use App\Service\AiNewsGenerator;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/ai-news',
    operationId: 'getStationAiNewsSettings',
    summary: 'Get current AI news bulletin settings and generation status.',
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
final class GetAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly AiNewsDashboardService $dashboardService,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $this->em->refetch($request->getStation());
        $backendConfig = $station->backend_config;

        return $response->withJson([
            'ai_news_enabled' => $backendConfig->ai_news_enabled,
            'ai_news_intro' => $backendConfig->ai_news_intro,
            'ai_news_reporter_name' => $backendConfig->ai_news_reporter_name,
            'ai_news_source_urls' => $backendConfig->ai_news_source_urls,
            'ai_news_story_count' => $backendConfig->ai_news_story_count,
            'ai_news_active_hours' => $backendConfig->ai_news_active_hours,
            'ai_news_active_days' => $backendConfig->ai_news_active_days,
            'ai_news_top_of_hour' => $backendConfig->ai_news_top_of_hour,
            'ai_news_bottom_of_hour' => $backendConfig->ai_news_bottom_of_hour,
            'ai_news_voice_model_path' => $backendConfig->ai_news_voice_model_path,
            'ai_news_outro' => $backendConfig->ai_news_outro,
            'ai_news_last_generation_status' => $station->ai_news_last_generation_status,
            'ai_news_last_generation_time' => $station->ai_news_last_generation_time?->format('Y-m-d\TH:i:s\Z'),
            'ai_news_last_error' => $station->ai_news_last_error,
            'dashboard' => $this->dashboardService->buildDashboardPayload($station),
            'voice_options' => AiNewsGenerator::getAvailableVoiceModels(),
        ]);
    }
}
