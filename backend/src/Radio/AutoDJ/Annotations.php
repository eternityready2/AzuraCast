<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Cache\AutoCueCache;
use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\CustomFieldRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationMediaMetadata as Meta;
use App\Entity\StationQueue;
use App\Entity\StationRequest;
use App\Event\Radio\AnnotateNextSong;
use App\Event\Radio\ResolveQueueClockConstraint;
use App\Event\Radio\RevalidateQueuedSong;
use App\Radio\Backend\Liquidsoap\Command\NextSongHeldException;
use App\Utilities\Time;
use App\Utilities\Types;
use Carbon\CarbonImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final class Annotations implements EventSubscriberInterface
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    /** How far ahead a held row may be due and still count as the hour's opener. */
    private const int HOUR_OPENER_WINDOW_MINUTES = 10;

    /** Projected air time of the row last revalidated before send. */
    private ?CarbonImmutable $lastExpectedPlayAt = null;

    /** Until when a stream show holds the air, from the same check; null when none does. */
    private ?CarbonImmutable $lastStreamHeldUntil = null;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly CustomFieldRepository $customFieldRepo,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AutoCueCache $autoCueCache,
        private readonly AiredLength $airedLength,
        private readonly StreamProgrammeClock $streamProgrammeClock,
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
    /**
     * @param bool $autoDjHeldByTopOfHour Liquidsoap's Top-of-Hour lane is holding
     *   AutoDJ: whatever is sent now only loads, and starts from its beginning
     *   when the ID/news releases. A "held for the new hour" decision is exactly
     *   that, so it is not refused here; refusing left nothing loaded and the
     *   hour opened after a fetch instead of straight after the ID.
     */
    public function annotateNextSong(
        Station $station,
        bool $asAutoDj = false,
        bool $autoDjHeldByTopOfHour = false,
    ): string {
        $queueRow = $this->queueRepo->getNextToSendToAutoDj($station);

        if (null === $queueRow) {
            throw new RuntimeException('Queue is empty!');
        }

        $startsAt = null;
        if ($asAutoDj) {
            // Last chance to catch drift before this row is irreversible.
            // postAnnotation() below marks it "sent" the moment this method
            // returns, and Liquidsoap resolves the actual audio file into its
            // one-ahead crossfade reserve immediately after -- typically as
            // soon as the CURRENT track starts, which can be minutes before
            // this row is due to air. A plugin's periodic re-check (e.g. the
            // Top-of-Hour swap selector, on every queue rebuild cycle) can
            // therefore still be looking at a row that hasn't been sent yet
            // when drift in the currently-playing track pushes this row's
            // landing out of tolerance, and never gets another look at it
            // once it has been. This uses the freshest timing available --
            // the station's actual current playback state, not the queue's
            // last-recalculated projection -- for one final, synchronous
            // check right at the handoff point.
            // This runs on the critical path for every single AutoDJ track
            // handoff station-wide, not just Top-of-Hour stations, so a
            // failure here must never be able to take down normal playback.
            $heldBack = false;
            $this->lastExpectedPlayAt = null;
            $this->lastStreamHeldUntil = null;
            try {
                $heldBack = $this->revalidateBeforeSend($station, $queueRow);
            } catch (Throwable $e) {
                // Swallow and proceed with whatever queueRow already was --
                // but LOG it. This used to swallow silently, which made a
                // broken revalidation path indistinguishable from "nothing to
                // revalidate": both looked like total silence in the logs.
                $this->logger->warning(
                    'Annotations: revalidateBeforeSend failed; proceeding with the '
                    . 'already-selected row unrevalidated.',
                    ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
                );
            }

            // A plugin held this row back: sent now, it would start just
            // before the Top-of-Hour ID and be cut. It stays queued (unsent)
            // and opens the new hour; the gap before the ID is Liquidsoap's
            // to fill (tempo / one promo), never a song the ID cuts.
            // The lane-hold exemption is for the row that opens THIS hour. A row
            // held because the NEXT hour's ID would cut it is not an opener:
            // behind the 11:00 CMS show, "Almost Home" (due 11:59:39) was sent
            // at 11:00:17 under the exemption, sat loaded for 59 minutes where
            // the swap could not replace it, and the 11:59:59 ID cut it after
            // 20s (Mon 2026-10-05).
            //
            // A stream show on the air is the same case: Liquidsoap plays the
            // stream from its own schedule switch, so what is sent meanwhile
            // only loads, and starts from its beginning when the show (and the
            // ID after it) hands the air back. It has to be loaded: with
            // nothing in the AutoDJ transport, Liquidsoap takes the air as
            // gone quiet and starts the Station ID as soon as its early window
            // opens. Refused until the show's last 30 seconds, the item behind
            // Unshackled left the transport empty and the ID aired at 11:59:29
            // PM, cutting the show's last half minute and opening the next
            // hour 30 seconds early (Sat 2026-10-10). Loaded when the show
            // began, the item behind the Morning Show let the ID start at
            // 8:59:59 and opened 9:00 straight after it (Fri 2026-10-09).
            $onlyLoads = $autoDjHeldByTopOfHour || null !== $this->lastStreamHeldUntil;
            $airFreeFrom = $this->lastStreamHeldUntil ?? Time::nowUtc();
            $opensWhenAirIsFree = null !== $this->lastExpectedPlayAt
                && $this->lastExpectedPlayAt->lessThan($airFreeFrom->addMinutes(self::HOUR_OPENER_WINDOW_MINUTES));
            if ($heldBack && !($onlyLoads && $opensWhenAirIsFree) && $this->em->contains($queueRow)) {
                $this->em->flush();
                throw new NextSongHeldException(
                    'Held: this item waits for what holds the air first (the Top-of-Hour ID, or a strict programme).'
                );
            }

            // A listener dropped this slot at the last check (nothing fits before
            // the Top-of-Hour ID, or its playlist may not play now). Refuse rather
            // than send it; the next queued row is taken on the retry.
            if (!$this->em->contains($queueRow)) {
                $this->em->flush();
                throw new RuntimeException('Queued row was dropped at the final check before sending.');
            }

            // The check above worked out when this row starts from what really
            // airs ahead of it, and everything that times the row while it is
            // annotated goes by that. A row loaded under the ID lane starts when
            // the lane lets go, which is only an estimate, so that one is timed
            // as before.
            if (!$heldBack && !$autoDjHeldByTopOfHour) {
                $startsAt = $this->lastExpectedPlayAt;
            }
        }

        $event = AnnotateNextSong::fromStationQueue($queueRow, $asAutoDj);
        if (null !== $startsAt) {
            $event->setExpectedPlayAt($startsAt->toDateTimeImmutable());
        }
        $this->eventDispatcher->dispatch($event);

        return $event->buildAnnotations();
    }

    /**
     * @return bool true when a plugin held the row back from being sent now
     * @phpstan-impure
     */
    private function revalidateBeforeSend(Station $station, StationQueue $queueRow): bool
    {
        $currentSong = $station->current_song;
        $now = Time::nowUtc();

        // Each track holds the air for its AutoCue cue_out - cue_in (measured
        // against song history to ~0.1s); the file length was off by up to 17s
        // per track, which is how the 8pm 2026-09-24 final song landed 18s short.
        // A show on air holds it until its part ends or until the station
        // clock takes it (the Top-of-Hour ID), as the queue's own projection
        // has it. Counted to the end of its file, the row behind Mad Christian
        // Radio's 2h50m file was judged at 3:50pm, outside the show's window,
        // and sent at 1:00pm; the 2pm ID cut the show and that row, a song,
        // aired inside it (Sat 2026-10-10). Music is timed as before.
        $expectedPlayAt = $now;
        $airFreeAt = $now;
        if (null !== $currentSong) {
            $currentStart = CarbonImmutable::instance($currentSong->timestamp_start);
            if (true === $currentSong->playlist?->is_programme && null !== $currentSong->media) {
                [$expectedPlayAt, $airFreeAt] = $this->showHoldsAirUntil(
                    $station,
                    $currentStart,
                    $currentSong->duration,
                    $currentSong->media,
                );
            } else {
                $currentLength = $this->airedLength->lengthOf($currentSong->media, $currentSong->duration);
                $expectedPlayAt = $currentStart->addSeconds(max(1.0, $currentLength));
                $airFreeAt = $expectedPlayAt;
            }

            if ($expectedPlayAt->lessThan($now)) {
                $expectedPlayAt = $now;
            }
            if ($airFreeAt->lessThan($now)) {
                $airFreeAt = $now;
            }
        }

        // A stream show handed to Liquidsoap holds the air until its window
        // closes, whatever its row's stored length says and however long ago
        // it was sent: the same answer the queue's own projection uses.
        $streamHeldUntil = $this->streamProgrammeClock->onAirHeldUntil($station, $now);
        $this->lastStreamHeldUntil = $streamHeldUntil;
        if (null !== $streamHeldUntil && $streamHeldUntil->greaterThan($airFreeAt)) {
            $airFreeAt = $streamHeldUntil;
            $expectedPlayAt = $streamHeldUntil;
        }

        // ...and then everything already resolved into Liquidsoap ahead of
        // this row, because all of it airs before this row does. Now-playing
        // feedback lags a track change by several seconds, so the track that
        // has just started is still in this list and is counted here.
        foreach ($this->queueRepo->getUnairedSentRows($station) as $aheadRow) {
            if ($aheadRow->id === $queueRow->id || $this->streamProgrammeClock->isStreamProgrammeRow($aheadRow)) {
                continue;
            }
            if (true === $aheadRow->playlist?->is_programme && null !== $aheadRow->media) {
                [$expectedPlayAt, $airFreeAt] = $this->showHoldsAirUntil(
                    $station,
                    $airFreeAt,
                    $aheadRow->duration,
                    $aheadRow->media,
                );
                continue;
            }
            $airFreeAt = $airFreeAt->addSeconds(
                $this->airedLength->lengthOf($aheadRow->media, $aheadRow->duration)
            );
            $expectedPlayAt = $airFreeAt;
        }

        $this->lastExpectedPlayAt = $expectedPlayAt;

        $event = new RevalidateQueuedSong($station, $queueRow, $expectedPlayAt->toDateTimeImmutable(), true);
        $this->eventDispatcher->dispatch($event);

        // Where the row really starts once what holds the air first is done:
        // only a row that opens within minutes may load under the ID. A row
        // waiting for a strict programme (Faith Horizons, 25 minutes) is not an
        // opener; loaded under the ID, the programme threw it away on taking
        // the air.
        $opensAfter = $event->getOpensAfter();
        if (null !== $opensAfter && $opensAfter > $this->lastExpectedPlayAt) {
            $this->lastExpectedPlayAt = CarbonImmutable::instance($opensAfter);
        }

        return $event->isHeldBack();
    }

    /**
     * When the item after a show's part is due, and when the air is free for
     * it: both the part's end, unless the station clock interrupts the part
     * first. Then the next item is due at the interruption (it is held, and
     * opens what follows) and the air is free when the clock event is over.
     * A part holds the air for its own row's length, not its file's.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function showHoldsAirUntil(
        Station $station,
        CarbonImmutable $start,
        ?float $partSeconds,
        StationMedia $media,
    ): array {
        $seconds = null !== $partSeconds && $partSeconds > 0.0
            ? min($partSeconds, $media->length)
            : $media->length;
        $end = $start->addMilliseconds((int)round(max(1.0, $seconds) * 1000));

        $constraint = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            $end->toDateTimeImmutable(),
        );
        $this->eventDispatcher->dispatch($constraint);

        $interruptAt = $constraint->getInterruptAt();
        $resumeAt = $constraint->getResumeAt();
        if (null === $interruptAt || null === $resumeAt || $interruptAt <= $start) {
            return [$end, $end];
        }

        return [CarbonImmutable::instance($interruptAt), CarbonImmutable::instance($resumeAt)];
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
            // Uncapped length; later subscribers may shrink `duration` to a wall-clock cap.
            'natural_length' => $duration,
            // Lets Liquidsoap limit stretch/squeeze and the Top-of-Hour fit to music.
            'media_type' => $media->type,
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
