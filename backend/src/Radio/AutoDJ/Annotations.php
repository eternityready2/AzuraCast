<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Cache\AutoCueCache;
use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\CustomFieldRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationMediaMetadata as Meta;
use App\Entity\StationQueue;
use App\Entity\StationRequest;
use App\Event\Radio\AnnotateNextSong;
use App\Utilities\Time;
use App\Utilities\Types;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class Annotations implements EventSubscriberInterface
{
    use EntityManagerAwareTrait;

    private const int MAX_STALE_QUEUE_ROWS = 100;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly CustomFieldRepository $customFieldRepo,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AutoCueCache $autoCueCache,
        private readonly Scheduler $scheduler,
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            AnnotateNextSong::class => [
                ['annotateSongPath', 20],
                ['annotateForLiquidsoap', 15],
                ['addCachedAutocueData', 12],
                ['annotatePlaylist', 10],
                ['annotateRequest', 5],
                ['postAnnotation', -10],
            ],
        ];
    }

    /**
     * Pulls the next song from the AutoDJ, dispatches the AnnotateNextSong event and returns the built result.
     */
    public function annotateNextSong(
        Station $station,
        bool $asAutoDj = false,
    ): string {
        $queueRow = null;

        // A schedule/enable toggle can change after a queue row was planned. Queue::buildQueue()
        // already drops stale unsent rows while rebuilding, but the final AutoDJ handoff must
        // enforce the same invariant so a disabled or out-of-window playlist can never be sent
        // to Liquidsoap merely because it was queued earlier.
        for ($attempt = 0; $attempt < self::MAX_STALE_QUEUE_ROWS; $attempt++) {
            $queueRow = $this->queueRepo->getNextToSendToAutoDj($station);

            if (null === $queueRow) {
                throw new RuntimeException('Queue is empty!');
            }

            if ($this->isQueueRowStillEligible($queueRow)) {
                break;
            }

            $this->em->remove($queueRow);
            $this->em->flush();
            $queueRow = null;
        }

        if (null === $queueRow) {
            throw new RuntimeException('Queue contains too many stale scheduled items; rebuild the station queue.');
        }

        $event = AnnotateNextSong::fromStationQueue($queueRow, $asAutoDj);
        $this->eventDispatcher->dispatch($event);

        return $event->buildAnnotations();
    }

    private function isQueueRowStillEligible(StationQueue $queueRow): bool
    {
        // These station/clock boundary items are governed by the dedicated boundary
        // planner and must not be invalidated by ordinary playlist schedule ownership.
        if ($queueRow->top_of_hour_legal_id || $queueRow->clock_wheel_legal_id_substitute) {
            return true;
        }

        $playlist = $queueRow->playlist;
        if (null === $playlist) {
            return true;
        }

        if (!$playlist->is_enabled) {
            return false;
        }

        $now = Time::nowUtc();
        $expectedPlayTime = $queueRow->timestamp_played instanceof DateTimeInterface
            ? CarbonImmutable::instance($queueRow->timestamp_played)
            : $now;

        if ($expectedPlayTime < $now) {
            $expectedPlayTime = $now;
        }

        return $this->scheduler->isPlaylistScheduledToPlayNow(
            $playlist,
            $expectedPlayTime,
            true
        );
    }

    public function annotateSongPath(AnnotateNextSong $event): void
    {
        $media = $event->getMedia();
        if ($media instanceof StationMedia) {
            $event->setSongPath('media:' . ltrim($media->path, '/'));
        } else {
            $queue = $event->getQueue();
            if ($queue instanceof StationQueue) {
                $customUri = $queue->autodj_custom_uri;
                if (!empty($customUri)) {
                    $event->setSongPath($customUri);
                }
            }
        }
    }

    public function annotateForLiquidsoap(AnnotateNextSong $event): void
    {
        $media = $event->getMedia();
        if (null === $media) {
            return;
        }

        $station = $event->getStation();
        if (!$station->backend_type->isEnabled()) {
            return;
        }

        $duration = $media->length;

        $event->addAnnotations([
            'title' => $media->title,
            'artist' => $media->artist,
            'duration' => $duration,
            'song_id' => $media->song_id,
            'media_id' => $media->id,
            'sq_id' => $event->getQueue()?->id,
            ...$this->processAutocueAnnotations(
                $station,
                $media->extra_metadata->toArray(),
                $duration,
            ),
            ...$this->customFieldRepo->getCustomFields($media),
        ]);
    }

    public function addCachedAutocueData(AnnotateNextSong $event): void
    {
        $media = $event->getMedia();
        if (null === $media) {
            return;
        }

        $station = $event->getStation();
        if (!$station->backend_type->isEnabled()) {
            return;
        }

        $cacheKey = $this->autoCueCache->getCacheKey($media);

        $event->addAnnotations([
            'azuracast_cache_key' => $cacheKey,
            ...$this->processAutocueAnnotations(
                $station,
                $this->autoCueCache->getForCacheKey($cacheKey),
                $media->length
            ),
        ]);
    }

    /**
     * @param null|array<string, mixed> $metadata
     */
    private function processAutocueAnnotations(
        Station $station,
        ?array $metadata,
        float $duration,
    ): array {
        $annotations = array_filter(
            $metadata ?? [],
            fn($row) => $row !== null
        );

        if (0 === count($annotations)) {
            return [];
        }

        // If cue_out is negative, it's relative to the end of the track; recompute to be relative to the start.
        if (
            isset($annotations[Meta::CUE_OUT])
            && $annotations[Meta::CUE_OUT] < 0.0
        ) {
            $cueOut = abs($annotations[Meta::CUE_OUT]);

            if (0.0 === $cueOut) {
                unset($annotations[Meta::CUE_OUT]);
            }

            if ($cueOut > $duration) {
                unset($annotations[Meta::CUE_OUT]);
            } else {
                $annotations[Meta::CUE_OUT] = max(0, $duration - $cueOut);
            }
        }

        // cue_out must be less than track duration.
        if (
            isset($annotations[Meta::CUE_OUT])
            && $annotations[Meta::CUE_OUT] > $duration
        ) {
            unset($annotations[Meta::CUE_OUT]);
        }

        // cue_in must be less than track duration.
        if (
            isset($annotations[Meta::CUE_IN])
            && $annotations[Meta::CUE_IN] > $duration
        ) {
            unset($annotations[Meta::CUE_IN]);
        }

        if (0 === count($annotations)) {
            return [];
        }

        // Liquidsoap expects amplify to be in dB.
        if (isset($annotations[Meta::AMPLIFY])) {
            $amplify = trim((string) $annotations[Meta::AMPLIFY]);
            if (!str_ends_with($amplify, 'dB')) {
                $amplify .= ' dB';
            }

            $annotations[Meta::AMPLIFY] = $amplify;

            // If only amplify is specified, return just it to use it in other AutoCue/amplify functions.
            if (1 === count($annotations)) {
                return [
                    'liq_amplify' => $annotations[Meta::AMPLIFY],
                ];
            }
        }

        // Ensure default values for all annotations.
        $annotations[Meta::CUE_IN] ??= 0.0;
        $annotations[Meta::CUE_OUT] ??= $duration;

        // cue_out must always be greater than cue_in.
        if ($annotations[Meta::CUE_OUT] < $annotations[Meta::CUE_IN]) {
            $annotations[Meta::CUE_IN] = 0.0;
            $annotations[Meta::CUE_OUT] = $duration;
        }

        // start_next must be between cue_in and cue_out.
        if (isset($annotations[Meta::CROSS_START_NEXT])) {
            $startNext = $annotations[Meta::CROSS_START_NEXT];
            if (
                $startNext < $annotations[Meta::CUE_IN]
                || $startNext > $annotations[Meta::CUE_OUT]
            ) {
                unset($annotations[Meta::CROSS_START_NEXT]);
            }
        }

        $backendConfig = $station->backend_config;
        $defaultFade = $backendConfig->isCrossfadeEnabled()
            ? $backendConfig->crossfade
            : 0.0;

        $annotations[Meta::FADE_IN] ??= $defaultFade;
        $annotations[Meta::FADE_OUT] ??= $defaultFade;

        return [
            'azuracast_autocue' => true,
            'liq_amplify' => Types::stringOrNull($annotations[Meta::AMPLIFY] ?? null),
            'autocue_cue_in' => Types::float($annotations[Meta::CUE_IN]),
            'autocue_cue_out' => Types::float($annotations[Meta::CUE_OUT]),
            'autocue_fade_in' => Types::float($annotations[Meta::FADE_IN]),
            'autocue_fade_out' => Types::float($annotations[Meta::FADE_OUT]),
            'autocue_start_next' => Types::floatOrNull(
                $annotations[Meta::CROSS_START_NEXT] ?? null
            ),
        ];
    }

    public function annotatePlaylist(AnnotateNextSong $event): void
    {
        $playlist = $event->getPlaylist();
        if (null === $playlist) {
            return;
        }

        $event->addAnnotations([
            'playlist_id' => $playlist->id,
        ]);

        if ($playlist->is_jingle) {
            $event->addAnnotations([
                'jingle_mode' => 'true',
            ]);
        }

        // Long-form programs are intentionally treated differently from songs in
        // the AI DJ and Liquidsoap configuration. Do not ask Liquidsoap to perform
        // expensive song-style AutoCue analysis on a remote feed, a single-track
        // program or a merged program block; it predictably exceeds the analysis
        // budget on 30-60 minute shows and only produces noisy failure logs.
        if (
            PlaylistSources::RemoteUrl === $playlist->source
            || $playlist->backendPlaySingleTrack()
            || $playlist->backendMerge()
        ) {
            $event->addAnnotations([
                'azuracast_autocue' => false,
            ]);
        }
    }

    public function annotateRequest(AnnotateNextSong $event): void
    {
        $request = $event->getRequest();
        if ($request instanceof StationRequest) {
            $event->addAnnotations([
                'request_id' => $request->id,
            ]);
        }
    }

    public function postAnnotation(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $queueRow = $event->getQueue();
        if ($queueRow instanceof StationQueue) {
            $queueRow->sent_to_autodj = true;
            $queueRow->timestamp_cued = Time::nowUtc();
            $this->em->persist($queueRow);
            $this->em->flush();
        }
    }
}
