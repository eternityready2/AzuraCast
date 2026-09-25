<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Event\Radio\RevalidateQueuedSong;
use App\Radio\AutoDJ\AiNewsScheduleForecastService;
use App\Radio\AutoDJ\AiredLength;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\AutoDJ\StretchSqueezeQueueTiming;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Requirement 1: dynamic song swapping, so the last music slot of the hour ENDS
 * on the Station ID deadline instead of being faded out mid-song.
 *
 * Runs on BuildQueue at priority -1: after the ordinary AutoDJ selector (0) has
 * made its pick under all its normal playlist/daypart/rotation rules, and before
 * the DMCA validator (-5) so the replacement is still checked like any other
 * track. Also runs on RevalidateQueuedSong, fired for every already-queued,
 * not-yet-sent row on every subsequent queue rebuild cycle (roughly every 5-15
 * seconds): a pick made here can go stale if the actual air clock drifts before
 * the row is sent to Liquidsoap, so this re-checks it against a freshly
 * projected play time each cycle instead of only ever deciding once.
 *
 * If a duration-matched track exists, the pick is replaced. If one does not, the
 * pick is left completely alone and the existing Liquidsoap pre-fade performs
 * the soft cut. Nothing here ever shortens, caps or rewrites a duration.
 *
 * The candidate search is not confined to the original pick's own playlist --
 * see getEligiblePlaylists(). A narrow playlist (fewer tracks, or tracks that
 * cluster around one length) can easily own nothing close to the exact
 * remaining gap even when a sibling playlist does, and by the final minute
 * before the ID, "close enough duration" matters far more than "same
 * playlist" for avoiding a fade.
 *
 * DELIBERATELY SELF-CONTAINED. This file reads its own settings and derives its
 * own deadline using only long-stable public API (TopOfHourClock::isEnabled,
 * ::getNextBoundary, ::getIdStartSecond, ::clockWheelOwnsBoundary). It requires
 * no edits to TopOfHourClock, the API controllers or the Vue page, so deploying
 * it cannot regress any of those files.
 */
