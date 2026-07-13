<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj;

use App\Controller\SingleActionInterface;
use App\Entity\Repository\AiDjRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\Adapters;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Service\AiDjGenerator;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Station;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/ai-dj/{dj_id}/test',
    operationId: 'testStationAiDj',
    summary: 'Test generate AI DJ audio and queue it for playback.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'dj_id',
            description: 'AI DJ ID',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'integer')
        ),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class TestAction implements SingleActionInterface
{
    public function __construct(
        private readonly AiDjRepository $aiDjRepository,
        private readonly AiDjGenerator $aiDjGenerator,
        private readonly Adapters $adapters,
        private readonly ReloadableEntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $djId = (int)($params['dj_id'] ?? 0);

        $dj = $this->aiDjRepository->find($djId);

        if (null === $dj || $dj->getStationId() !== $station->id) {
            return $response->withStatus(404)->withJson([
                'error' => 'AI DJ not found.',
            ]);
        }

        // Use the real current/just-played song so the test reflects live
        // behavior instead of a generic "this song by this artist" placeholder.
        $current = $this->getCurrentPlayingSong($station);
        $clipPath = $this->aiDjGenerator->generateSongIntro(
            $dj,
            $current['artist'] ?? null,
            $current['title'] ?? null,
            $station
        );

        if (null === $clipPath) {
            return $response->withStatus(500)->withJson([
                'error' => 'Failed to generate test audio.',
            ]);
        }

        // Queue the clip for playback via Liquidsoap
        $backend = $this->adapters->getBackendAdapter($station);
        $queued = false;
        if ($backend instanceof Liquidsoap) {
            $track = sprintf('annotate:title="AI DJ Test",artist="%s":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $queued = true;
        }

        return $response->withJson([
            'success' => true,
            'clip_path' => basename($clipPath),
            'queued' => $queued,
        ]);
    }

    /**
     * Get the real current/just-played song for this station so the test names an
     * actual track. Returns null values if none found (falls back to placeholder).
     *
     * @return array{artist: ?string, title: ?string}|null
     */
    private function getCurrentPlayingSong(Station $station): ?array
    {
        try {
            $last = $this->em->createQuery(
                <<<'DQL'
                    SELECT sh FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.is_visible = 1
                    AND sh.media IS NOT NULL
                    AND sh.artist IS NOT NULL
                    AND sh.artist != :empty
                    ORDER BY sh.timestamp_start DESC
                DQL
            )->setParameter('station', $station)
                ->setParameter('empty', '')
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if ($last === null) {
                return null;
            }

            return [
                'artist' => $last->artist,
                'title' => $last->title,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
