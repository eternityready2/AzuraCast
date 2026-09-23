<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps projected queue timing aligned with pitch-preserving stretch/squeeze.
 *
 * QueueBuilder and Clock Wheel planning attach the
 * `clock_wheel_stretch_ratio` field to rows that may be backtimed. This listener
 * freezes the final timing decision while the row is being planned, so later
 * runtime setting changes never make an already-queued row play for a different
 * duration than the one used to plan the rows after it.
 */
final class StretchSqueezeQueueTiming implements EventSubscriberInterface
{
    private const bool DEFAULT_ENABLED = true;

    private const float DEFAULT_MAX_PERCENT = 5.0;

    /**
     * Absolute ceiling for either direction, independent of what an operator
     * configures. Matches the pre-existing single-knob ceiling this replaces
     * -- splitting stretch and squeeze into two settings does not by itself
     * raise how much either one is allowed to alter playback; an operator
     * still has to deliberately turn each one up, and even then not past
     * this hard cap.
     */
    private const float HARD_CEILING_PERCENT = 5.0;

    public const string CONFIG_STRETCH_MAX_PERCENT = 'playout_stretch_max_percent';
    public const string CONFIG_SQUEEZE_MAX_PERCENT = 'playout_squeeze_max_percent';

    /** @deprecated Use CONFIG_STRETCH_MAX_PERCENT / CONFIG_SQUEEZE_MAX_PERCENT. Still read as the fallback for either one when it has no value of its own. */
    public const string CONFIG_LEGACY_MAX_PERCENT = 'playout_stretch_squeeze_max_percent';

