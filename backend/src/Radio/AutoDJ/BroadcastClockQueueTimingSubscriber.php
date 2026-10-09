<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Event\Radio\BuildQueue;
use App\Event\Radio\ResolveQueueClockConstraint;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies soft broadcast-clock targets during queue planning and then tightens
 * them once more immediately before a normal AutoDJ row is handed to Liquidsoap.
 */
final class BroadcastClockQueueTimingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BroadcastClockPlanner $clockPlanner,
        private readonly StationQueueRepository $queueRepo,
        private readonly AiredLength $airedLength,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['applyClockTarget', -4],
            AnnotateNextSong::class => ['applyRuntimeClockTarget', 13],
        ];
    }

    public function applyClockTarget(BuildQueue $event): void
    {
        if ($event->isInterrupting()) {
            return;
        }

        $maxDuration = $this->clockPlanner->maxContentDurationBeforeNextSoftAnchor(
            $event->getStation(),
            $event->getExpectedPlayTime(),
        );

        if (null === $maxDuration || $maxDuration <= 0) {
            return;
        }

        $targetSeconds = max(1, (int)floor($maxDuration));

        foreach ($event->getNextSongs() as $queueRow) {
            if (!$queueRow instanceof StationQueue) {
                continue;
            }

            $media = $queueRow->media;
            if ($this->isProtectedContent($queueRow, $media)) {
                continue;
            }

            // This field is consumed by StretchSqueezeQueueTiming. A short
            // timing difference is handled pitch-preservingly; a large overrun
            // keeps the normal cue-out/fade path so Liquidsoap fades rather than
            // abruptly terminating the audio.
            $queueRow->hour_boundary_max_play_seconds = $targetSeconds;

            if ($media instanceof StationMedia) {
                $queueRow->hour_boundary_enforce_cap = $media->getCalculatedLength() > $targetSeconds;
                continue;
            }

            // Non-media queue rows cannot receive AutoCue cue points. Bound
            // their projected duration so later queue slots recover to the
            // station clock rather than propagating the overrun. Runtime source
            // switching remains responsible for actually ending a remote source.
            if (null !== $queueRow->duration && $queueRow->duration > $targetSeconds) {
                $queueRow->duration = (float)$targetSeconds;
            }
        }
    }

    /**
     * Recheck a normal media row against the near-live clock before Liquidsoap
     * receives it. Queue projections can move after they were first built; this
     * only tightens an existing plan and never lengthens a row or changes which
     * track was selected.
     */
    public function applyRuntimeClockTarget(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $queueRow = $event->getQueue();
        $media = $event->getMedia();
        if (!$queueRow instanceof StationQueue || !$media instanceof StationMedia) {
            return;
        }

        // Direct/interrupting content is already delivered through a separate
        // playout path and must not be shortened by ordinary schedule recovery.
        if ($queueRow->is_played || $queueRow->playlist?->backendInterruptOtherSongs()) {
            return;
        }

        if ($this->isProtectedContent($queueRow, $media)) {
            return;
        }

        // The hand-off has already worked out when this row starts; the cap is
        // decided from that. The estimate below is for a row sent without one.
        $startsAt = $event->getExpectedPlayAt();
        if (null !== $startsAt) {
            $this->applyHandOffClockTarget($event->getStation(), $queueRow, $media, $startsAt);
            return;
        }

        $maxDuration = $this->clockPlanner->maxContentDurationBeforeNextSoftAnchor(
            $event->getStation(),
            $this->resolveLikelyStart($event->getStation()),
        );
        if (null === $maxDuration || $maxDuration <= 0) {
            return;
        }

        $targetSeconds = max(1, (int)floor($maxDuration));
        $existingTarget = $queueRow->hour_boundary_max_play_seconds;
        if (null !== $existingTarget && $existingTarget > 0) {
            $targetSeconds = min($targetSeconds, $existingTarget);
        }

        if ($media->getCalculatedLength() <= $targetSeconds) {
            return;
        }

        $queueRow->hour_boundary_max_play_seconds = $targetSeconds;
        $queueRow->hour_boundary_enforce_cap = true;
        $queueRow->clock_wheel_stretch_ratio = null;

        // Keep StationQueue::duration as the media's projected/full duration.
        // The runtime playout limit is represented independently by
        // hour_boundary_max_play_seconds and consumed by the annotator/Liquidsoap
        // cue-out path. Replacing duration with the remaining wall-clock window
        // makes Upcoming Queue/API clients report a 3-4 minute song as only a few
        // seconds long even though the underlying media duration is unchanged.
    }

    /**
     * The cap as decided at the hand-off, from the start worked out there: each
     * track ahead of the row holds the air for its AutoCue cue_out - cue_in.
     * resolveLikelyStart() adds up file lengths instead, and ran 15s late for
     * "Cry Holy" (6:55am Thu 2026-10-08): the song the Top-of-Hour swap had just
     * fitted to the ID was judged too long for the 7am show and sent with its
     * last 11s cut off, and the ID started 5s early.
     */
    private function applyHandOffClockTarget(
        Station $station,
        StationQueue $queueRow,
        StationMedia $media,
        DateTimeImmutable $startsAt,
    ): void {
        // A cap the queue projection left on the row was measured from the
        // projection's start, not this one. Same restore as
        // Queue::applyBroadcastClockCapToQueuedRow().
        if ($queueRow->hour_boundary_enforce_cap) {
            $naturalDuration = $media->getCalculatedLength();
            if (
                $queueRow->clock_wheel_enforce_cap
                && null !== $queueRow->clock_wheel_max_play_seconds
                && $queueRow->clock_wheel_max_play_seconds > 0
            ) {
                $queueRow->duration = min($naturalDuration, (float)$queueRow->clock_wheel_max_play_seconds);
            } elseif (null !== $queueRow->clock_wheel_stretch_ratio && $queueRow->clock_wheel_stretch_ratio > 0.0) {
                $queueRow->duration = $naturalDuration / $queueRow->clock_wheel_stretch_ratio;
            } else {
                $queueRow->duration = $naturalDuration;
            }

            $queueRow->hour_boundary_enforce_cap = false;
            $queueRow->hour_boundary_max_play_seconds = null;
        }

        $maxDuration = $this->clockPlanner->maxContentDurationBeforeNextSoftAnchor($station, $startsAt);
        if (null === $maxDuration || $maxDuration <= 0) {
            return;
        }

        // What airs is cue_out - cue_in. The file runs on past the last audible
        // second, so a song that ends before the anchor could still be "too long".
        // An item AutoCue never measured airs its whole file.
        $targetSeconds = max(1, (int)floor($maxDuration));
        $aired = $this->airedLength->forMedia($media);
        $airedSeconds = $aired['known'] ? $aired['length'] : $media->getCalculatedLength();
        if ($airedSeconds <= $targetSeconds) {
            return;
        }

        // A song is taken off the air before the anchor by something outside the
        // queue (the Top-of-Hour ID, a second before the show on the hour), so a
        // cap has nothing left to shorten. Capped anyway, the song reached
        // Liquidsoap already cut to length, and the tempo fit that lands it on
        // the ID whole had nothing to work with. Music only, as with stretch and
        // squeeze: for a show or a spot the target stays a ceiling.
        if ('music' === $media->type) {
            $interruption = new ResolveQueueClockConstraint(
                $station,
                $startsAt,
                CarbonImmutable::instance($startsAt)
                    ->addMilliseconds((int)round($airedSeconds * 1000))
                    ->toDateTimeImmutable(),
            );
            $this->dispatcher->dispatch($interruption);

            $interruptAt = $interruption->getInterruptAt();
            if (
                null !== $interruptAt
                && $interruptAt->getTimestamp() <= $startsAt->getTimestamp() + $targetSeconds
            ) {
                return;
            }
        }

        $queueRow->hour_boundary_max_play_seconds = $targetSeconds;
        $queueRow->hour_boundary_enforce_cap = true;
        $queueRow->clock_wheel_stretch_ratio = null;
    }

    private function resolveLikelyStart(Station $station): DateTimeImmutable
    {
        $now = Time::nowUtc();
        $currentSong = $station->current_song;
        if (null === $currentSong) {
            return $now;
        }

        $duration = max(0.0, (float)$currentSong->duration);
        $end = CarbonImmutable::instance($currentSong->timestamp_start)
            ->addMilliseconds((int)round($duration * 1000));

        $crossfade = max(0.0, $station->backend_config->getCrossfadeDuration());
        if ($duration >= $crossfade && $crossfade > 0) {
            $end = $end->subMilliseconds((int)round($crossfade * 1000));
        }

        // The current song is not necessarily the only thing between now and
        // the row being annotated: Liquidsoap is handed rows one ahead, so
        // several can be "sent but not yet aired" at once. Counting only the
        // current song makes every one of them believe it starts next, so each
        // independently caps itself to land on the same boundary. They then
        // play back-to-back and every cap after the first is wrong -- which put
        // a song on air seconds before the Top-of-Hour ID. Push the estimate
        // past everything already handed over but still unaired.
        $pending = $this->queueRepo->getUnairedSentDuration($station, $crossfade);
        if ($pending > 0.0) {
            $end = $end->addMilliseconds((int)round($pending * 1000));
        }

        return $end->greaterThan($now)
            ? $end->toDateTimeImmutable()
            : $now;
    }

    private function isProtectedContent(
        StationQueue $queueRow,
        ?StationMedia $media,
    ): bool {
        return $queueRow->top_of_hour_legal_id
            || $queueRow->clock_wheel_legal_id_substitute
            || ($media instanceof StationMedia && StationMediaTypes::isStationId($media->type));
    }
}
