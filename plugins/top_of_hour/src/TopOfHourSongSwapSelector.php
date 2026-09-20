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
 * Requirement 1: Dynamic Song Swapping (primary Top-of-Hour landing strategy).
 *
 * The station ID owns an exact wall-clock second inside minute :59. Historically
 * the only way to protect that deadline was to fade/cut whatever music happened
 * to be on air (requirement 2), which is audible and therefore a fallback, not a
 * plan.
 *
 * This subscriber runs AFTER the ordinary AutoDJ selector (priority 0) and
 * BEFORE the DMCA validator (priority -5). When the freshly-picked track is the
 * last music slot of the hour, it is replaced with a track from the SAME active
 * playlist whose natural duration lands on the ID deadline within the operator's
 * tolerance. If no such track exists, the pick is left completely untouched and
 * the existing Liquidsoap pre-fade performs the rare soft hard-cut instead.
 *
 * Deliberate design choices:
 *  - Candidates come only from the playlist the selector already chose, so
 *    daypart/scheduling/rotation rules cannot be bypassed by this swap.
 *  - The replacement is still subject to every lower-priority validator
 *    (DMCA, etc.), because it is written back into the same event.
 *  - Nothing here ever shortens, caps or rewrites a duration. A swap either
 *    happens naturally or does not happen at all.
 */
final class TopOfHourSongSwapSelector implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    /**
     * Extra slack applied to the raw `length` column when pre-filtering in SQL.
     * getCalculatedLength() subtracts cue-in/cue-out, so the stored length can be
     * a little longer than the audible duration we actually schedule against.
     */
    private const float SQL_PREFILTER_SLACK_SECONDS = 45.0;

    /** How many equally-good candidates to shuffle between, to avoid a rut. */
    private const int CANDIDATE_POOL_SIZE = 5;

    public function __construct(
        private readonly TopOfHourClock $clock,
        private readonly StationQueueRepository $queueRepo,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After QueueBuilder::calculateNextSong (0), before DmcaComplianceListener (-5).
            BuildQueue::class => ['onBuildQueue', -1],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        // An interrupting build is an emergency/strict takeover, not hour planning.
        if ($event->isInterrupting()) {
            return;
        }

        $station = $event->getStation();
        if (!$this->clock->isEnabled($station) || !$this->clock->isSwapEnabled($station)) {
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
        $target = CarbonImmutable::instance($this->clock->getTargetStartFor($station, $start));
        $boundary = CarbonImmutable::instance($this->clock->getNextBoundary($station, $start));

        // A Clock Wheel that supplies its own mandatory ID owns this boundary.
        if ($this->clock->clockWheelOwnsBoundary($station, $boundary->toDateTimeImmutable())) {
            return;
        }

        $gap = $this->secondsBetween($start, $target);
        if ($gap <= 0.0) {
            return;
        }

        $tolerance = (float)$this->clock->getSwapToleranceSeconds($station);
        $minGap = (float)$this->clock->getSwapMinGapSeconds($station);

        // Is this actually the final music slot of the hour? It is, if the chosen
        // track either crosses the deadline or leaves behind a remainder too short
        // for another whole song to occupy.
        $remainder = $gap - $naturalLength;
        if ($remainder > $minGap) {
            return;
        }

        // The slot is too short for ANY song to land on cleanly. Leave it to the
        // Liquidsoap pre-fade (requirement 2) rather than manufacturing a stub.
        if ($gap < $minGap) {
            $this->logger->debug(
                'Top-of-Hour swap: remaining hour is shorter than the minimum swap gap; deferring to the pre-fade fallback.',
                ['gap_seconds' => round($gap, 2), 'min_gap_seconds' => $minGap]
            );
            return;
        }

        // Already lands on the deadline within tolerance: nothing to fix.
        if (abs($remainder) <= $tolerance) {
            $this->logger->debug(
                'Top-of-Hour swap: selected track already lands on the ID deadline; no swap needed.',
                [
                    'media_id' => $media->id,
                    'overshoot_seconds' => round(-$remainder, 2),
                ]
            );
            return;
        }

        $replacement = $this->findDurationMatchedMedia(
            $station,
            $playlist,
            $gap,
            $tolerance,
            $media->id,
            $event->getExpectedPlayTime()->getTimestamp(),
            $start,
        );

        if (null === $replacement) {
            $this->logger->info(
                'Top-of-Hour swap: no duration-matched track available for this hour; the pre-fade soft cut will handle the deadline.',
                [
                    'playlist_id' => $playlist->id,
                    'needed_seconds' => round($gap, 2),
                    'tolerance_seconds' => $tolerance,
                    'original_media_id' => $media->id,
                ]
            );
            return;
        }

        [$spm, $replacementMedia] = $replacement;

        $newRow = StationQueue::fromMedia($station, $replacementMedia);
        $newRow->playlist = $playlist;
        $newRow->duration = $replacementMedia->getCalculatedLength();

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
            'Top-of-Hour swap: substituted the final song of the hour with a duration-matched track.',
            [
                'playlist_id' => $playlist->id,
                'original_media_id' => $media->id,
                'original_length' => round($media->getCalculatedLength(), 2),
                'replacement_media_id' => $replacementMedia->id,
                'replacement_length' => round($replacementMedia->getCalculatedLength(), 2),
                'needed_seconds' => round($gap, 2),
                'landing_error_seconds' => round($gap - $replacementMedia->getCalculatedLength(), 2),
                'id_target_at' => $target->toIso8601String(),
            ]
        );
    }

    /**
     * Only ordinary AutoDJ music is eligible for substitution. IDs, legal-ID
     * substitutes, Clock Wheel rows, listener requests, AI DJ/news rows and any
     * row already carrying an explicit wall-clock cap are left alone.
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
        int $nowTimestamp,
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
                // faded); landing late means the ID clips the song's tail. So
                // overshoot is penalised twice as heavily as undershoot.
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

        // Rotate between the best few matches. With a tight tolerance the same
        // one or two tracks would otherwise close every single hour.
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

    private function secondsBetween(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float)$to->format('U.u') - (float)$from->format('U.u');
    }
}
