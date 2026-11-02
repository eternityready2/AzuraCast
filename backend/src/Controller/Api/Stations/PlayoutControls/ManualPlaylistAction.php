<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\PlayoutControls;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\StationMedia;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Event\Radio\AnnotateNextSong;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\Adapters;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Utilities\Time;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;

final class ManualPlaylistAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    private const int MAX_MANUAL_TRACKS = 500;

    /** Fade on a track cut at the end of the playlist's scheduled time. */
    private const float WINDOW_END_FADE_SECONDS = 3.0;

    public function __construct(
        private readonly StationPlaylistMediaRepository $playlistMediaRepo,
        private readonly Adapters $adapters,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Scheduler $scheduler,
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

        // Started inside its scheduled time, the playlist plays only what fits
        // before that time ends and the last track is cut there: a one-hour
        // show started at 11:34 in an 11:00-12:00 slot stops at 12:00 instead
        // of running on past it into the next hour.
        $secondsLeft = $this->secondsLeftInScheduledWindow($playlist, $station);

        $queued = 0;
        $elapsed = 0.0;
        foreach ($queue as $queueItem) {
            if (null !== $secondsLeft && $elapsed >= $secondsLeft) {
                break;
            }

            $media = $this->em->find(StationMedia::class, $queueItem->media_id);
            if (!$media instanceof StationMedia) {
                continue;
            }

            $event = AnnotateNextSong::fromStationMedia($station, $media, true);
            $this->eventDispatcher->dispatch($event);

            $length = $media->getCalculatedLength();
            if (null !== $secondsLeft && $length > 0 && $elapsed + $length > $secondsLeft) {
                $cueIn = (float)($event->getAnnotations()['liq_cue_in'] ?? 0.0);
                $event->addAnnotations([
                    'liq_cue_out' => round($cueIn + ($secondsLeft - $elapsed), 2),
                    'liq_fade_out' => self::WINDOW_END_FADE_SECONDS,
                ]);
            }
            $elapsed += $length;

            // Normal request queue for both modes. The interrupting queue is
            // mixed on top of the music (smart duck, for voice-overs), so a
            // playlist started there played over whatever was already on air.
            $backend->enqueue($station, LiquidsoapQueues::Requests, $event->buildAnnotations());
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

    /**
     * Seconds until the end of the playlist's schedule row that is open now,
     * or null when none is (the playlist then plays in full).
     */
    private function secondsLeftInScheduledWindow(StationPlaylist $playlist, Station $station): ?int
    {
        $tz = $station->getTimezoneObject();
        $now = Time::nowInTimezone($tz);

        $left = null;
        foreach ($playlist->schedule_items as $schedule) {
            if (!$schedule instanceof StationSchedule || !$this->scheduler->shouldSchedulePlayNow($schedule, $tz, $now)) {
                continue;
            }

            $end = StationSchedule::getDateTime($schedule->end_time, $tz, $now);
            if ($end->getTimestamp() <= $now->getTimestamp()) {
                // The row runs past midnight.
                $end = $end->addDay();
            }

            $seconds = $end->getTimestamp() - $now->getTimestamp();
            if (null === $left || $seconds > $left) {
                $left = $seconds;
            }
        }

        return $left;
    }
}