    /**
     * [stretchPercent, squeezePercent], each independently configured (falling
     * back to the single legacy `playout_stretch_squeeze_max_percent` value
     * when its own key is absent, so existing stations keep their current
     * behavior until an operator deliberately splits the two), each clamped
     * to HARD_CEILING_PERCENT regardless of what is configured.
     *
     * @param array<string, mixed> $rawConfig
     * @return array{0: float, 1: float}
     */
    public static function getStretchSqueezePercents(array $rawConfig): array
    {
        $legacy = max(
            0.5,
            min(self::HARD_CEILING_PERCENT, (float)($rawConfig[self::CONFIG_LEGACY_MAX_PERCENT] ?? self::DEFAULT_MAX_PERCENT))
        );

        $stretch = array_key_exists(self::CONFIG_STRETCH_MAX_PERCENT, $rawConfig)
            ? max(0.5, min(self::HARD_CEILING_PERCENT, (float)$rawConfig[self::CONFIG_STRETCH_MAX_PERCENT]))
            : $legacy;

        $squeeze = array_key_exists(self::CONFIG_SQUEEZE_MAX_PERCENT, $rawConfig)
            ? max(0.5, min(self::HARD_CEILING_PERCENT, (float)$rawConfig[self::CONFIG_SQUEEZE_MAX_PERCENT]))
            : $legacy;

        return [$stretch, $squeeze];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // DMCA validation runs at -5 and may clear the selected row.
            BuildQueue::class => ['applyProjectedDuration', -6],
        ];
    }

    public function applyProjectedDuration(BuildQueue $event): void
    {
        $station = $event->getStation();

        foreach ($event->getNextSongs() as $queueRow) {
            if ($queueRow instanceof StationQueue) {
                $this->normalizeQueueRow($station, $queueRow);
            }
        }
    }

    /**
     * Freeze one planned queue row to the station settings that were active when
     * the row was selected. Existing queued rows are intentionally not re-timed
     * when an operator changes the runtime setting; the new setting applies to
     * subsequently planned rows instead.
     */
    public function normalizeQueueRow(Station $station, StationQueue $queueRow): void
    {
        $media = $queueRow->media;
        if (!$media instanceof StationMedia) {
            return;
        }

        $calculatedLength = $media->getCalculatedLength();
        if ($calculatedLength <= 0) {
            return;
        }

        $isLegalId = $queueRow->top_of_hour_legal_id
            || $queueRow->clock_wheel_legal_id_substitute
            || StationMediaTypes::isStationId($media->type);

        $targetSeconds = $this->getTimingTarget($queueRow);

        // A precomputed ratio can represent another protected anchor, most often
        // the station-wide top-of-hour target. If a separate scheduled-playlist
        // target was retained on the row, use whichever anchor occurs first.
        // Leave ratio-only rows on the normal ratio path below so disabling or
        // lowering the station limit restores natural playback instead of creating
        // a cap that the planner never requested.
        $precomputedRatio = $queueRow->clock_wheel_stretch_ratio;
        if (
            !$isLegalId
            && null !== $targetSeconds
            && null !== $precomputedRatio
            && $precomputedRatio > 0
        ) {
            $precomputedTargetSeconds = $calculatedLength / $precomputedRatio;
            if ($precomputedTargetSeconds > 0) {
                $targetSeconds = min($targetSeconds, $precomputedTargetSeconds);
            }
        }

        // Legal-ID max durations are ceilings, not backtiming targets. Never slow
        // down or speed up an ID merely to fill its configured maximum duration.
        if ($isLegalId) {
            $queueRow->clock_wheel_stretch_ratio = null;
            $queueRow->duration = null !== $targetSeconds && $calculatedLength > $targetSeconds
                ? $targetSeconds
                : $calculatedLength;
            return;
        }

        $rawConfig = $station->backend_config->toArray(true) ?? [];
        $enabled = (bool)($rawConfig['playout_stretch_squeeze_enabled'] ?? self::DEFAULT_ENABLED);
        [$stretchPercent, $squeezePercent] = self::getStretchSqueezePercents($rawConfig);

        // Stretch (slowing audio down to fill MORE time -- ratio < 1, natural
        // length shorter than the target) and squeeze (speeding it up to fill
        // LESS time -- ratio > 1, natural length longer than the target) are
        // separate operations with separate audibility characteristics, so
        // each gets its own configured ceiling instead of one symmetric
        // percentage covering both directions.
        $minimumRatio = 1.0 - ($stretchPercent / 100);
        $maximumRatio = 1.0 + ($squeezePercent / 100);

        if (null !== $targetSeconds) {
            $ratio = $calculatedLength / $targetSeconds;

            if (
                $enabled
                && $ratio > 0
                && $ratio >= $minimumRatio
                && $ratio <= $maximumRatio
                && abs($ratio - 1.0) >= 0.0001
            ) {
                $queueRow->clock_wheel_stretch_ratio = round($ratio, 4);
                $queueRow->duration = $targetSeconds;

                // The ratio now fulfills the timing requirement by itself. Clear
                // cap/fade flags so the annotation stage cannot apply both a
                // pitch-preserving adjustment and a cue-out truncation.
                $queueRow->clock_wheel_enforce_cap = false;
                $queueRow->hour_boundary_enforce_cap = false;
                return;
            }

            // No safe stretch/squeeze is available. Freeze the fallback duration
            // to what the cap path will actually air: longer tracks are truncated
            // to the target; shorter tracks remain at their natural duration.
            $queueRow->clock_wheel_stretch_ratio = null;
            $queueRow->duration = min($calculatedLength, $targetSeconds);
            return;
        }

        $ratio = $queueRow->clock_wheel_stretch_ratio;
        if (
            $enabled
            && null !== $ratio
            && $ratio > 0
            && $ratio >= $minimumRatio
            && $ratio <= $maximumRatio
        ) {
            $ratio = round($ratio, 4);
            if (abs($ratio - 1.0) >= 0.0001) {
                // Liquidsoap receives four decimal places. Freeze both the stored
                // ratio and projected duration to that same precision so queue
                // timing cannot differ from actual playout by rounding drift.
                $queueRow->clock_wheel_stretch_ratio = $ratio;
                $queueRow->duration = $calculatedLength / $ratio;
                return;
            }
        }

        // Disabled, outside the operator's safety limit, or effectively 1.0 after
        // serialization precision. Freeze the row to natural playback.
        $queueRow->clock_wheel_stretch_ratio = null;
        $queueRow->duration = $calculatedLength;
    }

    private function getTimingTarget(StationQueue $queueRow): ?float
    {
        $targets = [];

        if (
            $queueRow->clock_wheel_enforce_cap
            && null !== $queueRow->clock_wheel_max_play_seconds
            && $queueRow->clock_wheel_max_play_seconds > 0
        ) {
            $targets[] = (float)$queueRow->clock_wheel_max_play_seconds;
        }

        // QueueBuilder stores the next scheduled-playlist boundary even when the
        // selected track is shorter than it. `hour_boundary_enforce_cap` remains
        // false in that case, but the retained value is still an exact backtiming
        // target for a safe stretch operation.
        if (
            null !== $queueRow->hour_boundary_max_play_seconds
            && $queueRow->hour_boundary_max_play_seconds > 0
        ) {
            $targets[] = (float)$queueRow->hour_boundary_max_play_seconds;
        }

        // More than one independent protection can apply to the same row. The
        // earliest boundary must always win; using a fixed precedence here can
        // otherwise let a later scheduled target hide a tighter top-of-hour target.
        return [] === $targets ? null : min($targets);
    }
}