final class TopOfHourSongSwapSelector implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    // Settings, read from the station's backend_config extra-data bag. Absent
    // keys use the defaults below, so the feature works with no configuration.
    public const string CONFIG_SWAP_ENABLED = 'top_of_hour_swap_enabled';
    public const string CONFIG_SWAP_TOLERANCE = 'top_of_hour_swap_tolerance_seconds';

    /**
     * A song due to start within this many seconds of the ID is never sent:
     * it would only be cut. It waits and opens the new hour, and Liquidsoap's
     * fit (tempo +/-3% and at most one promo, up to 45s) fills the gap.
     */
    private const float HOLD_WINDOW_SECONDS = 45.0;

    /**
     * From the ID start until this many seconds past :00 the ID/news owns the
     * air and projected times slide with the wall clock; decisions made then
     * would only be re-made seconds later. Nothing is swapped in this window.
     */
    private const int SETTLE_SECONDS_AFTER_HOUR = 360;

    /** Last stretch before the ID in which songs go to Liquidsoap one at a time. */
    private const float FINAL_APPROACH_SECONDS = 720.0;

    public const string CONFIG_SWAP_MIN_GAP = 'top_of_hour_swap_min_gap_seconds';

    private const bool DEFAULT_SWAP_ENABLED = true;
    private const float DEFAULT_TOLERANCE_SECONDS = 5.0;

    /**
     * Absolute floor for "is this the final slot of the hour". The EFFECTIVE
     * value is raised at runtime to the shortest track actually present in the
     * playlist -- see getMinFillableGap(). A fixed 45s was wrong: with a 60s
     * remainder this class would decide "another song still fits", hand the
     * slot back, and then be asked to find a 60-second track. No music
     * playlist has those, so it fell through to the fade every time. That dead
     * zone between the configured floor and the shortest real track is
     * precisely where the cuts you were hearing came from.
     */
    private const float DEFAULT_MIN_GAP_SECONDS = 60.0;

    private const float MIN_TOLERANCE_SECONDS = 1.0;
    private const float MAX_TOLERANCE_SECONDS = 30.0;
    private const float MIN_MIN_GAP_SECONDS = 15.0;
    private const float MAX_MIN_GAP_SECONDS = 600.0;

    /**
     * Extra slack on the raw `length` column when pre-filtering in SQL.
     * getCalculatedLength() subtracts cue-in/cue-out, so the stored length can
     * be longer than the audible duration we actually schedule against.
     */
    private const float SQL_PREFILTER_SLACK_SECONDS = 45.0;

    /** Rotate between this many equally-good matches so the hour doesn't rut. */
    private const int CANDIDATE_POOL_SIZE = 5;

    /**
     * Where the final song is when the ID starts. false: its last sample ends
     * on :59:59, so it finishes on its own (the station's rule: no fading or
     * cutting a song unless there is no alternative). true: its fade-out
     * begins on :59:59 and the ID covers the outro.
     */
    private const bool LAND_FADE_START_ON_ID = false;

    /** A final song landing this close needs no swap; Liquidsoap's tempo fit trims the rest. */
    private const float LANDED_TOLERANCE_SECONDS = 2.0;

    /** Largest error Liquidsoap's fit can absorb by tempo (3%, see TopOfHourRuntimeConfiguration). */
    private const float FIT_TEMPO_FRACTION = 0.03;

    /** Score penalty for a candidate whose aired length is only estimated (no AutoCue yet). */
    private const float UNKNOWN_LENGTH_PENALTY = 8.0;

    /** Score penalty for a song outside the playlists scheduled right now. */
    private const float OFF_SCHEDULE_PENALTY = 1.5;

    /**
     * Final approach: a row is held while the track ahead of it has not
     * started. "Started" is judged from projected timing, because now-playing
     * feedback lags a track change by several seconds.
     */
    private const float HOLD_SLACK_SECONDS = 20.0;

    /** @var array<string, float> sorted playlist id list => shortest track length, per request. */
    private array $shortestTrackCache = [];

    public function __construct(
        private readonly TopOfHourClock $clock,
        private readonly StationQueueRepository $queueRepo,
        private readonly Scheduler $scheduler,
        private readonly AiNewsScheduleForecastService $aiNewsForecast,
        private readonly AiredLength $airedLength,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['onBuildQueue', -1],
            RevalidateQueuedSong::class => ['onRevalidateQueuedSong', -1],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        try {
            $this->swap($event);
        } catch (\Throwable $e) {
            // A failed swap must never break queue building. Worst case the
            // original pick stands and the pre-fade handles the deadline.
            $this->logger->error(
                'Top-of-Hour swap: failed, leaving the original selection in place.',
                ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
            );
        }
    }

    public function onRevalidateQueuedSong(RevalidateQueuedSong $event): void
    {
        try {
            $this->revalidate($event);
        } catch (\Throwable $e) {
            // A failed revalidation must never break queue building. Worst
            // case the existing pick stands as previously decided.
            $this->logger->error(
                'Top-of-Hour swap: revalidation failed, leaving the queued selection in place.',
                ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
            );
        }
    }

    private function swap(BuildQueue $event): void
    {
        if ($event->isInterrupting()) {
            return;
        }

        $station = $event->getStation();
        if (!$this->clock->isEnabled($station)) {
            return;
        }
        if (!$this->isSwapEnabled($station)) {
            return;
        }

        if ($this->isSettlingAroundTopOfHour($station)) {
            return;
        }

        $nextSongs = $event->getNextSongs();
        if (1 !== count($nextSongs)) {
            return;
        }

        $row = $nextSongs[0];

        // A pick due inside the early-ID window never starts before the ID:
        // Liquidsoap starts the ID early and holds this item until the ID/news
        // releases. Keep it so it is loaded before the ID and opens the new hour
        // immediately. Dropping it left the queue empty through the ID (1am
        // 2026-09-24: every retry was dropped, and the new hour opened after
        // 5s of silence while a song was fetched and analysed).
        if ($this->wouldBeCutByTopOfHourId($station, $row, CarbonImmutable::instance($event->getExpectedPlayTime()))) {
            $this->logger->notice(
                'Top-of-Hour: pick is due inside the early-ID window; it is held and opens the new hour.',
                ['media_id' => $row->media?->id, 'start' => $event->getExpectedPlayTime()->format(DATE_ATOM)]
            );
            return;
        }

        if (!$this->isOrdinaryMusicRow($row)) {
            return;
        }

        $playlist = $row->playlist;
        $media = $row->media;
        if (!$playlist instanceof StationPlaylist || !$media instanceof StationMedia) {
            return;
        }

        $start = CarbonImmutable::instance($event->getExpectedPlayTime());

        // Check if this pick would start in the pre-fade window -- if so,
        // reject it entirely rather than letting it play briefly and get
        // hard-cut by the ID. This prevents the AutoDJ from scheduling songs
        // that will sound terrible on air.
        if ($this->isInPreFadeWindow($station, $start)) {
            $this->logger->notice(
                'Top-of-Hour swap: rejecting pick that would start in the pre-fade window.',
                [
                    'media_id' => $media->id,
                    'start' => $start->toIso8601String(),
                ]
            );
            // Detach the row so it's not persisted, and clear the event so
            // no song is queued for this slot.
            if ($this->em->contains($row)) {
                $this->em->detach($row);
            }
            $event->setNextSongs(null);
            return;
        }

        $replacement = $this->evaluateFinalSlot($station, $playlist, $media, $start);
        if (null === $replacement) {
            return;
        }
        if (false === $replacement) {
            if ($this->em->contains($row)) {
                $this->em->detach($row);
            }
            $event->setNextSongs(null);
            return;
        }

        [$spm, $replacementMedia, $context] = $replacement;

        // fromMedia() -> setSong() already stamps duration from
        // getCalculatedLength(), so it is deliberately not set again here.
        // The replacement may come from a sibling playlist (see
        // getEligiblePlaylists()), so the new row belongs to wherever it was
        // actually found, not necessarily the original pick's playlist.
        $newRow = StationQueue::fromMedia($station, $replacementMedia);
        $newRow->playlist = $spm->playlist;
        // Keep the saved linear log's link, so the log shows the line as
        // SWAPPED instead of dropped with an unlinked song in its place.
        $newRow->log_entry_id = $row->log_entry_id;
        $this->applyStretchTarget($newRow, $context);

        $spm->played($start->getTimestamp());
        $this->em->persist($spm);

        // Drop the superseded pick before Queue::buildQueue() flushes, so the
        // discarded selection can never surface as a phantom queue row.
        if ($this->em->contains($row)) {
            $this->em->detach($row);
        }

        $this->em->persist($newRow);
        $event->setNextSongs($newRow);

        $this->logger->notice(
            'Top-of-Hour swap: SWAPPED the final song of the hour for a duration-matched track.',
            $context + [
                'replacement_media_id' => $replacementMedia->id,
                'replacement_aired_length' => round($this->landingLength($replacementMedia), 2),
            ]
        );
    }

    /**
     * Re-checks an already-queued, not-yet-sent row on every queue rebuild
     * cycle against a freshly projected play time, and swaps it again if
     * live drift means the original pick (made once, at build time -- by
     * this class or by ordinary AutoDJ selection) no longer lands cleanly.
     *
     * Mutates the row in place rather than replacing it with a new one:
     * unlike onBuildQueue(), this row is already persisted and may already
     * be visible in Upcoming Queue, so swapping its song keeps the same row
     * (and id) instead of orphaning it.
     */
    private function revalidate(RevalidateQueuedSong $event): void
    {
        $station = $event->getStation();
        $row = $event->getQueueRow();

        // Unconditional confirmation this handler is actually being reached
        // at all -- debug level, so it never pollutes normal logs, but a
        // total absence of this line means RevalidateQueuedSong itself is
        // not firing/reaching this class, which is a different bug than
        // anything below bailing on it.
        $this->logger->notice(
            'Top-of-Hour swap: onRevalidateQueuedSong invoked.',
            ['queue_id' => $row->id, 'media_id' => $row->media?->id]
        );

        if (!$this->clock->isEnabled($station)) {
            return;
        }
        if (!$this->isSwapEnabled($station)) {
            return;
        }

        if ($this->isSettlingAroundTopOfHour($station)) {
            return;
        }

        // Would start just before the ID and be cut: not sent now; it opens
        // the new hour instead (the hand-off honours the hold).
        if ($this->wouldBeCutByTopOfHourId($station, $row, CarbonImmutable::instance($event->getExpectedPlayAt()))) {
            $event->holdBack();
            $event->opensAfter(
                $this->estimateTopOfHourRelease($station, CarbonImmutable::instance($event->getExpectedPlayAt()))
                    ->toDateTimeImmutable()
            );
            $this->logger->notice(
                'Top-of-Hour: holding a pick that would start just before the ID; it opens the new hour.',
                ['queue_id' => $row->id, 'media_id' => $row->media?->id, 'start' => $event->getExpectedPlayAt()->format(DATE_ATOM)]
            );
            return;
        }

        // Due to start under the ID/news lane (just after :59:59, before the
        // lane releases): it opens the new hour. It must not be loaded where
        // the final song's fade could start it a second before the ID and leave
        // it playing muted underneath (7pm 2026-09-24: "Making Room" started at
        // 6:59:58 and never aired). It loads once the lane holds AutoDJ.
        $idWindow = $this->getTopOfHourLaneWindowContaining($station, CarbonImmutable::instance($event->getExpectedPlayAt()));
        if (null !== $idWindow && $this->isHoldableOpener($row)) {
            $event->holdBack();
            $event->opensAfter($idWindow['release']->toDateTimeImmutable());
            $this->logger->notice(
                'Top-of-Hour: holding the new hour\'s first item until the ID lane holds AutoDJ.',
                [
                    'queue_id' => $row->id,
                    'media_id' => $row->media?->id,
                    'start' => $event->getExpectedPlayAt()->format(DATE_ATOM),
                    'release' => $idWindow['release']->toIso8601String(),
                ]
            );
            return;
        }

        // In the last minutes before the ID, hand songs to Liquidsoap one at a
        // time: it otherwise takes them two ahead, so the final song was
        // locked in ~12 minutes early and anything that aired in between (an
        // AI DJ clip, a trimmed intro) pushed it into the ID (3pm 2026-09-24).
        // Held here, it is re-checked when the song before it starts, with
        // the real time known. The hold is only honoured at the hand-off.
        if ($this->shouldSendOneAtATime($station, $row, CarbonImmutable::instance($event->getExpectedPlayAt()))) {
            $event->holdBack();
            // Decided at the hand-off, where the start time is exact. The
            // periodic queue projection runs on file lengths and drifted 14s by
            // 8:47pm 2026-09-24, flipping the pick back and forth every cycle.
            return;
        }

        if (!$this->isOrdinaryMusicRow($row)) {
            $this->logger->notice(
                'Top-of-Hour swap: revalidate bailed -- not an ordinary music row.',
                ['queue_id' => $row->id, 'media_id' => $row->media?->id]
            );
            return;
        }

        $playlist = $row->playlist;
        $media = $row->media;
        if (!$playlist instanceof StationPlaylist || !$media instanceof StationMedia) {
            $this->logger->notice(
                'Top-of-Hour swap: revalidate bailed -- missing playlist or media.',
                ['queue_id' => $row->id]
            );
            return;
        }

        $start = CarbonImmutable::instance($event->getExpectedPlayAt());

        // Check if this row starts in the pre-fade window -- if so, remove it
        // entirely rather than letting it play briefly and get hard-cut.
        if ($this->isInPreFadeWindow($station, $start)) {
            $this->logger->notice(
                'Top-of-Hour swap: removing queued row that starts in the pre-fade window.',
                [
                    'queue_id' => $row->id,
                    'media_id' => $media->id,
                    'start' => $start->toIso8601String(),
                ]
            );
            $this->em->remove($row);
            return;
        }

        $replacement = $this->evaluateFinalSlot($station, $playlist, $media, $start);
        if (null === $replacement) {
            return;
        }
        if (false === $replacement) {
            $this->em->remove($row);
            return;
        }

        [$spm, $replacementMedia, $context] = $replacement;

        $spm->played($start->getTimestamp());
        $this->em->persist($spm);

        // The replacement may come from a sibling playlist (see
        // getEligiblePlaylists()); keep the row's playlist reference honest.
        $row->setSong($replacementMedia);
        $row->media = $replacementMedia;
        $row->playlist = $spm->playlist;
        // The replacement was chosen to land on the ID, so the overrun cap on
        // the old song no longer applies.
        $row->hour_boundary_enforce_cap = false;
        $row->duration = $replacementMedia->getCalculatedLength();
        // Always re-applied (not only when a stretch is chosen this cycle):
        // this row is mutated in place across repeated revalidation cycles,
        // so a stretch target set on an earlier cycle must be cleared if this
        // cycle instead landed an exact duration match.
        $this->applyStretchTarget($row, $context);
        $this->em->persist($row);

        $this->logger->notice(
            'Top-of-Hour swap: RE-SWAPPED an already-queued final song after the air clock drifted.',
            $context + [
                'queue_id' => $row->id,
                'replacement_media_id' => $replacementMedia->id,
                'replacement_aired_length' => round($this->landingLength($replacementMedia), 2),
            ]
        );
    }

    /**
     * Shared decision: is $media, starting at $start, actually the final
     * music slot of the hour, and if it no longer lands cleanly, is there a
     * duration-matched replacement? Every exit is logged so the feature
     * never looks silently inert.
     *
     * @return array{StationPlaylistMedia, StationMedia, array<string, mixed>}|null
     *         null means: leave the current pick alone (not yet the final
     *         slot, too little of the hour left, it already lands fine, or
     *         no replacement exists). false means: drop this slot -- nothing
     *         fits before the ID, so anything played here would be cut.
     */
    private function evaluateFinalSlot(
        Station $station,
        StationPlaylist $playlist,
        StationMedia $media,
        CarbonImmutable $start,
    ): array|false|null {
        $originalLanding = $this->landingLength($media);
        if ($originalLanding <= 0.0) {
            $this->logger->notice(
                'Top-of-Hour swap: evaluateFinalSlot bailed -- media has no calculated length.',
                ['media_id' => $media->id]
            );
            return null;
        }

        $boundary = CarbonImmutable::instance($this->clock->getNextBoundary($station, $start));
        $target = $boundary
            ->subMinute()
            ->startOfMinute()
            ->addSeconds($this->clock->getIdStartSecond($station));

        if ($this->clock->clockWheelOwnsBoundary($station, $boundary->toDateTimeImmutable())) {
            $this->logger->notice(
                'Top-of-Hour swap: evaluateFinalSlot bailed -- a Clock Wheel owns this boundary.',
                ['media_id' => $media->id, 'boundary' => $boundary->toIso8601String()]
            );
            return null;
        }

        // Seconds from this slot's start to the ID. The final song must fill
        // exactly that: measured by its aired length (AutoCue cue points), not
        // its file length, which ran 5s long on average and 17s at worst.
        $gap = $this->secondsBetween($start, $target);
        if ($gap <= 0.0) {
            $this->logger->notice(
                'Top-of-Hour swap: evaluateFinalSlot bailed -- start is already past the ID target.',
                [
                    'media_id' => $media->id,
                    'start' => $start->toIso8601String(),
                    'target' => $target->toIso8601String(),
                ]
            );
            return null;
        }

        $this->logger->notice(
            'Top-of-Hour swap: evaluateFinalSlot reached.',
            [
                'media_id' => $media->id,
                'playlist' => $playlist->name,
                'start' => $start->toIso8601String(),
                'target' => $target->toIso8601String(),
                'gap' => round($gap, 2),
                'aired_length' => round($originalLanding, 2),
                'remainder' => round($gap - $originalLanding, 2),
            ]
        );

        $playlists = $this->getEligiblePlaylists($station, $start, $playlist);

        $tolerance = $this->getToleranceSeconds($station);
        $minGap = $this->getMinFillableGap($station, $playlists);

        // Is this actually the last music slot of the hour? It is, if the chosen
        // track either crosses the deadline or leaves behind a remainder too
        // short for another whole song to occupy.
        $remainder = $gap - $originalLanding;
        if ($remainder > $minGap) {
            $this->logger->notice(
                'Top-of-Hour swap: evaluateFinalSlot bailed -- not yet the final slot '
                . '(remainder exceeds the minimum fillable gap).',
                [
                    'media_id' => $media->id,
                    'remainder' => round($remainder, 2),
                    'min_gap' => round($minGap, 2),
                ]
            );
            return null;
        }

        $context = [
            'playlist' => $playlist->name,
            'playlist_id' => $playlist->id,
            'slot_starts_at' => $start->toIso8601String(),
            'id_deadline_at' => $target->toIso8601String(),
            'seconds_to_fill' => round($gap, 2),
            'original_media_id' => $media->id,
            'original_aired_length' => round($originalLanding, 2),
            'original_overshoot' => round(-$remainder, 2),
            'tolerance' => $tolerance,
            'min_fillable_gap' => round($minGap, 2),
        ];

        if ($gap < $minGap) {
            // Too little of the hour left for a whole song. Take the longest
            // item (music first, then promos/sweepers) that still ends before
            // the ID; if nothing fits, drop the slot so nothing is cut.
            $overshoot = $originalLanding - $gap;
            if ($overshoot <= $tolerance) {
                return null;
            }

            $fitLimit = $gap + $tolerance;
            $best = $this->findBestFitMedia($station, $playlists, $media->id, $start, $fitLimit);
            $shortForm = $this->findBestFitMedia(
                $station,
                $this->getShortFormFallbackPlaylists($station),
                $media->id,
                $start,
                $fitLimit,
            );
            if (
                null !== $shortForm
                && (null === $best || $this->landingLength($shortForm[1]) > $this->landingLength($best[1]))
            ) {
                $best = $shortForm;
            }

            if (null !== $best) {
                [$spm, $replacementMedia, $isRepeat] = $best;
                $bestLength = $this->landingLength($replacementMedia);

                $this->logger->notice(
                    'Top-of-Hour swap: too little of the hour left for a whole song; '
                    . 'using the best-fitting item that ends cleanly before the ID.',
                    $context + [
                        'replacement_media_id' => $replacementMedia->id,
                        'replacement_aired_length' => round($bestLength, 2),
                        'landing_error' => round($gap - $bestLength, 2),
                        'is_repeat_pick' => $isRepeat,
                    ]
                );

                return [$spm, $replacementMedia, $context + [
                    'is_repeat_pick' => $isRepeat,
                    'landing_error' => round($gap - $bestLength, 2),
                ]];
            }

            $this->logger->notice(
                'Top-of-Hour swap: nothing fits the remaining gap; dropping this slot so nothing is cut by the ID.',
                $context + ['fit_limit' => round($fitLimit, 2)]
            );
            return false;
        }

        if (abs($remainder) <= min($tolerance, self::LANDED_TOLERANCE_SECONDS)) {
            $this->logger->notice(
                'Top-of-Hour swap: the selected track already lands on the deadline; no swap needed.',
                $context
            );
            return null;
        }

        // Search every music file in the library (not only the playlist the
        // pick came from) for the one whose aired length lands closest to the
        // ID. Whatever small error is left, Liquidsoap's fit removes with a
        // pitch-preserving tempo change of at most 3%.
        $maxError = max(min($tolerance, self::LANDED_TOLERANCE_SECONDS), $gap * self::FIT_TEMPO_FRACTION);
        $match = $this->findLibraryMatch($station, $playlists, $gap, $maxError, $media->id, $start);

        if (null !== $match && abs($match['error']) < abs($remainder) - 0.5) {
            $this->logger->notice(
                'Top-of-Hour swap: found a song in the library that ends on the ID.',
                $context + [
                    'replacement_media_id' => $match['media']->id,
                    'replacement_aired_length' => round($match['length'], 2),
                    'landing_error' => round($match['error'], 2),
                    'length_known' => $match['known'],
                    'is_repeat_pick' => $match['is_repeat'],
                ]
            );

            return [$match['spm'], $match['media'], $context + [
                'is_repeat_pick' => $match['is_repeat'],
                'landing_error' => round($match['error'], 2),
            ]];
        }

        if (abs($remainder) <= $maxError) {
            // Nothing in the library beats it and Liquidsoap's tempo fit can
            // absorb what is left, so keep it.
            $this->logger->notice(
                'Top-of-Hour swap: keeping the selected track; the tempo fit absorbs the remaining error.',
                $context
            );
            return null;
        }

        $stretchReplacement = $this->findStretchMatchedMedia(
            $station,
            $playlists,
            $gap,
            $media->id,
            $start,
        );

        if (null === $stretchReplacement) {
            $this->logger->warning(
                'Top-of-Hour swap: NO song in the library lands on the ID, even with stretch/squeeze; '
                . 'Liquidsoap fit and pre-fade will handle it.',
                $context
            );
            return null;
        }

        [$spm, $replacementMedia, $ratio, $isRepeat] = $stretchReplacement;

        $stretchContext = $context + [
            'is_repeat_pick' => $isRepeat,
            'stretch_target_seconds' => round($gap, 2),
            'stretch_ratio' => round($ratio, 4),
        ];

        $this->logger->notice(
            'Top-of-Hour swap: no library song within the tempo range; using pitch-preserving '
            . 'stretch/squeeze to land a nearby track on the deadline instead.',
            $stretchContext + ['replacement_media_id' => $replacementMedia->id]
        );

        return [$spm, $replacementMedia, $stretchContext];
    }

    /**
     * The length the final song must fill before the ID: its aired length
     * (AutoCue cue_out - cue_in), less its fade-out when the ID is meant to
     * cover the outro.
     */
    private function landingLength(StationMedia $media): float
    {
        $aired = $this->airedLength->forMedia($media);

        return self::LAND_FADE_START_ON_ID
            ? max(0.0, $aired['length'] - $aired['fade_out'])
            : $aired['length'];
    }

    /**
     * Every music file in the station library that belongs to an enabled
     * playlist, scored by how closely its aired length fills $neededSeconds.
     * Songs whose length AutoCue has measured, songs in the playlists scheduled
     * now, and songs not played recently are preferred, in that order of weight.
     *
     * @param list<StationPlaylist> $eligiblePlaylists
     * @return array{spm: StationPlaylistMedia, media: StationMedia, length: float, error: float, known: bool, is_repeat: bool}|null
     */
    private function findLibraryMatch(
        Station $station,
        array $eligiblePlaylists,
        float $neededSeconds,
        float $maxError,
        ?int $excludeMediaId,
        CarbonImmutable $expectedPlayTime,
    ): ?array {
        if ($neededSeconds <= 0.0) {
            return null;
        }

        $eligibleIds = [];
        foreach ($eligiblePlaylists as $eligible) {
            $eligibleIds[$eligible->id] = true;
        }

        // Aired length is at most the file length and, measured on this
        // library, never more than ~20s under it.
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT spm, m, p FROM App\Entity\StationPlaylistMedia spm
                JOIN spm.media m
                JOIN spm.playlist p
                WHERE p.station = :station
                AND p.is_enabled = true
                AND p.is_jingle = false
                AND p.type = :standard
                AND m.type = :music
                AND m.length >= :minLength
                AND m.length <= :maxLength
            DQL
        )->setParameter('station', $station)
            ->setParameter('standard', PlaylistTypes::Standard->value)
            ->setParameter('music', 'music')
            ->setParameter('minLength', $neededSeconds - $maxError)
            ->setParameter('maxLength', $neededSeconds + $maxError + self::SQL_PREFILTER_SLACK_SECONDS)
            ->getResult();

        // One entry per song: prefer its membership in a playlist scheduled now,
        // then the least recently played membership.
        $byMedia = [];
        foreach ($rows as $spm) {
            if (!$spm instanceof StationPlaylistMedia) {
                continue;
            }
            $candidateMedia = $spm->media;
            if ($candidateMedia->id === $excludeMediaId) {
                continue;
            }

            $onSchedule = isset($eligibleIds[$spm->playlist->id]);
            $existing = $byMedia[$candidateMedia->id] ?? null;
            if (
                null === $existing
                || ($onSchedule && !$existing['on_schedule'])
                || ($onSchedule === $existing['on_schedule'] && $spm->last_played < $existing['spm']->last_played)
            ) {
                $byMedia[$candidateMedia->id] = ['spm' => $spm, 'on_schedule' => $onSchedule];
            }
        }

        if ([] === $byMedia) {
            return null;
        }

        $recentSongIds = $this->getRecentlySelectedSongIds($station, $expectedPlayTime);

        $fresh = [];
        $repeats = [];
        foreach ($byMedia as $entry) {
            $spm = $entry['spm'];
            $candidateMedia = $spm->media;
            $aired = $this->airedLength->forMedia($candidateMedia);
            $length = self::LAND_FADE_START_ON_ID
                ? max(0.0, $aired['length'] - $aired['fade_out'])
                : $aired['length'];
            if ($length <= 0.0) {
                continue;
            }

            // Positive: ends before the ID (slowed slightly to fill). Negative:
            // runs past it (sped up slightly). Both are fixed by the tempo fit;
            // finishing early is preferred because it can never be cut.
            $error = $neededSeconds - $length;
            if (abs($error) > $maxError) {
                continue;
            }

            $score = abs($error)
                + ($error < 0.0 ? abs($error) * 0.25 : 0.0)
                + ($aired['known'] ? 0.0 : self::UNKNOWN_LENGTH_PENALTY)
                + ($entry['on_schedule'] ? 0.0 : self::OFF_SCHEDULE_PENALTY);

            $candidate = [
                'score' => $score,
                'spm' => $spm,
                'media' => $candidateMedia,
                'length' => $length,
                'error' => $error,
                'known' => $aired['known'],
                'last_played' => $spm->last_played,
            ];

            if (isset($recentSongIds[$candidateMedia->song_id])) {
                $repeats[] = $candidate;
            } else {
                $fresh[] = $candidate;
            }
        }

        $isRepeat = [] === $fresh;
        $candidates = $isRepeat ? $repeats : $fresh;
        if ([] === $candidates) {
            return null;
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => ($a['score'] <=> $b['score'])
                ?: ($a['last_played'] <=> $b['last_played'])
        );

        // Rotate among near-equal matches so the same song does not close every hour.
        $bestScore = $candidates[0]['score'];
        $pool = array_values(array_filter(
            array_slice($candidates, 0, self::CANDIDATE_POOL_SIZE),
            static fn (array $c): bool => $c['score'] <= $bestScore + 0.5
        ));
        $chosen = [] === $pool ? $candidates[0] : $pool[random_int(0, count($pool) - 1)];

        return [
            'spm' => $chosen['spm'],
            'media' => $chosen['media'],
            'length' => $chosen['length'],
            'error' => $chosen['error'],
            'known' => $chosen['known'],
            'is_repeat' => $isRepeat,
        ];
    }

    /**
     * Fallback for when no track lands within plain duration tolerance: find
     * the eligible candidate that needs the SMALLEST pitch-preserving speed
     * change to land exactly on $neededSeconds, and only return it if that
     * change is within the station's own configured stretch/squeeze safety
     * limit (the same limit StretchSqueezeQueueTiming enforces for Clock
     * Wheel and scheduled-playlist boundaries -- capped at 5% either way).
     * Prefers non-repeat candidates first, same policy as the exact-match
     * search above.
     *
     * @param list<StationPlaylist> $playlists
     * @return array{StationPlaylistMedia, StationMedia, float, bool}|null
     *         The float is the stretch ratio (calculatedLength / target) that
     *         the caller must record so StretchSqueezeQueueTiming applies it.
     */
    private function findStretchMatchedMedia(
        Station $station,
        array $playlists,
        float $neededSeconds,
        ?int $excludeMediaId,
        CarbonImmutable $expectedPlayTime,
    ): ?array {
        if ([] === $playlists || $neededSeconds <= 0.0) {
            return null;
        }

        $percents = $this->getStretchSqueezePercents($station);
        if (null === $percents) {
            // Feature disabled at the station.
            return null;
        }
        [$stretchPercent, $squeezePercent] = $percents;

        // ratio = candidate's natural length / neededSeconds. Below 1, the
        // candidate is shorter than the gap and must be slowed down to fill
        // it (stretch); above 1, it is longer and must be sped up to fit
        // (squeeze) -- the same two independently-configured directions
        // StretchSqueezeQueueTiming applies when it actually plays this back.
        $minRatio = 1.0 - ($stretchPercent / 100);
        $maxRatio = 1.0 + ($squeezePercent / 100);

        // Any candidate whose natural length falls in this band can be
        // stretched or squeezed onto $neededSeconds without exceeding the
        // station's safety limit. Widened further for the SQL prefilter the
        // same way findLibraryMatch() is, since m.length (raw) can
        // run longer than the length that actually airs.
        $minLength = $neededSeconds * $minRatio;
        $maxLength = ($neededSeconds * $maxRatio) + self::SQL_PREFILTER_SLACK_SECONDS;

        $recentSongIds = $this->getRecentlySelectedSongIds($station, $expectedPlayTime);

        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT spm, m FROM App\Entity\StationPlaylistMedia spm
                JOIN spm.media m
                WHERE spm.playlist IN (:playlists)
                AND m.length >= :minLength
                AND m.length <= :maxLength
                AND m.type NOT IN (:idTypes)
                ORDER BY spm.last_played ASC, spm.id ASC
            DQL
        )->setParameter('playlists', $playlists)
            ->setParameter('minLength', $minLength)
            ->setParameter('maxLength', $maxLength)
            ->setParameter('idTypes', StationMediaTypes::stationIdTypeValues())
            ->getResult();

        $fresh = [];
        $repeats = [];

        foreach ($rows as $spm) {
            if (!$spm instanceof StationPlaylistMedia) {
                continue;
            }

            $candidateMedia = $spm->media;
            if ($candidateMedia->id === $excludeMediaId) {
                continue;
            }

            $length = $candidateMedia->getCalculatedLength();
            if ($length <= 0.0) {
                continue;
            }

            $ratio = $length / $neededSeconds;
            if ($ratio < $minRatio || $ratio > $maxRatio) {
                continue;
            }

            $entry = [
                // Smallest stretch amount wins -- the whole point is to be as
                // close to natural playback as possible while still landing
                // exactly on the deadline.
                'score' => abs($ratio - 1.0),
                'spm' => $spm,
                'media' => $candidateMedia,
                'ratio' => $ratio,
                'last_played' => $spm->last_played,
            ];

            if (isset($recentSongIds[$candidateMedia->song_id])) {
                $repeats[] = $entry;
            } else {
                $fresh[] = $entry;
            }
        }

        $isRepeat = [] === $fresh;
        $candidates = $isRepeat ? $repeats : $fresh;

        if ([] === $candidates) {
            return null;
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => ($a['score'] <=> $b['score'])
                ?: ($a['last_played'] <=> $b['last_played'])
        );

        $pool = array_slice($candidates, 0, self::CANDIDATE_POOL_SIZE);
        $chosen = $pool[random_int(0, count($pool) - 1)];

        return [$chosen['spm'], $chosen['media'], $chosen['ratio'], $isRepeat];
    }

    /**
     * Absolute last resort before giving up entirely: the single shortest
     * calculated-length track across the eligible playlists, regardless of
     * how far it is from the needed duration. Used only when the remaining
     * gap is too small for a properly matched (exact or stretched)
     * replacement -- at that point the choice is not "land cleanly vs. not,"
     * it is "shrink the overshoot vs. leave it," so nothing here scores or
     * filters by duration the way the other two searches do. Still respects
     * the station's normal repeat-prevention window when possible, falling
     * back to a repeat only if every eligible track was played too recently.
     *
     * @param list<StationPlaylist> $playlists
     * @return array{StationPlaylistMedia, StationMedia, bool}|null The
     *         trailing bool is true when the pick only exists because the
     *         repeat-prevention window was overridden, same meaning as in
     *         findLibraryMatch().
     */
    /**
     * The longest eligible item that still fits in $maxLength seconds, i.e. the
     * one that ends closest to the ID without being cut. Prefers items not
     * recently played; falls back to a recent one only if nothing fresh fits.
     *
     * @param list<StationPlaylist> $playlists
     * @return array{StationPlaylistMedia, StationMedia, bool}|null
     */
    private function findBestFitMedia(
        Station $station,
        array $playlists,
        ?int $excludeMediaId,
        CarbonImmutable $expectedPlayTime,
        float $maxLength,
    ): ?array {
        if ([] === $playlists || $maxLength <= 0.0) {
            return null;
        }

        $recentSongIds = $this->getRecentlySelectedSongIds($station, $expectedPlayTime);

        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT spm, m FROM App\Entity\StationPlaylistMedia spm
                JOIN spm.media m
                WHERE spm.playlist IN (:playlists)
                AND m.length > 0
                AND m.length <= :maxLength
                AND m.type NOT IN (:idTypes)
                ORDER BY m.length DESC, spm.last_played ASC, spm.id ASC
            DQL
        )->setParameter('playlists', $playlists)
            ->setParameter('maxLength', $maxLength + self::SQL_PREFILTER_SLACK_SECONDS)
            ->setParameter('idTypes', StationMediaTypes::stationIdTypeValues())
            ->setMaxResults(100)
            ->getResult();

        // Judged by aired length: the longest item that still ends before the ID.
        $bestFresh = null;
        $bestAny = null;
        foreach ($rows as $spm) {
            if (!$spm instanceof StationPlaylistMedia) {
                continue;
            }
            $candidateMedia = $spm->media;
            if ($candidateMedia->id === $excludeMediaId) {
                continue;
            }
            $length = $this->landingLength($candidateMedia);
            if ($length <= 0.0 || $length > $maxLength) {
                continue;
            }

            if (null === $bestAny || $length > $bestAny[3]) {
                $bestAny = [$spm, $candidateMedia, true, $length];
            }
            if (!isset($recentSongIds[$candidateMedia->song_id]) && (null === $bestFresh || $length > $bestFresh[3])) {
                $bestFresh = [$spm, $candidateMedia, false, $length];
            }
        }

        $best = $bestFresh ?? $bestAny;

        return null === $best ? null : [$best[0], $best[1], $best[2]];
    }

    private function findShortestAvailableMedia(
        Station $station,
        array $playlists,
        ?int $excludeMediaId,
        CarbonImmutable $expectedPlayTime,
    ): ?array {
        if ([] === $playlists) {
            return null;
        }

        $recentSongIds = $this->getRecentlySelectedSongIds($station, $expectedPlayTime);

        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT spm, m FROM App\Entity\StationPlaylistMedia spm
                JOIN spm.media m
                WHERE spm.playlist IN (:playlists)
                AND m.length > 0
                AND m.type NOT IN (:idTypes)
                ORDER BY m.length ASC, spm.last_played ASC, spm.id ASC
            DQL
        )->setParameter('playlists', $playlists)
            ->setParameter('idTypes', StationMediaTypes::stationIdTypeValues())
            ->setMaxResults(50)
            ->getResult();

        $fresh = null;
        $anyPlayable = null;

        foreach ($rows as $spm) {
            if (!$spm instanceof StationPlaylistMedia) {
                continue;
            }

            $candidateMedia = $spm->media;
            if ($candidateMedia->id === $excludeMediaId) {
                continue;
            }

            if ($candidateMedia->getCalculatedLength() <= 0.0) {
                continue;
            }

            $anyPlayable ??= [$spm, $candidateMedia, true];

            if (!isset($recentSongIds[$candidateMedia->song_id])) {
                $fresh = [$spm, $candidateMedia, false];
                break;
            }
        }

        return $fresh ?? $anyPlayable;
    }

    /**
     * The station's configured stretch and squeeze safety limits -- delegates
     * to StretchSqueezeQueueTiming::getStretchSqueezePercents() so this search
     * never offers a ratio that layer would then refuse to honor, and so the
     * two directions' independent caps (and their fallback to the single
     * legacy setting) are defined in exactly one place. Returns null when the
     * feature is disabled at the station -- callers must skip the stretch
     * fallback entirely in that case, not silently compute an unused ratio.
     *
     * @return array{0: float, 1: float}|null [stretchPercent, squeezePercent]
     */
    private function getStretchSqueezePercents(Station $station): ?array
    {
        $raw = $station->backend_config->toArray(true) ?? [];

        $enabled = (bool)($raw['playout_stretch_squeeze_enabled'] ?? true);
        if (!$enabled) {
            return null;
        }

        return StretchSqueezeQueueTiming::getStretchSqueezePercents($raw);
    }

    /**
     * Only ordinary AutoDJ music is eligible. IDs, legal-ID substitutes, Clock
     * Wheel rows, listener requests and any row already carrying an explicit
     * wall-clock cap are left alone.
     */
    private function isOrdinaryMusicRow(StationQueue $row): bool
    {
        if ($row->top_of_hour_legal_id || $row->clock_wheel_legal_id_substitute) {
            return false;
        }
        if (null !== $row->clock_wheel || null !== $row->request) {
            return false;
        }
        // A Clock Wheel cap is a deliberate programming choice. An hour-boundary
        // cap is only the queue's fallback for a song that overruns the ID, which
        // is exactly the row the swap exists to fix: excluding it meant the
        // midnight 2026-09-24 overrun was capped and faded instead of swapped.
        if ($row->clock_wheel_enforce_cap) {
            return false;
        }
        if (null !== $row->autodj_custom_uri) {
            return false;
        }
        if (null === $row->media) {
            return false;
        }

        return !StationMediaTypes::isStationId($row->media->type);
    }

    /**
     * @return array<string, true>
     */
    private function getRecentlySelectedSongIds(
        Station $station,
        CarbonImmutable $expectedPlayTime,
    ): array {
        $recent = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime->toDateTimeImmutable(),
            max(30, $station->backend_config->duplicate_prevention_time_range),
        );

        $ids = [];
        foreach ($recent as $entry) {
            $songId = $entry['song_id'] ?? null;
            if (is_string($songId) && '' !== $songId) {
                $ids[$songId] = true;
            }
        }

        return $ids;
    }

    /**
     * The shortest gap worth handing to another whole song, across every
     * currently-eligible playlist.
     *
     * Anything shorter must be treated as the final slot and solved by swapping
     * the current pick, because no track anywhere eligible could fill the
     * remainder. Without this, a remainder that falls between the configured
     * floor and the shortest real track is unfillable by construction and
     * always degrades to the fade.
     *
     * @param list<StationPlaylist> $playlists
     */
    private function getMinFillableGap(Station $station, array $playlists): float
    {
        $configured = $this->getMinGapSeconds($station);

        if ([] === $playlists) {
            return min($configured, self::MAX_MIN_GAP_SECONDS);
        }

        $ids = array_map(static fn (StationPlaylist $p): int => $p->id, $playlists);
        sort($ids);
        $cacheKey = implode(',', $ids);

        if (!isset($this->shortestTrackCache[$cacheKey])) {
            $shortest = $this->em->createQuery(
                <<<'DQL'
                    SELECT MIN(m.length) FROM App\Entity\StationPlaylistMedia spm
                    JOIN spm.media m
                    WHERE spm.playlist IN (:playlists) AND m.length > 0
                DQL
            )->setParameter('playlists', $playlists)
                ->getSingleScalarResult();

            $this->shortestTrackCache[$cacheKey] = (float)($shortest ?? 0.0);
        }

        $shortest = $this->shortestTrackCache[$cacheKey];

        // Cap the adaptive raise so one very long outlier in a small playlist
        // cannot make every slot look like the final one.
        return min(max($configured, $shortest), self::MAX_MIN_GAP_SECONDS);
    }

    /**
     * Every enabled, non-jingle, ordinary rotation playlist currently
     * scheduled to play, always including the original pick's own playlist
     * (it is eligible by construction -- it was just chosen from it).
     * Special-purpose playlists (jingles, once-per-X insertions, custom/
     * advanced scheduling) are excluded: they are not "just more music" and
     * should not be reached for merely because their length happens to fit.
     *
     * @return list<StationPlaylist>
     */
    private function getEligiblePlaylists(
        Station $station,
        CarbonImmutable $start,
        StationPlaylist $originalPlaylist,
    ): array {
        $eligible = [$originalPlaylist->id => $originalPlaylist];

        foreach ($station->playlists as $candidate) {
            if (!$candidate instanceof StationPlaylist) {
                continue;
            }
            if (isset($eligible[$candidate->id])) {
                continue;
            }
            if (!$candidate->is_enabled || $candidate->is_jingle) {
                continue;
            }
            if (PlaylistTypes::Standard !== $candidate->type) {
                continue;
            }
            if (
                !$this->scheduler->isPlaylistScheduledToPlayNow(
                    $candidate,
                    $start->toDateTimeImmutable(),
                    true
                )
            ) {
                continue;
            }

            $eligible[$candidate->id] = $candidate;
        }

        return array_values($eligible);
    }

    /**
     * Enabled jingle/insert pools (promos, sweepers, station imaging) --
     * deliberately excluded from getEligiblePlaylists() and every normal
     * duration-matched or shortest-available search above, because they are
     * not "just more music" and should not be reached for merely because
     * their length happens to fit. Used ONLY as the very last resort in the
     * evaluateFinalSlot() `$gap < $minGap` branch, when even the shortest
     * regular song in the whole eligible catalog is still longer than the
     * current pick -- i.e. nothing about a normal rotation choice can help
     * this slot no matter what. Real Station ID audio is still excluded from
     * whatever this returns via the caller's own `m.type NOT IN idTypes`
     * filter, regardless of which playlist it happens to live in.
     *
     * @return list<StationPlaylist>
     */
    private function getShortFormFallbackPlaylists(Station $station): array
    {
        $eligible = [];

        foreach ($station->playlists as $candidate) {
            if (!$candidate instanceof StationPlaylist) {
                continue;
            }
            if (!$candidate->is_enabled || !$candidate->is_jingle) {
                continue;
            }

            $eligible[$candidate->id] = $candidate;
        }

        return array_values($eligible);
    }

    /**
     * When evaluateFinalSlot() chose a stretch-matched replacement, stamp the
     * exact deadline onto the row as its `hour_boundary_max_play_seconds` --
     * StretchSqueezeQueueTiming (BuildQueue priority -6, runs after this
     * class) reads that field as a safe backtiming target and computes the
     * pitch-preserving ratio from it, the same mechanism Clock Wheel and
     * scheduled-playlist boundaries already use. This class only ever
     * supplies the target; the actual ratio math and the station's safety
     * clamp both live in that one place. Explicitly clears the field when
     * $context carries no stretch target, since revalidate() reuses the same
     * row across cycles and a stale target from an earlier cycle must not
     * survive into a cycle that landed an exact match instead.
     *
     * @param array<string, mixed> $context
     */
    private function applyStretchTarget(StationQueue $row, array $context): void
    {
        $target = $context['stretch_target_seconds'] ?? null;
        $row->hour_boundary_max_play_seconds = is_numeric($target) ? (int)round((float)$target) : null;
    }

    private function isSwapEnabled(Station $station): bool
    {
        $raw = $station->backend_config->toArray(true) ?? [];

        if (!array_key_exists(self::CONFIG_SWAP_ENABLED, $raw)) {
            return self::DEFAULT_SWAP_ENABLED;
        }

        $value = $raw[self::CONFIG_SWAP_ENABLED];
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string)$value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Within FINAL_APPROACH_SECONDS of the ID, a row is not sent while the
     * track loaded ahead of it in Liquidsoap has not started yet, so the final
     * song is chosen with the real start time known.
     *
     * "Started" is judged by projected timing, not current_song: Liquidsoap asks
     * for the next song the moment a track starts, but its now-playing feedback
     * arrives ~6s later, so current_song is still the track that just ended.
     * The old test ("the song on air ends within 25s") therefore always passed
     * at hand-off and the final song went out two ahead (8pm 2026-09-24: sent at
     * 7:47:16, aired 7:55:23, landed 18s short). A short track ahead is never
     * held behind, so AutoDJ cannot run dry.
     */
    private function shouldSendOneAtATime(Station $station, StationQueue $row, CarbonImmutable $start): bool
    {
        if ($row->top_of_hour_legal_id || $row->clock_wheel_legal_id_substitute || null !== $row->request) {
            return false;
        }

        $boundary = CarbonImmutable::instance($this->clock->getNextBoundary($station, $start));
        if ($this->clock->clockWheelOwnsBoundary($station, $boundary->toDateTimeImmutable())) {
            return false;
        }
        $target = $boundary->subMinute()->startOfMinute()->addSeconds($this->clock->getIdStartSecond($station));
        $gap = $this->secondsBetween($start, $target);
        if ($gap <= 0.0 || $gap > self::FINAL_APPROACH_SECONDS) {
            return false;
        }

        $ahead = null;
        foreach ($this->queueRepo->getUnairedSentRows($station) as $sentRow) {
            if ($sentRow->id !== $row->id) {
                $ahead = $sentRow;
            }
        }
        if (null === $ahead) {
            return false;
        }

        $aheadLength = $this->airedLength->lengthOf($ahead->media, $ahead->duration);
        $secondsUntilDue = $this->secondsBetween(CarbonImmutable::now(), $start);
        if ($secondsUntilDue <= $aheadLength + self::HOLD_SLACK_SECONDS) {
            return false;
        }

        $this->logger->notice(
            'Top-of-Hour: final approach; holding this pick until the song ahead of it starts.',
            [
                'queue_id' => $row->id,
                'media_id' => $row->media?->id,
                'start' => $start->toIso8601String(),
                'gap' => round($gap, 1),
                'ahead_queue_id' => $ahead->id,
                'ahead_aired_length' => round($aheadLength, 1),
                'seconds_until_due' => round($secondsUntilDue, 1),
            ]
        );
        return true;
    }

    /**
     * The Top-of-Hour lane window $start falls in, if any: from the ID target
     * (:59:ss) until the lane is expected to release (ID length, plus top-hour
     * news when it airs), which can run past :00.
     *
     * @return array{target: CarbonImmutable, release: CarbonImmutable}|null
     */
    private function getTopOfHourLaneWindowContaining(Station $station, CarbonImmutable $start): ?array
    {
        $boundary = CarbonImmutable::instance(
            $this->clock->getNextBoundary($station, $start->subMinutes(10)->toDateTimeImmutable())
        );
        if ($this->clock->clockWheelOwnsBoundary($station, $boundary->toDateTimeImmutable())) {
            return null;
        }

        $target = $boundary->subMinute()->startOfMinute()->addSeconds($this->clock->getIdStartSecond($station));
        if ($start->lessThan($target->subSecond())) {
            return null;
        }

        $release = $this->estimateTopOfHourRelease($station, $target->subSecond());
        if ($start->greaterThanOrEqualTo($release)) {
            return null;
        }

        return ['target' => $target, 'release' => $release];
    }

    private function isHoldableOpener(StationQueue $row): bool
    {
        if ($row->top_of_hour_legal_id || $row->clock_wheel_legal_id_substitute) {
            return false;
        }
        if (null !== $row->clock_wheel || null !== $row->request) {
            return false;
        }
        $media = $row->media;

        return $media instanceof StationMedia && !StationMediaTypes::isStationId($media->type);
    }

    /**
     * When the ID lane will hand the air back: the ID start plus the ID's
     * length plus, when top-hour AI News airs, the recent bulletins' average
     * length. Never before :00 (the lane owns the air until then).
     */
    private function estimateTopOfHourRelease(Station $station, CarbonImmutable $start): CarbonImmutable
    {
        $boundary = CarbonImmutable::instance($this->clock->getNextBoundary($station, $start));
        $target = $boundary->subMinute()->startOfMinute()->addSeconds($this->clock->getIdStartSecond($station));
        $conn = $this->em->getConnection();

        $idSeconds = (float)($conn->fetchOne(
            'SELECT duration FROM station_queue
            WHERE station_id = ? AND top_of_hour_legal_id = 1 AND is_played = 0
            ORDER BY id DESC LIMIT 1',
            [$station->id]
        ) ?: 38.0);
        $release = $target->addSeconds((int)ceil($idSeconds));

        $newsAtBoundary = $this->aiNewsForecast->getAiringTimes(
            $station,
            $target->subMinute()->toDateTimeImmutable(),
            $boundary->addMinutes(3)->toDateTimeImmutable(),
        );
        if ([] !== $newsAtBoundary) {
            $newsSeconds = (float)($conn->fetchOne(
                'SELECT AVG(d) FROM (
                    SELECT duration AS d FROM song_history
                    WHERE station_id = ? AND text = ? AND duration > 30
                    ORDER BY id DESC LIMIT 5
                ) recent',
                [$station->id, 'Eternity Ready - News Hour']
            ) ?: 150.0);
            $release = $release->addSeconds((int)ceil($newsSeconds));
        }

        return $release->max($boundary);
    }

    /**
     * True from the ID's start (:59:ss) until a few minutes past :00, while
     * the ID and news own the air.
     */
    private function isSettlingAroundTopOfHour(Station $station): bool
    {
        $now = CarbonImmutable::now($station->getTimezoneObject());
        $secondsIntoHour = $now->minute * 60 + $now->second;
        $idStart = 59 * 60 + $this->clock->getIdStartSecond($station);

        return $secondsIntoHour >= $idStart || $secondsIntoHour < self::SETTLE_SECONDS_AFTER_HOUR;
    }

    private function getToleranceSeconds(Station $station): float
    {
        $raw = $station->backend_config->toArray(true) ?? [];

        return $this->clamp(
            (float)($raw[self::CONFIG_SWAP_TOLERANCE] ?? self::DEFAULT_TOLERANCE_SECONDS),
            self::MIN_TOLERANCE_SECONDS,
            self::MAX_TOLERANCE_SECONDS,
            self::DEFAULT_TOLERANCE_SECONDS,
        );
    }

    private function getMinGapSeconds(Station $station): float
    {
        $raw = $station->backend_config->toArray(true) ?? [];

        return $this->clamp(
            (float)($raw[self::CONFIG_SWAP_MIN_GAP] ?? self::DEFAULT_MIN_GAP_SECONDS),
            self::MIN_MIN_GAP_SECONDS,
            self::MAX_MIN_GAP_SECONDS,
            self::DEFAULT_MIN_GAP_SECONDS,
        );
    }

    private function clamp(float $value, float $min, float $max, float $default): float
    {
        if ($value <= 0.0) {
            return $default;
        }

        return max($min, min($max, $value));
    }

    private function secondsBetween(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float)$to->format('U.u') - (float)$from->format('U.u');
    }

    /**
     * Returns true if $start falls inside the pre-fade window before the next
     * Station ID deadline. Starting a new song in this window is problematic:
     * the pre-fade is designed to fade OUT an already-playing track, not fade
     * IN a fresh one. A song starting here plays at full volume for only a
     * few seconds before getting hard-cut by the ID -- audible as a jarring
     * cut rather than the intended smooth transition.
     *
     * When this returns true, the caller should remove the queued row entirely
     * rather than let it play.
     */
    private function isInPreFadeWindow(Station $station, CarbonImmutable $start): bool
    {
        $boundary = CarbonImmutable::instance($this->clock->getNextBoundary($station, $start));
        $target = $boundary
            ->subMinute()
            ->startOfMinute()
            ->addSeconds($this->clock->getIdStartSecond($station));

        $gap = $this->secondsBetween($start, $target);
        if ($gap <= 0.0) {
            // Already past the ID target -- not in the pre-fade window (though
            // this row may need other handling).
            return false;
        }

        // Don't remove rows for boundaries owned by a Clock Wheel.
        if ($this->clock->clockWheelOwnsBoundary($station, $boundary->toDateTimeImmutable())) {
            return false;
        }

        $fadeSeconds = $this->clock->getIdFadeSeconds($station);

        // The pre-fade window is the $fadeSeconds immediately before the ID
        // target. Any song starting in this window will get hard-cut almost
        // immediately after it starts.
        return $gap <= $fadeSeconds;
    }

    /**
     * True when $row would start within the final seconds before the ID and
     * cannot finish before it. Applies to capped rows and short-form items too:
     * nothing may start only to be cut by the ID.
     */
    private function wouldBeCutByTopOfHourId(Station $station, StationQueue $row, CarbonImmutable $start): bool
    {
        if ($row->top_of_hour_legal_id || $row->clock_wheel_legal_id_substitute) {
            return false;
        }
        if (null !== $row->clock_wheel || null !== $row->request) {
            return false;
        }
        $media = $row->media;
        if (!$media instanceof StationMedia || StationMediaTypes::isStationId($media->type)) {
            return false;
        }

        $boundary = CarbonImmutable::instance($this->clock->getNextBoundary($station, $start));
        if ($this->clock->clockWheelOwnsBoundary($station, $boundary->toDateTimeImmutable())) {
            return false;
        }
        $target = $boundary
            ->subMinute()
            ->startOfMinute()
            ->addSeconds($this->clock->getIdStartSecond($station));

        $gap = $this->secondsBetween($start, $target);
        if ($gap <= 0.0 || $gap > self::HOLD_WINDOW_SECONDS) {
            return false;
        }

        return $this->landingLength($media) > $gap + 1.0;
    }
}
