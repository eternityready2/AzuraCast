<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies soft broadcast-clock targets after AutoDJ selects content and before
 * stretch/squeeze freezes the row's projected duration.
 */
final class BroadcastClockQueueTimingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BroadcastClockPlanner $clockPlanner,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['applyClockTarget', -4],
        ];
    }

    public function applyClockTarget(BuildQueue $event): void
    {
        $seconds = $this->clockPlanner->secondsUntilNextSoftAnchor(
            $event->getStation(),
            $event->getExpectedPlayTime(),
        );

        if (null === $seconds || $seconds <= 0) {
            return;
        }

        $targetSeconds = max(1, $seconds);

        foreach ($event->getNextSongs() as $queueRow) {
            if (!$queueRow instanceof StationQueue) {
                continue;
            }

            $media = $queueRow->media;
            $isLegalId = $queueRow->top_of_hour_legal_id
                || $queueRow->clock_wheel_legal_id_substitute
                || ($media instanceof StationMedia && StationMediaTypes::isStationId($media->type));

            if ($isLegalId) {
                continue;
            }

            // This field is consumed by StretchSqueezeQueueTiming. A short
            // timing difference is handled pitch-preservingly; a large overrun
            // keeps the normal cue-out path so Liquidsoap fades rather than
            // abruptly terminating the audio.
            $queueRow->hour_boundary_max_play_seconds = $targetSeconds;

            if ($media instanceof StationMedia) {
                $queueRow->hour_boundary_enforce_cap = $media->getCalculatedLength() > $targetSeconds;
                continue;
            }

            // Non-media queue rows cannot receive AutoCue cue points. Bound
            // their projected duration so later queue slots still recover to
            // the station clock rather than propagating the overrun.
            if (null !== $queueRow->duration && $queueRow->duration > $targetSeconds) {
                $queueRow->duration = (float)$targetSeconds;
            }
        }
    }
}
