<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\PlayoutControls;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Event\Radio\AnnotateNextSong;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\Adapters;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;

final class ManualPlaylistAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    private const int MAX_MANUAL_TRACKS = 500;

    public function __construct(
        private readonly StationPlaylistMediaRepository $playlistMediaRepo,
        private readonly Adapters $adapters,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $body = (array)$request->getParsedBody();
        $station = $request->getStation();
        $playlistId = (int)($body['playlist_id'] ?? 0);
        $mode = (string)($body['mode'] ?? 'queue_next');

        $playlist = $this->em->find(StationPlaylist::class, $playlistId);
        if (!$playlist instanceof StationPlaylist || $playlist->station->id !== $station->id) {
            throw new ValidationException(__('Playlist not found for this station.'));
        }

        if (!$playlist->is_enabled) {
            throw new ValidationException(__('Disabled playlists cannot be started manually. Enable the playlist first.'));
        }

        if (PlaylistSources::Songs !== $playlist->source) {
            throw new ValidationException(__('Manual playlist start currently supports song-based playlists only.'));
        }

        if (!in_array($mode, ['queue_next', 'play_now'], true)) {
            throw new ValidationException(__('Invalid manual playlist action.'));
        }

        $queue = $this->playlistMediaRepo->getQueue($playlist);
        if ([] === $queue) {
            throw new ValidationException(__('This playlist has no playable media.'));
        }

        if (count($queue) > self::MAX_MANUAL_TRACKS) {
            throw new ValidationException(
                sprintf(
                    __('This playlist has more than %d tracks, too many to queue at once. Schedule it from the playlist\'s Schedule tab instead.'),
                    self::MAX_MANUAL_TRACKS
                )
            );
        }

        $backend = $this->adapters->requireBackendAdapter($station);
        if (!$backend instanceof Liquidsoap) {
            throw new ValidationException(__('Manual playlist start requires Liquidsoap.'));
        }

        $queued = 0;
        foreach ($queue as $index => $queueItem) {
            $media = $this->em->find(StationMedia::class, $queueItem->media_id);
            if (!$media instanceof StationMedia) {
                continue;
            }

            $event = AnnotateNextSong::fromStationMedia($station, $media, true);
            $this->eventDispatcher->dispatch($event);

            $targetQueue = ('play_now' === $mode && 0 === $index)
                ? LiquidsoapQueues::Interrupting
                : LiquidsoapQueues::Requests;

            $backend->enqueue($station, $targetQueue, $event->buildAnnotations());
            $queued++;
        }

        if (0 === $queued) {
            throw new ValidationException(__('No playable media could be queued from this playlist.'));
        }

        if ('play_now' === $mode) {
            $backend->skip($station);
        }

        return $response->withJson(
            new Status(
                true,
                'play_now' === $mode
                    ? sprintf(__('Playlist started now with %d queued track(s).'), $queued)
                    : sprintf(__('Playlist queued next with %d track(s).'), $queued)
            )
        );
    }
}
