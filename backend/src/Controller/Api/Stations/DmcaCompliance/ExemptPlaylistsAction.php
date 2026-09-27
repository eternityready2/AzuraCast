<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\DmcaCompliance;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * Read-only summary of what the DMCA performance complement does NOT apply to.
 *
 * The limits govern sound recordings, so a spoken-word programme is exempt. That
 * exemption is decided in two places -- each file's media type, and the
 * playlist's "spoken-word programming" flag -- and neither is visible from the
 * DMCA page itself. This lists both so the operator can see, while looking at
 * the limits, exactly which programming is outside them and why.
 */
final class ExemptPlaylistsAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    /** @param array<string, string> $params */
    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT sp.id,
                       sp.name,
                       sp.is_programme,
                       sp.is_enabled,
                       COUNT(spm.id) AS total_files,
                       SUM(CASE WHEN sm.type = 'music' THEN 1 ELSE 0 END) AS music_files
                FROM station_playlists sp
                LEFT JOIN station_playlist_media spm ON spm.playlist_id = sp.id
                LEFT JOIN station_media sm ON sm.id = spm.media_id
                WHERE sp.station_id = :stationId
                GROUP BY sp.id, sp.name, sp.is_programme, sp.is_enabled
                ORDER BY sp.name ASC
            SQL,
            ['stationId' => $station->id]
        );

        $exempt = [];
        foreach ($rows as $row) {
            $total = (int)$row['total_files'];
            $music = (int)$row['music_files'];
            $flagged = (bool)$row['is_programme'];
            $nonMusic = $total - $music;

            if (!$flagged && 0 === $nonMusic) {
                continue;
            }

            $exempt[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'is_enabled' => (bool)$row['is_enabled'],
                'is_programme' => $flagged,
                'total_files' => $total,
                'non_music_files' => $nonMusic,
                // Fully exempt, or only some of its files? A part-exempt music
                // playlist is usually a tagging mistake worth seeing.
                'scope' => match (true) {
                    $flagged => 'playlist',
                    0 === $music => 'all_files',
                    default => 'some_files',
                },
            ];
        }

        return $response->withJson([
            'exempt' => $exempt,
        ]);
    }
}
