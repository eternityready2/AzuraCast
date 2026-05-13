<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiNews;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\AiNewsGenerator;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[
    OA\Get(
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
    )
]
final class GetAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

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
            'ai_news_source_urls' => $backendConfig->ai_news_source_urls,
            'ai_news_active_hours' => $backendConfig->ai_news_active_hours,
            'ai_news_voice_model_path' => $backendConfig->ai_news_voice_model_path,
            'ai_news_last_generation_status' => $backendConfig->ai_news_last_generation_status,
            'ai_news_last_generation_time' => $backendConfig->ai_news_last_generation_time,
            'ai_news_last_error' => $backendConfig->ai_news_last_error,
            'dashboard' => $this->buildDashboardPayload($station),
        ]);
    }

    public static function buildDashboardPayload(\App\Entity\Station $station): array
    {
        $backendConfig = $station->backend_config;
        $bulletinPath = $station->getRadioTempDir() . '/' . AiNewsGenerator::OUTPUT_FILENAME;
        $fileExists = file_exists($bulletinPath);
        $latestBulletin = is_array($backendConfig->ai_news_latest_bulletin)
            ? $backendConfig->ai_news_latest_bulletin
            : [];

        $fileInfo = null;
        if ($fileExists) {
            $fileInfo = [
                'exists' => true,
                'size' => filesize($bulletinPath),
                'modified_at' => gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($bulletinPath)),
            ];
        }

        return [
            'latest_bulletin' => [
                'generated_at' => $latestBulletin['generated_at'] ?? null,
                'story_count' => $latestBulletin['story_count'] ?? null,
                'source_urls' => $latestBulletin['source_urls'] ?? [],
                'elapsed_seconds' => $latestBulletin['elapsed_seconds'] ?? null,
                'output_filename' => $latestBulletin['output_filename'] ?? null,
                'headline_preview' => $latestBulletin['headline_preview'] ?? [],
            ],
            'file_info' => $fileInfo,
            'next_bulletin_time' => self::computeNextBulletinTime($backendConfig->ai_news_active_hours, $station),
            'current_time_station' => (new \DateTimeImmutable('now', $station->getTimezoneObject()))->format(DATE_ATOM),
            'tts_engine' => 'piper',
            'audio_available' => $fileExists,
            'bulletin_url' => '/api/station/' . $station->id . '/ai-news/bulletin',
        ];
    }

    private static function computeNextBulletinTime(?string $activeHours, \App\Entity\Station $station): ?string
    {
        if (null === $activeHours || '' === trim($activeHours)) {
            return null;
        }

        $activeHours = trim($activeHours);
        $now = new \DateTimeImmutable('now', $station->getTimezoneObject());
        $currentHour = (int) $now->format('G');
        $currentMinute = (int) $now->format('i');
        $nowMinutes = $currentHour * 60 + $currentMinute;

        if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $activeHours, $matches)) {
            $startHour = (int) $matches[1];
            $startMinute = (int) $matches[2];
            $endHour = (int) $matches[3];
            $endMinute = (int) $matches[4];
            $startMinutes = $startHour * 60 + $startMinute;
            $endMinutes = $endHour * 60 + $endMinute;

            if ($startMinutes <= $endMinutes) {
                if ($nowMinutes < $startMinutes) {
                    return $now->setTime($startHour, $startMinute)->format(DATE_ATOM);
                }

                if ($nowMinutes >= $endMinutes) {
                    return $now->modify('+1 day')->setTime($startHour, $startMinute)->format(DATE_ATOM);
                }

                return $now->modify('+1 hour')->setTime((int) $now->modify('+1 hour')->format('G'), 0)->format(DATE_ATOM);
            }

            if ($nowMinutes >= $startMinutes || $nowMinutes < $endMinutes) {
                return $now->modify('+1 hour')->setTime((int) $now->modify('+1 hour')->format('G'), 0)->format(DATE_ATOM);
            }

            return $now->setTime($startHour, $startMinute)->format(DATE_ATOM);
        }

        if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $activeHours, $matches)) {
            $startHour = (int) $matches[1];
            $endHour = (int) $matches[2];

            if ($startHour <= $endHour) {
                if ($currentHour < $startHour) {
                    return $now->setTime($startHour, 0)->format(DATE_ATOM);
                }

                if ($currentHour >= $endHour) {
                    return $now->modify('+1 day')->setTime($startHour, 0)->format(DATE_ATOM);
                }

                return $now->modify('+1 hour')->setTime((int) $now->modify('+1 hour')->format('G'), 0)->format(DATE_ATOM);
            }

            if ($currentHour >= $startHour || $currentHour < $endHour) {
                return $now->modify('+1 hour')->setTime((int) $now->modify('+1 hour')->format('G'), 0)->format(DATE_ATOM);
            }

            return $now->setTime($startHour, 0)->format(DATE_ATOM);
        }

        return null;
    }
}
