<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\Quota;
use App\Utilities\Time;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/overview-stats',
    operationId: 'getStationOverviewStats',
    summary: 'Get summary stats for the station Overview page (24h reach, total listening hours, storage).',
    tags: [OpenApi::TAG_STATIONS_REPORTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class OverviewStatsAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $now = Time::nowUtc();
        $since24h = $now->subHours(24);

        // listener.timestamp_start / timestamp_end are DATETIME(6), so bind
        // DateTimeImmutable values (not Unix ints) — same pattern as ListenerRepository.
        $unique24h = (int)$this->em->getConnection()->fetchOne(
            <<<'SQL'
                SELECT COUNT(DISTINCT listener_hash)
                FROM listener
                WHERE station_id = :station_id
                AND (timestamp_end IS NULL OR timestamp_end >= :since)
                AND timestamp_start <= :now
            SQL,
            [
                'station_id' => $station->id,
                'since' => $since24h,
                'now' => $now,
            ]
        );

        $tlh = [
            '24h' => $this->getTotalListeningHours($station->id, $now, 24),
            '7d' => $this->getTotalListeningHours($station->id, $now, 24 * 7),
            '30d' => $this->getTotalListeningHours($station->id, $now, 24 * 30),
        ];

        $storageLocation = $station->media_storage_location;
        $usedBytes = $storageLocation->storageUsedBytes;
        $availableBytes = $storageLocation->storageAvailableBytes;

        $freeLabel = '';
        if ($availableBytes instanceof BigInteger) {
            $freeBytes = $availableBytes->minus($usedBytes);
            if ($freeBytes->isNegative()) {
                $freeBytes = BigInteger::zero();
            }
            $freeLabel = Quota::getReadableSize($freeBytes);
        }

        $storage = [
            'used' => $storageLocation->storageUsed,
            'free' => $freeLabel,
            'quota' => $storageLocation->storageQuota,
            'percent' => $storageLocation->getStorageUsePercentage(),
        ];

        return $response->withJson([
            'unique_listeners_24h' => $unique24h,
            'total_listening_hours' => $tlh,
            'storage' => $storage,
        ]);
    }

    private function getTotalListeningHours(int $stationId, DateTimeImmutable $now, int $hoursBack): float
    {
        $since = $now->modify("-{$hoursBack} hours");

        // Sum of connected seconds across all listener sessions overlapping
        // this window, clamped to the window boundaries. Ongoing sessions
        // (timestamp_end IS NULL) count up to "now".
        // Use TIMESTAMPDIFF so DATETIME columns yield seconds (not raw datetime math).
        $totalSeconds = (float)$this->em->getConnection()->fetchOne(
            <<<'SQL'
                SELECT COALESCE(SUM(
                    GREATEST(
                        0,
                        TIMESTAMPDIFF(
                            SECOND,
                            GREATEST(timestamp_start, :since),
                            LEAST(COALESCE(timestamp_end, :now), :now)
                        )
                    )
                ), 0)
                FROM listener
                WHERE station_id = :station_id
                AND (timestamp_end IS NULL OR timestamp_end >= :since)
                AND timestamp_start <= :now
            SQL,
            [
                'station_id' => $stationId,
                'since' => $since,
                'now' => $now,
            ]
        );

        return round($totalSeconds / 3600, 1);
    }
}
