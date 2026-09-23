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

    /** @var array<string, float> sorted playlist id list => shortest track length, per request. */
    private array $shortestTrackCache = [];

    public function __construct(
        private readonly TopOfHourClock $clock,
        private readonly StationQueueRepository $queueRepo,
        private readonly Scheduler $scheduler,
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

        $nextSongs = $event->getNextSongs();
        if (1 !== count($nextSongs)) {
            return;
        }

        $row = $nextSongs[0];
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

        [$spm, $replacementMedia, $context] = $replacement;

        // fromMedia() -> setSong() already stamps duration from
        // getCalculatedLength(), so it is deliberately not set again here.
        // The replacement may come from a sibling playlist (see
        // getEligiblePlaylists()), so the new row belongs to wherever it was
        // actually found, not necessarily the original pick's playlist.
        $newRow = StationQueue::fromMedia($station, $replacementMedia);
        $newRow->playlist = $spm->playlist;
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
                'replacement_length' => round($replacementMedia->getCalculatedLength(), 2),
                'landing_error' => round($context['seconds_to_fill'] - $replacementMedia->getCalculatedLength(), 2),
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

        [$spm, $replacementMedia, $context] = $replacement;

        $spm->played($start->getTimestamp());
        $this->em->persist($spm);

        // The replacement may come from a sibling playlist (see
        // getEligiblePlaylists()); keep the row's playlist reference honest.
        $row->setSong($replacementMedia);
        $row->media = $replacementMedia;
        $row->playlist = $spm->playlist;
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
                'replacement_length' => round($replacementMedia->getCalculatedLength(), 2),
                'landing_error' => round($context['seconds_to_fill'] - $replacementMedia->getCalculatedLength(), 2),
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
     *         no replacement exists).
     */
    private function evaluateFinalSlot(
        Station $station,
        StationPlaylist $playlist,
        StationMedia $media,
        CarbonImmutable $start,
    ): ?array {
        $naturalLength = $media->getCalculatedLength();
        if ($naturalLength <= 0.0) {
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

        // Diagnostic trace, unconditional (debug level, so it never pollutes
        // normal operational logs but is there to grep for): confirms this
        // method is actually being reached for a given pick and shows the
        // raw numbers behind whatever it decides next, whether or not this
        // turns out to be the final slot.
        $this->logger->notice(
            'Top-of-Hour swap: evaluateFinalSlot reached.',
            [
                'media_id' => $media->id,
                'playlist' => $playlist->name,
                'start' => $start->toIso8601String(),
                'target' => $target->toIso8601String(),
                'gap' => round($gap, 2),
                'natural_length' => round($naturalLength, 2),
                'remainder' => round($gap - $naturalLength, 2),
            ]
        );

        // Duration matches are not limited to the original pick's own playlist:
        // a station's music playlists are all "the same kind of thing" once you
        // are down to the last handful of seconds before the ID, and confining
        // the search to one playlist means a station only ever lands cleanly
        // when THAT specific playlist happens to own a track of the right
        // length. Every other currently-eligible music playlist is a fair
        // source too.
        $playlists = $this->getEligiblePlaylists($station, $start, $playlist);

        $tolerance = $this->getToleranceSeconds($station);
        $minGap = $this->getMinFillableGap($station, $playlists);

        // Is this actually the last music slot of the hour? It is, if the chosen
        // track either crosses the deadline or leaves behind a remainder too
        // short for another whole song to occupy.
        $remainder = $gap - $naturalLength;
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

        // From here on this IS the final slot, so every exit gets logged. If the
        // feature ever looks inert, these lines say exactly why.
        $context = [
            'playlist' => $playlist->name,
            'playlist_id' => $playlist->id,
            'slot_starts_at' => $start->toIso8601String(),
            'id_deadline_at' => $target->toIso8601String(),
            'seconds_to_fill' => round($gap, 2),
            'original_media_id' => $media->id,
            'original_length' => round($naturalLength, 2),
            'original_overshoot' => round(-$remainder, 2),
            'tolerance' => $tolerance,
            'min_fillable_gap' => round($minGap, 2),
        ];

        if ($gap < $minGap) {
            // Not enough room for a properly duration-matched replacement --
            // but that is not the same as nothing being wrong. A track whose
            // OWN natural length badly overshoots this tiny remaining gap
            // (observed live tonight: a 503s track landing with ~1s of gap
            // left, an 8+ minute overshoot) cannot be fixed by the pre-fade
            // either: that fade is sized for a track landing a few seconds
            // late, not minutes late, so "let the pre-fade handle it" was
            // itself the bug in this branch, not just an unlogged one.
            // Swapping to whatever the shortest available eligible track is
            // will not land cleanly, but it shrinks an unrecoverable
            // multi-minute overshoot down to (at worst) that track's own
            // length past the deadline -- strictly better, even though not
            // perfect. This is a needed floor under the whole feature: it
            // should never make a bad landing worse than doing nothing, but
            // it also should never leave an enormous overshoot standing
            // when a smaller one was available for the taking.
            $overshoot = $naturalLength - $gap;
            if ($overshoot > $tolerance) {
                // Two pools, tried in order, keeping whichever candidate is
                // actually shortest: regular music first (findShortestAvailableMedia,
                // same pool the normal search already uses), then -- only if
                // that pool has nothing shorter than the current pick, which
                // is exactly the case where the whole catalog's shortest
                // regular song is still far longer than the gap -- the
                // station's own promo/sweeper pool. That pool is excluded
                // from every other search in this class on purpose (jingles
                // are not "just more music"), but landing an 8-second promo
                // a few seconds early is a far better outcome than a
                // two-minute-plus song getting hard-cut seconds after it
                // starts, which is exactly what shipped on air tonight.
                $best = $this->findShortestAvailableMedia($station, $playlists, $media->id, $start);
                $bestLength = null !== $best ? $best[1]->getCalculatedLength() : null;

                if (null === $bestLength || $bestLength >= $naturalLength) {
                    $shortForm = $this->findShortestAvailableMedia(
                        $station,
                        $this->getShortFormFallbackPlaylists($station),
                        $media->id,
                        $start,
                    );

                    if (
                        null !== $shortForm
                        && $shortForm[1]->getCalculatedLength() > 0.0
                        && (null === $bestLength || $shortForm[1]->getCalculatedLength() < $bestLength)
                    ) {
                        $best = $shortForm;
                        $bestLength = $shortForm[1]->getCalculatedLength();
                    }
                }

                if (null !== $best && null !== $bestLength && $bestLength > 0.0 && $bestLength < $naturalLength) {
                    [$spm, $replacementMedia, $isRepeat] = $best;

                    $this->logger->notice(
                        'Top-of-Hour swap: too little of the hour left for a duration-matched track; '
                        . 'using the shortest available track instead to shrink a large overshoot.',
                        $context + [
                            'min_gap' => $minGap,
                            'replacement_media_id' => $replacementMedia->id,
                            'replacement_length' => round($bestLength, 2),
                            'new_overshoot' => round($bestLength - $gap, 2),
                            'is_repeat_pick' => $isRepeat,
                        ]
                    );

                    return [$spm, $replacementMedia, $context + ['is_repeat_pick' => $isRepeat]];
                }
            }

            $this->logger->notice(
                'Top-of-Hour swap: too little of the hour left to fill with a whole song, and no shorter '
                . 'replacement was available either (including promos/sweepers); the pre-fade will handle it.',
                $context + ['min_gap' => $minGap]
            );
            return null;
        }

        if (abs($remainder) <= $tolerance) {
            $this->logger->notice(
                'Top-of-Hour swap: the selected track already lands on the deadline; no swap needed.',
                $context
            );
            return null;
        }

        $replacement = $this->findDurationMatchedMedia(
            $station,
            $playlists,
            $gap,
            $tolerance,
            $media->id,
            $start,
        );

        if (null === $replacement) {
            // No track is close enough on its own. Before giving up to the
            // pre-fade, check whether the station's existing pitch-preserving
            // stretch/squeeze feature (Playout > Stretch/Squeeze, already used
            // for Clock Wheel and scheduled-playlist boundaries) can speed up
            // or slow down some eligible track just enough to land exactly on
            // the deadline. This is the case the exact-duration search above
            // can never solve by construction: a catalog rarely has a track
            // sitting within a few seconds of any arbitrary remaining gap, but
            // a +/-5% time adjustment turns a much wider band of "roughly the
            // right length" tracks into an exact landing, with no audible cut
            // and (unlike a plain duration-tolerance match) no dead air either.
            $stretchReplacement = $this->findStretchMatchedMedia(
                $station,
                $playlists,
                $gap,
                $media->id,
                $start,
            );

            if (null === $stretchReplacement) {
                $this->logger->warning(
                    'Top-of-Hour swap: NO duration-matched or stretch-matched track in any '
                    . 'eligible playlist (including as a repeat); falling back to the pre-fade soft cut.',
                    $context + ['playlists_searched' => array_map(static fn (StationPlaylist $p): string => $p->name, $playlists)]
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
                'Top-of-Hour swap: no exact duration match; using pitch-preserving '
                . 'stretch/squeeze to land a nearby track exactly on the deadline instead.',
                $stretchContext + ['replacement_media_id' => $replacementMedia->id]
            );

            return [$spm, $replacementMedia, $stretchContext];
        }

        [$spm, $replacementMedia, $isRepeat] = $replacement;

        if ($isRepeat) {
            // Logged distinctly (not just via the flag below) because this is
            // the one path where the feature deliberately trades a station
            // policy (no repeats within the dedup window) for a bigger win
            // (no audible cut into the ID) -- worth being able to grep for.
            $this->logger->notice(
                'Top-of-Hour swap: only a recently-played track matched the needed duration; '
                . 'using it anyway rather than falling back to the pre-fade soft cut.',
                $context + ['replacement_media_id' => $replacementMedia->id]
            );
        }

        return [$spm, $replacementMedia, $context + ['is_repeat_pick' => $isRepeat]];
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
        // same way findDurationMatchedMedia() is, since m.length (raw) can
        // run longer than getCalculatedLength() (after cue trimming).
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
     *         findDurationMatchedMedia().
     */
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
        if ($row->clock_wheel_enforce_cap || $row->hour_boundary_enforce_cap) {
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
     * @param list<StationPlaylist> $playlists
     * @return array{StationPlaylistMedia, StationMedia, bool}|null
     *         The trailing bool is true when the pick only exists because the
     *         repeat-prevention window was overridden (see below) -- purely
     *         informational, for the caller's log line.
     */
    private function findDurationMatchedMedia(
        Station $station,
        array $playlists,
        float $neededSeconds,
        float $toleranceSeconds,
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
                AND m.length >= :minLength
                AND m.length <= :maxLength
                AND m.type NOT IN (:idTypes)
                ORDER BY spm.last_played ASC, spm.id ASC
            DQL
        )->setParameter('playlists', $playlists)
            ->setParameter('minLength', $neededSeconds - $toleranceSeconds)
            ->setParameter('maxLength', $neededSeconds + $toleranceSeconds + self::SQL_PREFILTER_SLACK_SECONDS)
            ->setParameter('idTypes', StationMediaTypes::stationIdTypeValues())
            ->getResult();

        // Two buckets: candidates that respect the station's normal repeat-
        // prevention window, and candidates that would only repeat it. A
        // narrow duration window (needed length +/- tolerance) often has
        // only one or two tracks in the whole catalog to begin with, so
        // requiring BOTH "right length" and "not played in the last N
        // minutes" at once regularly comes up empty even when a perfectly
        // good duration match exists -- exactly the case logged as "NO
        // duration-matched track" while a matching track sat unused because
        // it had aired within the (station-configured, often 1-2 hour)
        // dedup window. For this one final-of-the-hour slot, landing
        // cleanly on the ID beats avoiding a repeat: an early repeat is a
        // minor, easy-to-miss imperfection; a fade/cut into the ID is the
        // exact audible defect this whole feature exists to prevent. So the
        // repeat window is honored when possible and only overridden as a
        // last resort, never silently -- see the 'is_repeat' flag below.
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

            $error = $neededSeconds - $length;
            if (abs($error) > $toleranceSeconds) {
                continue;
            }

            $entry = [
                // Landing a hair early is inaudible (the underlay is already
                // faded to silence); landing late means the ID clips the song's
                // tail. So overshoot is penalised twice as heavily.
                'score' => $error >= 0.0 ? $error : (abs($error) * 2.0),
                'spm' => $spm,
                'media' => $candidateMedia,
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

        return [$chosen['spm'], $chosen['media'], $isRepeat];
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
}
