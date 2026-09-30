<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Cache\QueueLogCache;
use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Event\Radio\ResolveQueueClockConstraint;
use App\Event\Radio\RevalidateQueuedSong;
use App\Utilities\ScheduleRecurrence;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Monolog\Handler\TestHandler;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LogLevel;

/**
 * Public methods related to the AutoDJ Queue process.
 */
final class Queue
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    /**
     * Shortest clock-boundary cap worth airing. Matches the Top-of-Hour early-ID
     * window: a row this close to the boundary is held until after it instead.
     */
    private const int MIN_BOUNDARY_CAP_SECONDS = 30;

    /** Largest single step the preview cursor takes over a slot it could not fill. */
    private const int MAX_PREVIEW_GAP_STEP_SECONDS = 300;

    /** Smallest step, so the preview always makes forward progress. */
    private const int MIN_PREVIEW_GAP_STEP_SECONDS = 1;

    /**
     * How soon the live AutoDJ asks again after a refused pick: Liquidsoap's
     * azuracast.autodj_retry_delay. Before the Top-of-Hour ID a pick is often
     * refused only because it does not land on the ID, and a few seconds later a
     * different one does. The preview retries on the same cadence there, or it
     * wrote off the last minutes of the hour that really are filled on air.
     */
    private const int PREVIEW_RETRY_SECONDS = 10;

    /**
     * Safety cap on consecutive unfilled slots. The preview now steps to the next
     * scheduling boundary rather than a flat five minutes, so a genuinely dry
     * station takes many more (smaller) steps than it used to; this bounds the
     * loop independently of how much wall-clock time those steps covered.
     */
    private const int MAX_CONSECUTIVE_PREVIEW_GAP_STEPS = 500;

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly StationQueueRepository $queueRepo,
        private readonly Scheduler $scheduler,
        private readonly BroadcastClockPlanner $broadcastClockPlanner,
        private readonly QueueLogCache $queueLogCache,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
    ) {
    }

    /**
     * @param int|null $lookaheadMinutesOverride When given, builds forward to at least
     *        this many minutes regardless of the station's configured
     *        `autodj_queue_lookahead_minutes`. Used by the linear-log builder to project
     *        a full day ahead on demand without changing the station's live setting.
     * @param int|null $maxTracksOverride Safety cap override to match a larger horizon.
     * @return list<array{started_at:int, duration:int, reason:string}>
     */
    public function buildQueue(
        Station $station,
        ?int $lookaheadMinutesOverride = null,
        ?int $maxTracksOverride = null,
        bool $isPreview = false,
    ): array {
        $previewGaps = [];
        // Early-fail if the station is disabled.
        if (!$station->supportsAutoDjQueue()) {
            $this->logger->info('Cannot build queue: station does not support AutoDJ queue.');
            return [];
        }

        // Adjust "expectedCueTime" time from current queue.
        $expectedCueTime = Time::nowUtc();

        // Get expected play time of each item. A plugin-owned absolute clock
        // event may interrupt the song that is already on air, so the initial
        // projection must pass through the same generic constraint seam as
        // future queue rows.
        $currentSong = $station->current_song;
        if (null !== $currentSong) {
            [, $expectedPlayTime] = $this->resolveQueueClockConstraint(
                $station,
                CarbonImmutable::instance($currentSong->timestamp_start),
                (float)($currentSong->duration ?? 1.0),
            );

            if ($expectedPlayTime < $expectedCueTime) {
                $expectedPlayTime = $expectedCueTime;
            }
        } else {
            $expectedPlayTime = $expectedCueTime;
        }

        $maxQueueLength = max($station->backend_config->autodj_queue_length, 2);

        // Track-count queue length alone (default 3, sometimes set as low as 2)
        // does not reliably reach far enough into the future for clock wheels,
        // schedules, or top-of-hour logic to resolve tracks in advance -- how
        // far ahead in *time* that represents depends entirely on how long the
        // next few songs happen to be. When configured, this makes the queue
        // keep building until it reaches a guaranteed minimum time horizon,
        // independent of track length.
        $lookaheadMinutes = $lookaheadMinutesOverride ?? $station->backend_config->autodj_queue_lookahead_minutes;
        $lookaheadHorizon = $lookaheadMinutes > 0
            ? Time::nowUtc()->modify('+' . $lookaheadMinutes . ' minutes')
            : null;

        // Hard safety cap so a misconfigured horizon (or a station with very
        // short tracks) can't spin this into an unbounded loop.
        $maxLookaheadTracks = $maxTracksOverride ?? 500;

        $upcomingQueue = $this->queueRepo->getUnplayedQueue($station);

        // Feedback marks an AutoDJ row played as soon as it takes air, so the
        // current/interrupted music row is normally absent from getUnplayedQueue().
        // During TOH, current SongHistory then becomes the ID itself; seeding this
        // cursor from current_song would therefore forget the exact music that was
        // interrupted and allow it to be fetched again as a supposedly fresh
        // request. Use actual played MUSIC history instead. The minimum lookback
        // is deliberately independent of a station disabling broad duplicate
        // prevention: immediate-repeat protection is a separate liveness/continuity
        // invariant, while the final retry still fails open for a one-song library.
        $recentPlayedMusic = $this->queueRepo->getPlayedMusicHistoryByTimeRange(
            $station,
            Time::nowUtc(),
            max(30, $station->backend_config->duplicate_prevention_time_range),
        );
        $lastSongId = $recentPlayedMusic[0]['song_id'] ?? null;
        $queueLength = 0;

        foreach ($upcomingQueue as $queueRow) {
            if (!$queueRow->sent_to_autodj) {
                if (!$this->isQueueRowStillValid($queueRow, $expectedPlayTime)) {
                    $this->em->remove($queueRow);
                    continue;
                }

                // Give a plugin a chance to replace an already-queued pick in
                // place now that its projected play time is fresh. A choice
                // made when this row was first built (e.g. a duration match
                // against a Top-of-Hour deadline) can go stale if the actual
                // air clock has drifted since; this re-runs on every queue
                // rebuild cycle, not just once at build time.
                $revalidate = new RevalidateQueuedSong($station, $queueRow, $expectedPlayTime);
                $this->dispatcher->dispatch($revalidate);

                if (!$this->em->contains($queueRow)) {
                    continue;
                }

                // Held until something else owns the air (the Top-of-Hour ID and
                // news): project it, and everything after it, from then.
                $opensAfter = $revalidate->getOpensAfter();
                if (null !== $opensAfter && $opensAfter > $expectedPlayTime) {
                    $expectedPlayTime = CarbonImmutable::instance($opensAfter);
                }

                // Re-apply a soft anchor to rows that were planned before the
                // latest live timing correction. This is especially important
                // when the actual air clock drifts relative to projected queue
                // timestamps: the last song before a programme/news boundary can
                // still be given a graceful cue-out before it is handed to Liquidsoap.
                //
                // This has to run *after* the hold above. A row waiting on the
                // Top-of-Hour ID does not start when the previous song ends, it
                // starts when the ID releases -- on the far side of the anchor,
                // where no anchor constrains it. Anchoring it to the pre-ID time
                // measured the few seconds left before the ID and stamped that on
                // the row: a 28-minute programme became a 27-second fragment and
                // its scheduled window went empty for the rest of the hour.
                $this->applyBroadcastClockCapToQueuedRow($station, $queueRow, $expectedPlayTime);
            }

            // Only use the five-second safety floor for genuinely missing/bad
            // durations. A deliberate wall-clock/clock-wheel cap may validly be
            // shorter than five seconds and must remain exact.
            $effectiveDuration = $queueRow->duration ?? 0.0;
            if (
                $effectiveDuration < 5.0
                && !$queueRow->hour_boundary_enforce_cap
                && !$queueRow->clock_wheel_enforce_cap
            ) {
                $naturalDuration = $queueRow->media?->getCalculatedLength() ?? 0.0;
                if ($naturalDuration >= 5.0) {
                    $queueRow->duration = $naturalDuration;
                    $effectiveDuration = $naturalDuration;
                } else {
                    $effectiveDuration = 5.0;
                }
            }

            $constraint = $this->resolveQueueClockConstraint(
                $station,
                CarbonImmutable::instance($expectedPlayTime),
                $effectiveDuration,
                $queueRow,
            );
            [$effectiveDuration, $nextExpectedPlayTime] = $constraint;
            if (isset($constraint[2])) {
                $expectedPlayTime = $constraint[2];
            }

            if ($queueRow->sent_to_autodj) {
                $expectedCueTime = $this->addDurationToTime(
                    $station,
                    $queueRow->timestamp_cued,
                    $effectiveDuration
                );

                if (0 === $queueLength) {
                    $queueLength = 1;
                }
            } else {
                $queueRow->timestamp_cued = $expectedCueTime;
                $expectedCueTime = $this->addDurationToTime($station, $expectedCueTime, $effectiveDuration);

                // Only append to queue length for uncued songs.
                $queueLength++;
            }

            $queueRow->timestamp_played = $expectedPlayTime;
            $this->em->persist($queueRow);

            $expectedPlayTime = $nextExpectedPlayTime;

            $lastSongId = $queueRow->song_id;
        }

        $this->em->flush();

        // Build the remainder of the queue.
        // A validator (e.g. DmcaComplianceListener) can reject a selector's pick by
        // clearing next songs; when that happens we re-dispatch a fresh BuildQueue event
        // so a selector gets another chance to choose a different track, instead of
        // silently halting queue-building and leaving the station with dead air.
        $maxAttemptsPerSlot = $isPreview ? 25 : (null !== $lookaheadMinutesOverride ? 50 : 10);
        $tracksBuiltThisRun = 0;
        $consecutivePreviewGapSeconds = 0;
        $consecutivePreviewGapSteps = 0;
        $maxPreviewGapSeconds = (max(
            60,
            $station->backend_config->duplicate_prevention_time_range,
            $station->backend_config->dmca_window_minutes ?? 180,
        ) + 60) * 60;

        while (
            $queueLength < $maxQueueLength
            || ($lookaheadHorizon !== null
                && $expectedPlayTime < $lookaheadHorizon
                && $tracksBuiltThisRun < $maxLookaheadTracks)
        ) {
            $nextSongs = [];
            $attempts = 0;

            while ($attempts < $maxAttemptsPerSlot) {
                $attempts++;

                $this->logger->debug(
                    'Adding to station queue.',
                    [
                        'now' => (string)$expectedPlayTime,
                        'attempt' => $attempts,
                    ]
                );

                // Push another test handler specifically for this one queue task.
                $testHandler = new TestHandler(LogLevel::DEBUG, true);
                $this->logger->pushHandler($testHandler);

                $event = new BuildQueue(
                    $station,
                    $expectedCueTime,
                    $expectedPlayTime,
                    $lastSongId
                );

                try {
                    $this->dispatcher->dispatch($event);
                } finally {
                    $this->logger->popHandler();
                }

                $nextSongs = $event->getNextSongs();

                // Hard backstop against the exact same ordinary song playing
                // twice in a row. Mandatory broadcast IDs are exempt: a station
                // with a one-ID library must still identify every hour, and a
                // Clock Wheel legal-ID substitute is likewise not ordinary music.
                // Only enforce this when a retry is actually possible so a
                // single-song music playlist cannot deadlock the queue.
                if (
                    !empty($nextSongs)
                    && $lastSongId !== null
                    && $attempts < $maxAttemptsPerSlot
                    && count($nextSongs) === 1
                    && !$this->isMandatoryBoundaryContent($nextSongs[0])
                    && $nextSongs[0]->song_id === $lastSongId
                ) {
                    $this->logger->debug(
                        'BuildQueue picked the same song as the immediately preceding slot; retrying.',
                        ['song_id' => $lastSongId, 'attempt' => $attempts]
                    );
                    $nextSongs = [];
                    continue;
                }

                if (!empty($nextSongs)) {
                    break;
                }

                $this->logger->debug(
                    'BuildQueue attempt produced no song (rejected by a validator); retrying.',
                    ['attempt' => $attempts]
                );
            }

            if (empty($nextSongs)) {
                if ($isPreview) {
                    // Never step past the next :00; a short tail before it is owned
                    // by the Top-of-Hour ID lane on air, so it is not dead air.
                    $gapSeconds = self::MAX_PREVIEW_GAP_STEP_SECONDS;
                    $secondsToTop = $this->hourBoundaryPlanner->secondsUntilNextTopOfHour(
                        $expectedPlayTime,
                        $station->getTimezoneObject(),
                    );
                    if ($secondsToTop > 0 && $secondsToTop < $gapSeconds) {
                        $gapSeconds = $secondsToTop;
                    }

                    // Stop at the end of the scheduled window that owns this slot
                    // and retry there, instead of writing off the rest of the hour
                    // on one failed pick. A scheduled programme is exclusive, so
                    // while its window is open nothing else may fill it -- but the
                    // moment it closes, ordinary rotation is eligible again. Taking
                    // the whole remainder in one step both over-reported the gap and
                    // skipped airtime that really would have been programmed: a
                    // programme whose audio ended 12s before its 11:00-11:59 window
                    // closed was logged as 72s of dead air, and the 59s after the
                    // window reopened was never attempted at all.
                    $secondsToWindowEnd = $this->secondsToScheduledWindowEnd($station, $expectedPlayTime);
                    if (null !== $secondsToWindowEnd && $secondsToWindowEnd < $gapSeconds) {
                        $gapSeconds = $secondsToWindowEnd;
                    }

                    $topOfHourProtected = $this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station);
                    if (
                        $topOfHourProtected
                        && $secondsToTop > 0
                        && $secondsToTop <= self::MAX_PREVIEW_GAP_STEP_SECONDS
                    ) {
                        $gapSeconds = min($gapSeconds, self::PREVIEW_RETRY_SECONDS);
                    }

                    $gapSeconds = max(self::MIN_PREVIEW_GAP_STEP_SECONDS, $gapSeconds);

                    // The final minute belongs to the ID lane's pre-fade on air.
                    $coveredByTopOfHour = $topOfHourProtected && $secondsToTop <= 60;

                    if (!$coveredByTopOfHour) {
                        $previewGaps[] = [
                            'started_at' => $expectedPlayTime->getTimestamp(),
                            'duration' => $gapSeconds,
                            'reason' => 'No eligible AutoDJ item was available for this projected slot.',
                        ];
                        $consecutivePreviewGapSeconds += $gapSeconds;
                    }
                    $consecutivePreviewGapSteps++;

                    $this->logger->warning(
                        'Linear Log preview found no eligible item; advancing the projection cursor.',
                        [
                            'attempts' => $attempts,
                            'expected_play_time' => $expectedPlayTime->format(DateTimeInterface::ATOM),
                            'step_seconds' => $gapSeconds,
                            'stepped_to_window_end' => $gapSeconds === $secondsToWindowEnd,
                            'dry_seconds' => $consecutivePreviewGapSeconds,
                        ]
                    );

                    // Advanced without the crossfade overlap that addDurationToTime()
                    // applies to real audio: there is nothing here to crossfade, and
                    // subtracting it would land the retry a moment BEFORE the very
                    // boundary this step exists to reach, so the same blocked slot
                    // would be retried forever.
                    $expectedCueTime = CarbonImmutable::instance($expectedCueTime)->addSeconds($gapSeconds);
                    $expectedPlayTime = CarbonImmutable::instance($expectedPlayTime)->addSeconds($gapSeconds);

                    if (
                        $consecutivePreviewGapSeconds >= $maxPreviewGapSeconds
                        || $consecutivePreviewGapSteps >= self::MAX_CONSECUTIVE_PREVIEW_GAP_STEPS
                    ) {
                        $this->logger->warning(
                            'Linear Log preview stopped after the station remained dry beyond its compliance window.',
                            [
                                'dry_seconds' => $consecutivePreviewGapSeconds,
                                'dry_steps' => $consecutivePreviewGapSteps,
                            ]
                        );
                        $this->em->flush();
                        break;
                    }

                    continue;
                }

                $this->logger->warning(
                    'Could not find a compliant song for queue slot after max attempts; stopping queue build.',
                    ['attempts' => $attempts]
                );
                $this->em->flush();
                break;
            }

            $consecutivePreviewGapSeconds = 0;
            $consecutivePreviewGapSteps = 0;

            foreach ($nextSongs as $queueRow) {
                // Guard against a corrupt or not-yet-analyzed media duration while
                // preserving intentional sub-five-second wall-clock caps.
                $effectiveDuration = $queueRow->duration ?? 0.0;
                if (
                    $effectiveDuration < 5.0
                    && !$queueRow->hour_boundary_enforce_cap
                    && !$queueRow->clock_wheel_enforce_cap
                ) {
                    $naturalDuration = $queueRow->media?->getCalculatedLength() ?? 0.0;
                    if ($naturalDuration >= 5.0) {
                        $this->logger->warning(
                            'Queue: restoring natural media duration instead of collapsing the projected timeline.',
                            [
                                'song_id' => $queueRow->song_id,
                                'media_id' => $queueRow->media?->id,
                                'stored_duration' => $queueRow->duration,
                                'natural_duration' => $naturalDuration,
                            ]
                        );
                        $queueRow->duration = $naturalDuration;
                        $effectiveDuration = $naturalDuration;
                    } else {
                        $this->logger->warning(
                            'Queue: song has an implausibly short or missing duration; using a floor value to prevent queue timestamp collapse.',
                            [
                                'song_id' => $queueRow->song_id,
                                'media_id' => $queueRow->media?->id,
                                'duration' => $queueRow->duration,
                            ]
                        );
                        $effectiveDuration = 5.0;
                    }
                }

                $constraint = $this->resolveQueueClockConstraint(
                    $station,
                    CarbonImmutable::instance($expectedPlayTime),
                    $effectiveDuration,
                    $queueRow,
                );
                [$effectiveDuration, $nextExpectedPlayTime] = $constraint;
                if (isset($constraint[2])) {
                    $expectedPlayTime = $constraint[2];
                }

                // A scheduled remote stream is switched off at its window end by
                // Liquidsoap, so its window may never be credited past that point:
                // a stale stream duration would otherwise push the next scheduled
                // playlist minutes late everywhere the queue timeline is read.
                if (null !== $queueRow->autodj_custom_uri && null !== $queueRow->playlist) {
                    $windowRemaining = (float)$this->scheduler->getPlaylistScheduleDuration(
                        $queueRow->playlist,
                        $expectedPlayTime
                    );

                    if ($windowRemaining < $effectiveDuration) {
                        $effectiveDuration = max(0.0, $windowRemaining);
                        $queueRow->duration = $effectiveDuration;
                        $nextExpectedPlayTime = $this->addDurationToTime(
                            $station,
                            $expectedPlayTime,
                            $effectiveDuration
                        );
                    }
                }

                $queueRow->timestamp_cued = $expectedCueTime;
                $queueRow->timestamp_played = $expectedPlayTime;
                $queueRow->updateVisibility();
                $this->em->persist($queueRow);
                $this->em->flush();

                if (!$isPreview) {
                    $this->queueLogCache->setLog($queueRow, $testHandler->getRecords());
                }

                $lastSongId = $queueRow->song_id;

                $expectedCueTime = $this->addDurationToTime(
                    $station,
                    $expectedCueTime,
                    $effectiveDuration
                );
                $expectedPlayTime = $nextExpectedPlayTime;

                $queueLength++;
                $tracksBuiltThisRun++;
            }
        }

        return $previewGaps;
    }

    /**
     * @param Station $station
     * @return StationQueue[]|null
     */
    public function getInterruptingQueue(Station $station): ?array
    {
        // Early-fail if the station is disabled.
        if (!$station->supportsAutoDjQueue()) {
            $this->logger->notice('Cannot build queue: station does not support AutoDJ queue.');
            return null;
        }

        $tzObject = $station->getTimezoneObject();
        $expectedPlayTime = CarbonImmutable::now($tzObject);

        $this->logger->debug(
            'Fetching interrupting queue.',
            [
                'now' => (string)$expectedPlayTime,
            ]
        );

        // Push another test handler specifically for this one queue task.
        $testHandler = new TestHandler(LogLevel::DEBUG, true);
        $this->logger->pushHandler($testHandler);

        $event = new BuildQueue(
            $station,
            $expectedPlayTime,
            $expectedPlayTime,
            null,
            true
        );

        try {
            $this->dispatcher->dispatch($event);
        } finally {
            $this->logger->popHandler();
        }

        $nextSongs = $event->getNextSongs();

        if (empty($nextSongs)) {
            $this->em->flush();
            return null;
        }

        foreach ($nextSongs as $queueRow) {
            $queueRow->is_played = true;
            $queueRow->timestamp_cued = $expectedPlayTime;
            $queueRow->timestamp_played = $expectedPlayTime;
            $queueRow->updateVisibility();

            $this->em->persist($queueRow);
            $this->em->flush();

            $this->queueLogCache->setLog($queueRow, $testHandler->getRecords());

            $expectedPlayTime = $this->addDurationToTime(
                $station,
                $expectedPlayTime,
                $queueRow->duration
            );
        }

        return $nextSongs;
    }

    /**
     * Resolve a generic absolute clock interruption without teaching core AutoDJ
     * what produced it. The queue row is capped at the interruption point for
     * actual playout, while the projected next-play cursor can jump over content
     * that lives in an external/plugin-owned clock lane.
     *
     * The optional third element is the item's own start when the constraint
     * moved it (it would have started while the interruption owned the air).
     *
     * @return array{0:float,1:CarbonImmutable,2?:CarbonImmutable}
     */
    private function resolveQueueClockConstraint(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        float $effectiveDuration,
        ?StationQueue $queueRow = null,
    ): array {
        // Clock ownership is about actual on-air extent, not the normal crossfade
        // overlap used to predict the following music start. This also lets a row
        // that was previously capped exactly at the clock boundary retain the
        // external occupancy jump on later queue rebuilds.
        $projectedEndAt = CarbonImmutable::instance($expectedPlayTime)->addMilliseconds(
            (int)round(max(0.0, $effectiveDuration) * 1000)
        );

        $event = new ResolveQueueClockConstraint(
            $station,
            $expectedPlayTime,
            $projectedEndAt->toDateTimeImmutable(),
            $queueRow,
        );
        $this->dispatcher->dispatch($event);

        if (!$event->hasConstraint()) {
            return [
                $effectiveDuration,
                $this->addDurationToTime($station, $expectedPlayTime, $effectiveDuration),
            ];
        }

        $interruptAt = $event->getInterruptAt();
        $resumeAt = $event->getResumeAt();
        if (null === $interruptAt || null === $resumeAt) {
            return [
                $effectiveDuration,
                $this->addDurationToTime($station, $expectedPlayTime, $effectiveDuration),
            ];
        }

        if ($event->isProjectionOnly()) {
            // An item that would start while the interruption owns the air, or
            // too close before it to be worth starting, is held and starts when
            // it releases, at its natural length (the same rule as below). One
            // that is playing when it begins yields to it, and the next item
            // follows its release.
            $secondsBeforeInterrupt = $interruptAt->getTimestamp() - $expectedPlayTime->getTimestamp();
            if ($secondsBeforeInterrupt < self::MIN_BOUNDARY_CAP_SECONDS) {
                $rowStart = CarbonImmutable::instance($resumeAt);

                return [
                    $effectiveDuration,
                    $this->addDurationToTime($station, $rowStart, $effectiveDuration),
                    $rowStart,
                ];
            }

            return [
                $effectiveDuration,
                CarbonImmutable::instance($resumeAt),
            ];
        }

        $capSeconds = max(
            1,
            $interruptAt->getTimestamp() - $expectedPlayTime->getTimestamp(),
        );

        // Nothing may start this close to a clock boundary: the boundary content
        // starts early and this row is held until it releases. Capping it to the
        // few seconds left would travel with the row and cut it to a fragment
        // when it airs after the boundary (00:09 on 2026-09-24: a 1s cap made at
        // 23:59:59). Project it after the boundary at its natural length instead.
        // The on-air song (no queue row) really is interrupted, so it is excluded.
        if (
            null !== $queueRow
            && $capSeconds < self::MIN_BOUNDARY_CAP_SECONDS
            && !$this->isMandatoryBoundaryContent($queueRow)
        ) {
            if ($queueRow->hour_boundary_enforce_cap) {
                $naturalDuration = $queueRow->media?->getCalculatedLength() ?? 0.0;
                if ($naturalDuration > 0.0) {
                    $effectiveDuration = $naturalDuration;
                }
                $queueRow->duration = $effectiveDuration;
            }
            $queueRow->hour_boundary_enforce_cap = false;
            $queueRow->hour_boundary_max_play_seconds = null;

            $rowStart = CarbonImmutable::instance($resumeAt);

            return [
                $effectiveDuration,
                $this->addDurationToTime($station, $rowStart, $effectiveDuration),
                $rowStart,
            ];
        }

        if (null !== $queueRow && !$this->isMandatoryBoundaryContent($queueRow)) {
            $queueRow->hour_boundary_enforce_cap = true;
            $queueRow->hour_boundary_max_play_seconds = $capSeconds;
            $queueRow->duration = (float)$capSeconds;
            $effectiveDuration = (float)$capSeconds;
        }

        $this->logger->debug(
            'Applied external broadcast-clock constraint to AutoDJ projection.',
            [
                'reason' => $event->getReason(),
                'expected_play_at' => $expectedPlayTime->format(DateTimeInterface::ATOM),
                'interrupt_at' => $interruptAt->format(DateTimeInterface::ATOM),
                'resume_at' => $resumeAt->format(DateTimeInterface::ATOM),
                'queue_id' => $queueRow?->id,
            ]
        );

        return [
            $effectiveDuration,
            CarbonImmutable::instance($resumeAt),
        ];
    }

    /**
     * Seconds from $at until the earliest end of a scheduled playlist window that
     * is open at $at, or null when no scheduled window covers it.
     *
     * This is the next instant at which the eligibility rules that just rejected
     * every candidate can change on their own: a scheduled programme owns its
     * window exclusively, so while it is open nothing else may be placed there,
     * and when it closes ordinary rotation becomes eligible again. The preview
     * uses it as a retry point so one blocked slot cannot write off the airtime
     * on the far side of the boundary.
     *
     * Read from the schedule occurrences rather than from
     * getPlaylistScheduleDuration(), which reports a window's FULL length instead
     * of the time left in it for any playlist carrying `allow_overrun` -- the
     * option the station's own programme playlists use, so that route reported no
     * usable boundary exactly where one was needed.
     */
    private function secondsToScheduledWindowEnd(
        Station $station,
        DateTimeInterface $at,
    ): ?int {
        $tz = $station->getTimezoneObject();
        $cursor = CarbonImmutable::instance($at)->setTimezone($tz);
        $atTs = $cursor->getTimestamp();
        $soonest = null;

        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }

            foreach ($playlist->schedule_items as $schedule) {
                $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                    $schedule,
                    $tz,
                    $cursor->subDay(),
                    $cursor->addDay(),
                );

                foreach ($occurrences as $occurrence) {
                    $startTs = $occurrence->start->getTimestamp();
                    $endTs = $occurrence->end->getTimestamp();

                    // A loop-once show releases its window the moment its single
                    // content pass is exhausted, not when the schedule clock runs
                    // out: rotation music fills the tail. The effective end is the
                    // earlier of the two. Without this, the preview cursor jumps a
                    // full MAX_PREVIEW_GAP_STEP past a finished loop-once show and
                    // reports the whole remainder of the window as a phantom gap,
                    // even though on air the next rotation song is already cued.
                    $loopOnceDuration = $this->scheduler->loopOnceContentDurationSeconds($schedule);
                    if (null !== $loopOnceDuration) {
                        $contentEndTs = $startTs + (int)ceil($loopOnceDuration);
                        if ($contentEndTs < $endTs) {
                            $endTs = $contentEndTs;
                        }
                    }

                    // Half-open, matching RigidScheduleWindowResolver: a window
                    // ending at $at no longer owns the air at $at.
                    if ($startTs > $atTs || $endTs <= $atTs) {
                        continue;
                    }

                    $remaining = $endTs - $atTs;
                    if (null === $soonest || $remaining < $soonest) {
                        $soonest = $remaining;
                    }
                }
            }
        }

        return $soonest;
    }

    private function addDurationToTime(
        Station $station,
        DateTimeInterface $now,
        ?float $duration
    ): CarbonImmutable {
        $duration ??= 1;

        $startNext = $station->backend_config->getCrossfadeDuration();

        $now = CarbonImmutable::instance($now)->addSeconds($duration);
        return ($duration >= $startNext)
            ? $now->subMilliseconds((int)($startNext * 1000))
            : $now;
    }

    private function isMandatoryBoundaryContent(StationQueue $queueRow): bool
    {
        return $queueRow->top_of_hour_legal_id
            || $queueRow->clock_wheel_legal_id_substitute;
    }

    private function applyBroadcastClockCapToQueuedRow(
        Station $station,
        StationQueue $queueRow,
        DateTimeImmutable $expectedPlayTime,
    ): void {
        if ($this->isMandatoryBoundaryContent($queueRow)) {
            return;
        }

        $media = $queueRow->media;
        if (!$media instanceof StationMedia) {
            return;
        }

        // Hour-boundary caps are projections for one particular expected start.
        // If the queue is recalculated (clock correction, restart, previous track
        // changed, etc.), restore the row to its non-hour-boundary duration first.
        // Otherwise a former 1-5 second cap becomes permanent and every later row
        // appears only a few seconds apart in Upcoming Queue.
        if ($queueRow->hour_boundary_enforce_cap) {
            $naturalDuration = $media->getCalculatedLength();

            if (
                $queueRow->clock_wheel_enforce_cap
                && null !== $queueRow->clock_wheel_max_play_seconds
                && $queueRow->clock_wheel_max_play_seconds > 0
            ) {
                $queueRow->duration = min(
                    $naturalDuration,
                    (float)$queueRow->clock_wheel_max_play_seconds,
                );
            } elseif (
                null !== $queueRow->clock_wheel_stretch_ratio
                && $queueRow->clock_wheel_stretch_ratio > 0.0
            ) {
                $queueRow->duration = $naturalDuration / $queueRow->clock_wheel_stretch_ratio;
            } else {
                $queueRow->duration = $naturalDuration;
            }
        }

        $queueRow->hour_boundary_enforce_cap = false;
        $queueRow->hour_boundary_max_play_seconds = null;

        $maxDuration = $this->broadcastClockPlanner->maxContentDurationBeforeNextSoftAnchor(
            $station,
            $expectedPlayTime,
        );
        if (null === $maxDuration || $maxDuration <= 0) {
            return;
        }

        $targetSeconds = max(1, (int)floor($maxDuration));

        // Same rule the projection path applies: a cap this small is not a trim,
        // it is a fragment. The row cannot meaningfully start before the anchor,
        // so it is held and airs after it at its natural length. Recording the
        // cap anyway is what carries a dead projection across the boundary --
        // a 28-minute programme expected at 23:59:33 was capped to 27s, then
        // actually aired at 00:00:37 (where there is no anchor at all) still
        // carrying that 27s, blanking the rest of its own scheduled window.
        if ($targetSeconds < self::MIN_BOUNDARY_CAP_SECONDS) {
            return;
        }

        $queueRow->hour_boundary_max_play_seconds = $targetSeconds;

        if ($media->getCalculatedLength() <= $targetSeconds) {
            return;
        }

        $queueRow->hour_boundary_enforce_cap = true;
        $queueRow->duration = null === $queueRow->duration
            ? (float)$targetSeconds
            : min($queueRow->duration, (float)$targetSeconds);
    }

    private function isQueueRowStillValid(
        StationQueue $queueRow,
        DateTimeImmutable $expectedPlayTime
    ): bool {
        // Mandatory boundary content must never be invalidated by a programme
        // ownership check. Its own planner decides whether the hour belongs to
        // the station-wide ID or a Clock Wheel legal-ID substitute.
        if ($this->isMandatoryBoundaryContent($queueRow)) {
            return true;
        }

        // A saved Linear Log line was chosen by the same pickers for this
        // slot; the log is the authority. Re-checking it here would drop it,
        // and every line after it, whenever re-timing nudges it across a
        // programme boundary. Rebuilds are what re-plan the log.
        if (null !== $queueRow->log_entry_id) {
            return true;
        }

        if (
            null !== $queueRow->request
            && $this->broadcastClockPlanner->areRequestsBlockedBySchedule(
                $queueRow->station,
                $expectedPlayTime,
            )
        ) {
            return false;
        }

        if (
            null !== $queueRow->clock_wheel
            && $this->broadcastClockPlanner->isProgramWindowActive(
                $queueRow->station,
                $expectedPlayTime,
            )
        ) {
            return false;
        }

        $playlist = $queueRow->playlist;
        if (null === $playlist) {
            return true;
        }

        if (
            !$playlist->is_enabled
            || !$this->scheduler->isPlaylistScheduledToPlayNow(
                $playlist,
                $expectedPlayTime,
                true
            )
        ) {
            return false;
        }

        return !$this->broadcastClockPlanner->isPlaylistPreemptedByProgram(
            $playlist,
            $expectedPlayTime,
        );
    }
}