<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * Real average lengths of the station's library, so the wheel editor can size
 * each entry from what will actually air instead of a fixed guess, and the
 * Top-of-Hour ID timing the hour has to fit around. Read-only.
 */
#[OA\Get(
    path: '/station/{station_id}/clock-wheels/slot-lengths',
    operationId: 'getClockWheelSlotLengths',
    summary: 'Average media lengths by playlist, category and type, plus the Top-of-Hour ID timing.',
    tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OA\Response\Success(),
        new OA\Response\AccessDenied(),
        new OA\Response\GenericError(),
    ]
)]
final class SlotLengthsAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly TopOfHourClock $topOfHourClock,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $conn = $this->em->getConnection();

        // Every playlist an entry can play from: its name, average length and
        // what it mostly holds (so a playlist entry gets the right type). Smart
        // blocks build their list on the fly, so they have no fixed media.
        $byPlaylist = [];
        foreach (
            $conn->fetchAllAssociative(
                'SELECT p.id, p.name, p.is_smart_block, p.is_jingle,
                    AVG(m.length) AS avg_seconds, COUNT(m.id) AS items,
                    (SELECT m2.type FROM station_playlist_media pm2
                        JOIN station_media m2 ON m2.id = pm2.media_id
                        WHERE pm2.playlist_id = p.id
                        GROUP BY m2.type ORDER BY COUNT(*) DESC LIMIT 1) AS main_type
                FROM station_playlists p
                LEFT JOIN station_playlist_media pm ON pm.playlist_id = p.id
                LEFT JOIN station_media m ON m.id = pm.media_id AND m.length > 0
                WHERE p.station_id = ? AND p.is_enabled = 1 AND p.source = ?
                GROUP BY p.id, p.name, p.is_smart_block, p.is_jingle
                ORDER BY p.name',
                [$station->id, 'songs']
            ) as $row
        ) {
            $byPlaylist[(int)$row['id']] = self::stat($row) + [
                'name' => trim((string)$row['name']),
                'is_smart_block' => (bool)$row['is_smart_block'],
                'main_type' => $row['main_type'] ?? ((bool)$row['is_jingle'] ? 'promo' : 'music'),
            ];
        }

        $byCategory = [];
        foreach (
            $conn->fetchAllAssociative(
                'SELECT c.id, AVG(m.length) AS avg_seconds, COUNT(*) AS items
                FROM station_media_categories c
                JOIN station_media m ON m.category_id = c.id
                WHERE c.station_id = ? AND m.length > 0
                GROUP BY c.id',
                [$station->id]
            ) as $row
        ) {
            $byCategory[(int)$row['id']] = self::stat($row);
        }

        $byType = [];
        foreach (
            $conn->fetchAllAssociative(
                'SELECT m.type AS id, AVG(m.length) AS avg_seconds, COUNT(*) AS items
                FROM station_media m
                WHERE m.length > 0 AND m.id IN (
                    SELECT pm.media_id FROM station_playlist_media pm
                    JOIN station_playlists p ON p.id = pm.playlist_id
                    WHERE p.station_id = ?
                )
                GROUP BY m.type',
                [$station->id]
            ) as $row
        ) {
            $byType[(string)$row['id']] = self::stat($row);
        }

        $idSeconds = (float)($conn->fetchOne(
            'SELECT AVG(m.length) FROM station_media m
            JOIN station_playlist_media pm ON pm.media_id = m.id
            JOIN station_playlists p ON p.id = pm.playlist_id
            WHERE p.station_id = ? AND m.type IN (?, ?) AND m.length > 0',
            [$station->id, 'id', 'legal_id']
        ) ?: 38.0);

        return $response->withJson([
            'top_of_hour_id_enabled' => $this->topOfHourClock->isEnabled($station),
            'id_start_second' => 59 * 60 + $this->topOfHourClock->getIdStartSecond($station),
            'id_seconds' => (int)round($idSeconds),
            'playlists' => (object)$byPlaylist,
            'categories' => (object)$byCategory,
            'types' => (object)$byType,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{avg_seconds: int, items: int}
     */
    private static function stat(array $row): array
    {
        return [
            'avg_seconds' => (int)round((float)($row['avg_seconds'] ?? 0)),
            'items' => (int)$row['items'],
        ];
    }
}
