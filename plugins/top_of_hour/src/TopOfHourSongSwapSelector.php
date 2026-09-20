<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
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
 * track.
 *
 * If a duration-matched track exists, the pick is replaced. If one does not, the
 * pick is left completely alone and the existing Liquidsoap pre-fade performs
 * the soft cut. Nothing here ever shortens, caps or rewrites a duration.
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

    /** @var array<int, float> playlist id => shortest track length, per request. */
    private array $shortestTrackCache = [];

    public function __construct(
        private readonly TopOfHourClock $clock,
        private readonly StationQueueRepository $queueRepo,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['onBuildQueue', -1],
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

        $naturalLength = $media->getCalculatedLength();
        if ($naturalLength <= 0.0) {
            return;
        }

        $start = CarbonImmutable::instance($event->getExpectedPlayTime());
        $boundary = CarbonImmutable::instance($this->clock->getNextBoundary($station, $start));
        $target = $boundary
            ->subMinute()
            ->startOfMinute()
            ->addSeconds($this->clock->getIdStartSecond($station));

        if ($this->clock->clockWheelOwnsBoundary($station, $boundary->toDateTimeImmutable())) {
            return;
        }

        $gap = $this->secondsBetween($start, $target);
        if ($gap <= 0.0) {
            return;
        }

        $tolerance = $this->getToleranceSeconds($station);
        $minGap = $this->getMinFillableGap($station, $playlist);

        // Is this actually the last music slot of the hour? It is, if the chosen
        // track either crosses the deadline or leaves behind a remainder too
        // short for another whole song to occupy.
        $remainder = $gap - $naturalLength;
        if ($remainder > $minGap) {
            return;
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
            $this->logger->info(
                'Top-of-Hour swap: too little of the hour left to fill with a whole song; the pre-fade will handle it.',
                $context + ['min_gap' => $minGap]
            );
            return;
        }

        if (abs($remainder) <= $tolerance) {
            $this->logger->info(
                'Top-of-Hour swap: the selected track already lands on the deadline; no swap needed.',
                $context
            );
            return;
        }

        $replacement = $this->findDurationMatchedMedia(
            $station,
            $playlist,
            $gap,
            $tolerance,
            $media->id,
            $start,
        );

        if (null === $replacement) {
            $this->logger->warning(
                'Top-of-Hour swap: NO duration-matched track in this playlist; falling back to the pre-fade soft cut.',
                $context
            );
            return;
        }

        [$spm, $replacementMedia] = $replacement;

        // fromMedia() -> setSong() already stamps duration from
        // getCalculatedLength(), so it is deliberately not set again here.
        $newRow = StationQueue::fromMedia($station, $replacementMedia);
        $newRow->playlist = $playlist;

        $spm->played($start->getTimestamp());
        $this->em->persist($spm);

        // Drop the superseded pick before Queue::buildQueue() flushes, so the
        // discarded selection can never surface as a phantom queue row.
        if ($this->em->contains($row)) {
            $this->em->detach($row);
        }

        $this->em->persist($newRow);
        $event->setNextSongs($newRow);

        $this->logger->info(
            'Top-of-Hour swap: SWAPPED the final song of the hour for a duration-matched track.',
            $context + [
                'replacement_media_id' => $replacementMedia->id,
                'replacement_length' => round($replacementMedia->getCalculatedLength(), 2),
                'landing_error' => round($gap - $replacementMedia->getCalculatedLength(), 2),
            ]
        );
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
     * @return array{StationPlaylistMedia, StationMedia}|null
     */
    private function findDurationMatchedMedia(
        Station $station,
        StationPlaylist $playlist,
        float $neededSeconds,
        float $toleranceSeconds,
        ?int $excludeMediaId,
        CarbonImmutable $expectedPlayTime,
    ): ?array {
        $recentSongIds = $this->getRecentlySelectedSongIds($station, $expectedPlayTime);

        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT spm, m FROM App\Entity\StationPlaylistMedia spm
                JOIN spm.media m
                WHERE spm.playlist = :playlist
                AND m.length >= :minLength
                AND m.length <= :maxLength
                AND m.type NOT IN (:idTypes)
                ORDER BY spm.last_played ASC, spm.id ASC
            DQL
        )->setParameter('playlist', $playlist)
            ->setParameter('minLength', $neededSeconds - $toleranceSeconds)
            ->setParameter('maxLength', $neededSeconds + $toleranceSeconds + self::SQL_PREFILTER_SLACK_SECONDS)
            ->setParameter('idTypes', StationMediaTypes::stationIdTypeValues())
            ->getResult();

        $candidates = [];

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

            if (isset($recentSongIds[$candidateMedia->song_id])) {
                continue;
            }

            $candidates[] = [
                // Landing a hair early is inaudible (the underlay is already
                // faded to silence); landing late means the ID clips the song's
                // tail. So overshoot is penalised twice as heavily.
                'score' => $error >= 0.0 ? $error : (abs($error) * 2.0),
                'spm' => $spm,
                'media' => $candidateMedia,
                'last_played' => $spm->last_played,
            ];
        }

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

        return [$chosen['spm'], $chosen['media']];
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
     * The shortest gap worth handing to another whole song, for THIS playlist.
     *
     * Anything shorter must be treated as the final slot and solved by swapping
     * the current pick, because no track in the playlist could fill the
     * remainder. Without this, a remainder that falls between the configured
     * floor and the playlist's shortest track is unfillable by construction and
     * always degrades to the fade.
     */
    private function getMinFillableGap(Station $station, StationPlaylist $playlist): float
    {
        $configured = $this->getMinGapSeconds($station);

        if (!isset($this->shortestTrackCache[$playlist->id])) {
            $shortest = $this->em->createQuery(
                <<<'DQL'
                    SELECT MIN(m.length) FROM App\Entity\StationPlaylistMedia spm
                    JOIN spm.media m
                    WHERE spm.playlist = :playlist AND m.length > 0
                DQL
            )->setParameter('playlist', $playlist)
                ->getSingleScalarResult();

            $this->shortestTrackCache[$playlist->id] = (float)($shortest ?? 0.0);
        }

        $shortest = $this->shortestTrackCache[$playlist->id];

        // Cap the adaptive raise so one very long outlier in a small playlist
        // cannot make every slot look like the final one.
        return min(max($configured, $shortest), self::MAX_MIN_GAP_SECONDS);
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
}
