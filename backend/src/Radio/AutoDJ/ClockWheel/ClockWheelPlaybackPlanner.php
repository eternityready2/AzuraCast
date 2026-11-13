<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\AdOrder;
use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Enums\ClockWheelScheduleMode;
use App\Entity\Enums\ClockWheelSlotAlgorithms;
use App\Entity\Enums\ClockWheelSlotPoolModes;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Entity\StationSchedule;
use App\Event\Radio\RevalidateQueuedSong;
use App\Radio\AutoDJ\AiNewsScheduleForecastService;
use App\Radio\AutoDJ\AiredLength;
use App\Radio\AutoDJ\DmcaComplianceListener;
use App\Radio\AutoDJ\DuplicatePrevention;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Radio\AutoDJ\MediaPlayability;
use App\Radio\AutoDJ\QueueBuilder;
use App\Radio\AutoDJ\SponsorGuaranteedPlayoutService;
use App\Radio\AutoDJ\StretchSqueezeQueueTiming;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\SmartBlock\SmartBlockAvailability;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;


/**
 * Duration-aware format clock planner: selects the active anchor slot for the
 * current second in the hour and picks media that fits before the next anchor.
 */
final class ClockWheelPlaybackPlanner
{
    private const int HOUR_SECONDS = 3600;

    /** Minimum playable window (seconds) before deferring a flexible music slot. */
    private const int MIN_MUSIC_WINDOW_SECONDS = 30;

    private const int MIN_TALK_WINDOW_SECONDS = 45;

    private const int MIN_SHORT_FORM_WINDOW_SECONDS = 10;
    /**
     * The hour's last item may end this much before the ID (as the Top-of-Hour
     * swap holds back anything due in its last 45s); a pick due later than
     * this opens the next hour after the ID instead.
     */
    private const int ID_EARLY_SECONDS = 15;

    /** A promo or ad is not put before the ID again within this long of its last airing. */
    private const int PROMO_REPEAT_SECONDS = 3600;

    /** @deprecated Use HourBoundaryPlanner::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS or station config. */
    public const int LEGAL_ID_COMPLIANCE_TOLERANCE_SECONDS = HourBoundaryPlanner::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StationQueueRepository $queueRepo,
        private readonly DuplicatePrevention $duplicatePrevention,
        private readonly SeparationRulesChecker $separationChecker,
        private readonly ClockWheelEventLogger $eventLogger,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly QueueBuilder $queueBuilder,
        private readonly SponsorGuaranteedPlayoutService $sponsorGuarantee,
        private readonly ClockWheelStretchCalculator $stretchCalculator,
        private readonly LoggerInterface $logger,
        private readonly SmartBlockAvailability $smartBlockAvailability,
        private readonly DmcaComplianceListener $dmca,
        private readonly TopOfHourClock $topOfHourClock,
        private readonly AiredLength $airedLength,
        private readonly AiNewsScheduleForecastService $aiNewsForecast,
    ) {
    }

    /**
     * Holds a Clock Wheel song due under the Top-of-Hour ID lane (the ID, and
     * the AI News after it) until the lane hands the air back, so it opens the
     * new hour, as the Top-of-Hour swap holds an ordinary hour's first song.
     *
     * The swap leaves Clock Wheel rows alone, so a wheel hour's first song was
     * timed at :00:14 while the ID and a 2:20 news bulletin aired first: the
     * whole wheel hour ran 2:19 late and the next ID faded its last song
     * (Power Drive, 5pm Thu 2026-11-12, in the log build). The lane is worked
     * out as the swap works it out: the ID start from TopOfHourClock, the
     * queued ID's length, and the recent bulletins' average when news airs.
     */
    public function holdUnderIdLane(RevalidateQueuedSong $event): void
    {
        $row = $event->getQueueRow();
        if (
            null === $row->clock_wheel
            || $row->top_of_hour_legal_id
            || $row->clock_wheel_legal_id_substitute
            || null !== $row->request
        ) {
            return;
        }

        $media = $row->media;
        if (null === $media || StationMediaTypes::isStationId($media->type)) {
            return;
        }

        $station = $event->getStation();
        if (!$this->topOfHourClock->isEnabled($station)) {
            return;
        }

        $start = CarbonImmutable::instance($event->getExpectedPlayAt());
        $release = $this->idLaneReleaseContaining($station, $start);
        if (null === $release) {
            return;
        }

        $event->holdBack();
        $event->opensAfter($release->toDateTimeImmutable());

        $this->logger->notice(
            'Clock Wheel: holding the new hour\'s first song until the ID lane hands the air back.',
            [
                'queue_id' => $row->id,
                'media_id' => $media->id,
                'start' => $start->toIso8601String(),
                'release' => $release->toIso8601String(),
            ]
        );
    }

    /**
     * On air, a wheel song that would run into the Top-of-Hour ID is not
     * started: the row gets a song from its own (slot) playlist that ends on
     * the ID, or, when none does, a promo that fits before it. The swap does
     * this for every other song and leaves wheel rows alone, so a wheel song
     * handed over late was faded by the ID ("Awesome God", 12:58:08-12:59:59
     * Thu 2026-11-12). Nothing is held back: the row always has something to
     * play.
     */
    public function landWheelRowOnId(RevalidateQueuedSong $event): void
    {
        // Only as the row is handed to the player, when its start is real: the
        // queue's projection had the 6pm opener starting at 5:59:24 and it was
        // swapped for a promo every re-check (Thu 2026-11-12, 5:02pm).
        if (!$event->isHandOff()) {
            return;
        }

        $row = $event->getQueueRow();
        if (
            null === $row->clock_wheel
            || $row->top_of_hour_legal_id
            || $row->clock_wheel_legal_id_substitute
            || null !== $row->request
        ) {
            return;
        }

        $media = $row->media;
        if (null === $media || 'music' !== $media->type) {
            return;
        }

        $station = $event->getStation();
        $start = $event->getExpectedPlayAt();
        $untilId = $this->secondsUntilId($station, $start);
        if (null === $untilId || $untilId <= 0.0) {
            return;
        }

        $ratio = (float)($row->clock_wheel_stretch_ratio ?: 1.0);
        $plays = $this->airedLength->lengthOf($media) / ($ratio > 0.0 ? $ratio : 1.0);
        if ($plays <= $untilId + 2.0) {
            return;
        }

        // The next hour's opener handed over just before the ID: a promo takes
        // the seconds before the ID and the line goes back to the log to open
        // its hour after it (LinearLogHourGuard::isFillerBeforeLine), as the
        // Top-of-Hour swap does for an ordinary opener. Left alone, the 6pm
        // opener would have started at 5:59:24 and been cut by the ID.
        if (null !== $row->log_entry_id) {
            $line = $this->em->find(\App\Entity\StationLogEntry::class, $row->log_entry_id);
            if (null !== $line && $line->planned_at >= $start->getTimestamp() + (int)floor($untilId)) {
                $promo = $this->promoBefore($station, $untilId);
                if (null !== $promo) {
                    $this->putOnRow($row, $promo->media, $promo->playlist, null, $start);
                    // The promo is not the next wheel's song: it airs as an
                    // ordinary promo of this hour.
                    $row->clock_wheel = null;
                    $row->clock_wheel_schedule_mode = null;
                    $this->em->persist($row);
                    $this->logger->notice('Clock Wheel: the next hour\'s opener was due before the ID; a promo fills the seconds and the opener waits for its hour.', [
                        'queue_id' => $row->id,
                        'opener' => $media->text,
                        'now' => $promo->media->text,
                        'until_id' => round($untilId, 1),
                    ]);
                    return;
                }
                $this->holdForNextHour($event, $station, $start, $untilId);
                return;
            }
        }

        $playlist = $row->playlist;
        if (!$playlist instanceof StationPlaylist && null !== $row->log_entry_id) {
            $playlist = $this->em->find(\App\Entity\StationLogEntry::class, $row->log_entry_id)?->playlist;
        }

        $pick = $playlist instanceof StationPlaylist
            ? $this->songThatLandsOnId($station, $playlist, $untilId, $start, $media->id)
            : null;
        if (null !== $pick) {
            [$newMedia, $newRatio] = $pick;
            $this->putOnRow($row, $newMedia, $playlist, $newRatio, $start);
            $this->logger->notice('Clock Wheel: a song that would run into the ID was swapped for one from its slot playlist that ends on it.', [
                'queue_id' => $row->id,
                'was' => $media->text,
                'now' => $newMedia->text,
                'until_id' => round($untilId, 1),
                'stretch_ratio' => $newRatio,
            ]);
            return;
        }

        $spm = $this->promoBefore($station, $untilId);
        if (null !== $spm) {
            $this->putOnRow($row, $spm->media, $spm->playlist, null, $start);
            $this->logger->notice('Clock Wheel: a song that would run into the ID was swapped for a promo that ends before it.', [
                'queue_id' => $row->id,
                'was' => $media->text,
                'now' => $spm->media->text,
                'until_id' => round($untilId, 1),
            ]);
            return;
        }

        if ($this->holdForNextHour($event, $station, $start, $untilId)) {
            return;
        }

        $this->logger->warning('Clock Wheel: nothing in the slot playlist or the promos fits before the ID.', [
            'queue_id' => $row->id,
            'song' => $media->text,
            'until_id' => round($untilId, 1),
        ]);
    }

    /**
     * The song in $playlist that ends on the ID from $start at a tempo within
     * the station's stretch/squeeze range (or up to ID_EARLY_SECONDS before
     * it), the closest first, that has not aired lately, is not queued and
     * passes the DMCA rules.
     *
     * @return array{0: StationMedia, 1: ?float}|null the song and its tempo
     */
    private function songThatLandsOnId(
        Station $station,
        StationPlaylist $playlist,
        float $untilId,
        DateTimeImmutable $start,
        int $exceptMediaId,
    ): ?array {
        [$slowest, $fastest] = $this->stretchRange($station);

        /** @var StationMedia[] $songs */
        $songs = $this->em->createQuery(
            'SELECT m FROM App\Entity\StationMedia m
            WHERE m.type = :music AND m.length > 0 AND m.id != :except
            AND EXISTS (SELECT 1 FROM App\Entity\StationPlaylistMedia spm WHERE spm.media = m AND spm.playlist = :playlist)'
        )->setParameter('music', 'music')
            ->setParameter('except', $exceptMediaId)
            ->setParameter('playlist', $playlist)
            ->getResult();

        $fits = [];
        foreach ($songs as $song) {
            $aired = $this->airedLength->lengthOf($song);
            $ratio = $aired / $untilId;
            if ($ratio > $fastest + 0.0005) {
                continue;
            }
            if ($ratio >= $slowest - 0.0005) {
                $fits[] = [abs($ratio - 1.0), $song, round(min(max($ratio, $slowest), $fastest), 4)];
            } elseif ($untilId - $aired / $slowest <= self::ID_EARLY_SECONDS) {
                $fits[] = [1.0 + $untilId - $aired / $slowest, $song, round($slowest, 4)];
            }
        }
        usort($fits, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $conn = $this->em->getConnection();
        $since = $start->getTimestamp() - max(60, $station->backend_config->duplicate_prevention_time_range) * 60;
        foreach (array_slice($fits, 0, 25) as [, $song, $ratio]) {
            $recent = $conn->fetchOne(
                'SELECT 1 FROM song_history WHERE station_id = ? AND song_id = ? AND timestamp_start >= FROM_UNIXTIME(?) LIMIT 1',
                [$station->id, $song->song_id, $since]
            );
            $queued = $conn->fetchOne(
                'SELECT 1 FROM station_queue WHERE station_id = ? AND song_id = ? AND is_played = 0 LIMIT 1',
                [$station->id, $song->song_id]
            );
            if (
                false === $recent
                && false === $queued
                && $this->dmca->allowsPick(StationQueue::fromMedia($station, $song), $station, $start)
            ) {
                return [$song, abs($ratio - 1.0) < 0.0005 ? null : $ratio];
            }
        }

        return null;
    }

    /**
     * An item from the station's enabled promo/liner pools that ends before
     * the ID and has not aired in the last hour, least recently played first: the pools the Top-of-Hour
     * swap fills the seconds before an ordinary hour's ID from.
     */
    private function promoBefore(Station $station, float $untilId): ?\App\Entity\StationPlaylistMedia
    {
        $promos = $this->em->createQuery(
            'SELECT spm, m, p FROM App\Entity\StationPlaylistMedia spm JOIN spm.media m JOIN spm.playlist p
            WHERE p.station = :station AND p.is_enabled = true AND p.is_jingle = true
            AND m.type IN (:promo) AND m.length > 0 AND m.length <= :room
            AND spm.last_played < :recent
            ORDER BY spm.last_played ASC, m.length DESC'
        )->setParameter('station', $station)
            ->setParameter('promo', ['promo', 'ad'])
            // Never one that aired in the last hour, and the least recently
            // played first: longest-first picked the same 72s promo for every
            // slot from 10:55 to 11:01pm, five times running (Thu 2026-11-12).
            ->setParameter('recent', time() - self::PROMO_REPEAT_SECONDS)
            // By file length with a second to spare, as LinearLogHour judges
            // whether an item runs into the ID.
            ->setParameter('room', $untilId - 1.0)
            ->setMaxResults(1)
            ->getResult();

        return $promos[0] ?? null;
    }

    private function putOnRow(
        StationQueue $row,
        StationMedia $media,
        ?StationPlaylist $playlist,
        ?float $ratio,
        DateTimeImmutable $start,
    ): void {
        $row->setSong($media);
        $row->media = $media;
        if (null !== $playlist) {
            $row->playlist = $playlist;
        }
        $row->clock_wheel_enforce_cap = false;
        $row->clock_wheel_max_play_seconds = null;
        $row->clock_wheel_stretch_ratio = $ratio;
        $row->duration = $media->getCalculatedLength() / ($ratio ?? 1.0);
        $this->em->persist($row);

        if (null !== $playlist) {
            $this->em->getConnection()->executeStatement(
                'UPDATE station_playlist_media SET last_played = ? WHERE playlist_id = ? AND media_id = ?',
                [$start->getTimestamp(), $playlist->id, $media->id]
            );
        }
    }

    /**
     * When the ID lane that $start falls under hands the air back, or null when
     * $start is outside it (before the ID start, or after the lane released).
     */
    private function idLaneReleaseContaining(Station $station, CarbonImmutable $start): ?CarbonImmutable
    {
        $boundary = CarbonImmutable::instance(
            $this->topOfHourClock->getNextBoundary($station, $start->subMinutes(10)->toDateTimeImmutable())
        );
        if ($this->topOfHourClock->clockWheelOwnsBoundary($station, $boundary->toDateTimeImmutable())) {
            return null;
        }

        $target = $boundary
            ->subMinute()
            ->startOfMinute()
            ->subMinutes($this->topOfHourClock->getIdStartMinuteOffset($station))
            ->addSeconds($this->topOfHourClock->getIdStartSecond($station));
        if ($start->lessThan($target->subSecond())) {
            return null;
        }

        $release = $this->idLaneRelease($station, $boundary, $target);

        return $start->lessThan($release) ? $release : null;
    }

    /** When the ID lane at $boundary (ID start $target) hands the air back: the ID, plus the news when it airs. */
    private function idLaneRelease(Station $station, CarbonImmutable $boundary, CarbonImmutable $target): CarbonImmutable
    {
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

        if ($this->topOfHourClock->getIdStartMinuteOffset($station) <= 0) {
            $release = $release->max($boundary);
        }

        return $release;
    }

    /**
     * A wheel song due in the swap's hold window before the ID that would be
     * cut by it, with nothing short enough to take its seconds: not sent now;
     * it opens the new hour once the ID (and news) hand the air back, as the
     * Top-of-Hour swap holds an ordinary song there. Started instead, the 7pm
     * opener was cut 6s in and the overview kept its name for the next song
     * (Ray Boltz 6:59:53, Avalon on air from 7:00:11, Thu 2026-11-12).
     */
    private function holdForNextHour(RevalidateQueuedSong $event, Station $station, DateTimeImmutable $start, float $untilId): bool
    {
        if ($untilId > \App\Radio\AutoDJ\LinearLog\LinearLogHour::ID_HOLD_WINDOW_SECONDS) {
            return false;
        }

        $at = CarbonImmutable::instance($start);
        $boundary = CarbonImmutable::instance($this->topOfHourClock->getNextBoundary($station, $start));
        $target = $at->addSeconds((int)floor($untilId));
        $release = $this->idLaneRelease($station, $boundary, $target);

        $event->holdBack();
        $event->opensAfter($release->toDateTimeImmutable());

        $this->logger->notice('Clock Wheel: holding a song due just before the ID; it opens the new hour.', [
            'queue_id' => $event->getQueueRow()->id,
            'song' => $event->getQueueRow()->media?->text,
            'until_id' => round($untilId, 1),
            'release' => $release->toIso8601String(),
        ]);

        return true;
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    public function resolveNextQueueEntry(
        StationClockWheel $wheel,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime,
        StationSchedule $activeSchedule,
    ): ?StationQueue {
        $slots = $this->sortSlots($wheel->slots->toArray());

        $station = $wheel->station;

        if ($slots === []) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                null,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoSlots,
            );

            return null;
        }
        $tz = $station->getTimezoneObject();
        $secondsIntoHour = $this->hourBoundaryPlanner->getPlannedSecondsIntoHour($station, $expectedPlayTime, $tz);

        $activeIndex = $this->getActiveSlotIndex($slots, $secondsIntoHour);
        $activeSlot = $slots[$activeIndex];
        $nextAnchor = $this->getNextAnchorSeconds($slots, $activeIndex);
        $availableSeconds = max(1, $nextAnchor - $secondsIntoHour);
        $minWindow = $this->getMinWindowSeconds($activeSlot);

        // A top-of-hour legal ID airs once. Its slot stays "active" by time until
        // the next slot's position, and every pick in that span used to queue the
        // ID again: nine IDs in a row at 6:00 for a template whose next slot sat
        // at 5:00 (2026-10-05). Once this hour's ID is in, move on to the next slot.
        while (
            ClockWheelSlotTypes::isMandatoryTopOfHourSlot($activeSlot->type, $activeSlot->position_seconds)
            && isset($slots[$activeIndex + 1])
            && $this->isLegalIdInThisHour($station, $activeSlot, $recentHistory, $expectedPlayTime)
        ) {
            $activeIndex++;
            $activeSlot = $slots[$activeIndex];
            $nextAnchor = $this->getNextAnchorSeconds($slots, $activeIndex);
            $availableSeconds = max(1, $nextAnchor - $secondsIntoHour);
            $minWindow = $this->getMinWindowSeconds($activeSlot);
        }

        $this->logger->info('Clock Wheel slot selection.', [
            'clock_wheel_id' => $wheel->id,
            'seconds_into_hour' => $secondsIntoHour,
            'expected_play_time' => $expectedPlayTime->format(DateTimeImmutable::ATOM),
            'active_slot_order' => $activeSlot->slot_order,
            'active_position_seconds' => $activeSlot->position_seconds,
            'next_anchor_seconds' => $nextAnchor,
            'available_seconds' => $availableSeconds,
            'min_window_seconds' => $minWindow,
            'slot_type' => $activeSlot->type?->value,
        ]);

        $scheduleMode = $activeSchedule->clock_wheel_mode ?? ClockWheelScheduleMode::Flexible;

        if (ClockWheelSlotTypes::isMandatoryTopOfHourSlot($activeSlot->type, $activeSlot->position_seconds)) {
            return $this->resolveMandatoryLegalIdSlot(
                $wheel,
                $activeSlot,
                $recentHistory,
                $scheduleMode,
                $station,
                $expectedPlayTime,
                $secondsIntoHour,
                $availableSeconds,
            );
        }

        // A soft slot whose time is up hands the clock to the next slot, as a
        // music log moves to its next category; the hour's last slot keeps
        // picking. Returning nothing here left a hole in the log.
        while (
            $this->isSoftSlot($activeSlot, $scheduleMode)
            && $availableSeconds < $minWindow
            && isset($slots[$activeIndex + 1])
        ) {
            $activeIndex++;
            $activeSlot = $slots[$activeIndex];
            $nextAnchor = $this->getNextAnchorSeconds($slots, $activeIndex);
            $availableSeconds = max(1, $nextAnchor - $secondsIntoHour);
            $minWindow = $this->getMinWindowSeconds($activeSlot);
        }

        if (
            $this->isFlexibleMusicSlot($activeSlot)
            && $availableSeconds < $minWindow
            && !$this->isSoftSlot($activeSlot, $scheduleMode)
        ) {
            if ($activeSlot->is_hard_anchor) {
                if ($availableSeconds < self::MIN_SHORT_FORM_WINDOW_SECONDS) {
                    $this->logger->warning(
                        'Clock Wheel: hard anchor missed — insufficient window before next anchor.',
                        [
                            'available_seconds' => $availableSeconds,
                            'position_seconds' => $activeSlot->position_seconds,
                        ]
                    );
                    $this->eventLogger->recordFallback(
                        $station,
                        $wheel,
                        $activeSlot,
                        $expectedPlayTime,
                        ClockWheelFallbackReason::HardAnchorMissed,
                        $secondsIntoHour,
                    );

                    return null;
                }

                $this->logger->info(
                    'Clock Wheel: hard anchor — forcing shortest fit in tight window.',
                    [
                        'available_seconds' => $availableSeconds,
                        'min_window_seconds' => $minWindow,
                    ]
                );
            } else {
                $this->logger->info(
                    'Clock Wheel: insufficient time before next anchor; deferring to next BuildQueue tick.',
                    [
                        'available_seconds' => $availableSeconds,
                        'min_window_seconds' => $minWindow,
                        'slot_type' => $activeSlot->type?->value,
                    ]
                );
                $this->eventLogger->recordDeferred(
                    $station,
                    $wheel,
                    $activeSlot,
                    $expectedPlayTime,
                    ClockWheelFallbackReason::DeferredInsufficientWindow,
                    $secondsIntoHour,
                );

                return null;
            }
        }

        if (
            $activeSlot->is_hard_anchor
            && $secondsIntoHour > $activeSlot->position_seconds + 5
        ) {
            $this->logger->warning(
                'Clock Wheel: hard anchor playhead is past anchor position.',
                [
                    'position_seconds' => $activeSlot->position_seconds,
                    'seconds_into_hour' => $secondsIntoHour,
                ]
            );
        }

        $isEndOfHour = $this->isEndOfHourMusicSlot($slots, $activeIndex);

        if ($this->isSoftSlot($activeSlot, $scheduleMode)) {
            $untilId = $this->secondsUntilId($station, $expectedPlayTime);

            // The hour's last seconds: nothing starts only to be faded by the ID.
            // The next hour's own first item opens after it (this wheel's, a
            // show's or another wheel's), as when an ordinary hour ends.
            if (null !== $untilId && $untilId > 0.0 && $untilId <= self::ID_EARLY_SECONDS) {
                $this->eventLogger->recordDeferred(
                    $station,
                    $wheel,
                    $activeSlot,
                    $expectedPlayTime,
                    ClockWheelFallbackReason::DeferredInsufficientWindow,
                    $secondsIntoHour,
                );

                return null;
            }

            if (
                null !== $untilId
                && $untilId > self::ID_EARLY_SECONDS
                && $untilId < $this->shortestSongSeconds($activeSlot)
            ) {
                $filler = $this->resolveIdFiller($wheel, $activeSlot, $scheduleMode, $station, $expectedPlayTime, $untilId);
                if (null !== $filler) {
                    return $filler;
                }
            }
        }

        $queueEntry = $this->resolveSlot(
            $wheel,
            $activeSlot,
            $recentHistory,
            $availableSeconds,
            $scheduleMode,
            $station,
            $expectedPlayTime,
            $secondsIntoHour,
            $isEndOfHour,
        );

        if (null === $queueEntry && $activeSlot->is_hard_anchor) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $activeSlot,
                $expectedPlayTime,
                ClockWheelFallbackReason::HardAnchorMissed,
                $secondsIntoHour,
            );
        }

        return $queueEntry;
    }

    /**
     * @param StationClockWheelSlot[] $slots
     *
     * @return StationClockWheelSlot[]
     */
    private function sortSlots(array $slots): array
    {
        usort(
            $slots,
            static fn (StationClockWheelSlot $a, StationClockWheelSlot $b): int =>
                $a->position_seconds <=> $b->position_seconds
                ?: $a->slot_order <=> $b->slot_order
        );

        return $slots;
    }

    /**
     * Planned position within the broadcast hour (0–3599), using expected play time
     * and already-queued items in the same hour so anchors stay aligned when the
     * AutoDJ queue is built ahead of wall clock time.
     */
    private function getPlannedSecondsIntoHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        DateTimeZone $tz,
    ): int {
        return $this->hourBoundaryPlanner->getPlannedSecondsIntoHour($station, $expectedPlayTime, $tz);
    }

    private function getMinWindowSeconds(StationClockWheelSlot $slot): int
    {
        if (!$this->isFlexibleMusicSlot($slot)) {
            return self::MIN_SHORT_FORM_WINDOW_SECONDS;
        }

        return match ($slot->type) {
            ClockWheelSlotTypes::Talk => self::MIN_TALK_WINDOW_SECONDS,
            ClockWheelSlotTypes::Music, null => self::MIN_MUSIC_WINDOW_SECONDS,
            default => self::MIN_MUSIC_WINDOW_SECONDS,
        };
    }

    /**
     * @param StationClockWheelSlot[] $slots
     */
    private function getActiveSlotIndex(array $slots, int $secondsIntoHour): int
    {
        $activeIndex = 0;

        foreach ($slots as $index => $slot) {
            if ($slot->position_seconds <= $secondsIntoHour) {
                $activeIndex = $index;
            } else {
                break;
            }
        }

        return $activeIndex;
    }

    /**
     * @param StationClockWheelSlot[] $slots
     */
    private function getNextAnchorSeconds(array $slots, int $activeIndex): int
    {
        if (isset($slots[$activeIndex + 1])) {
            return $slots[$activeIndex + 1]->position_seconds;
        }

        return self::HOUR_SECONDS;
    }

    private function isFlexibleMusicSlot(StationClockWheelSlot $slot): bool
    {
        if ($slot->duration_seconds !== null) {
            return false;
        }

        $type = $slot->type;

        return $type === null
            || $type === ClockWheelSlotTypes::Music
            || $type === ClockWheelSlotTypes::Talk;
    }

    /**
     * @param StationClockWheelSlot[] $slots
     */
    private function isEndOfHourMusicSlot(array $slots, int $activeIndex): bool
    {
        if (!isset($slots[$activeIndex])) {
            return false;
        }

        $slot = $slots[$activeIndex];
        if (!$this->isFlexibleMusicSlot($slot)) {
            return false;
        }

        return $this->getNextAnchorSeconds($slots, $activeIndex) >= HourBoundaryPlanner::HOUR_SECONDS;
    }

    /**
     * The top of the hour a legal-ID slot belongs to: this hour within 30s of
     * :00, otherwise the next one. HourBoundaryPlanner's method of the same name
     * was deleted with the legacy TOH planner (11f44590a) while this call stayed,
     * so the first wheel with a :00 legal-ID slot to be scheduled (a daypart,
     * 2026-10-05) failed every Linear Log build with "Call to undefined method".
     */
    private function resolveTopOfHourExpectedPlayAt(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): DateTimeImmutable {
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($station->getTimezoneObject());
        $hourStart = $local->startOf('hour');

        if ($local->getTimestamp() - $hourStart->getTimestamp() > 30) {
            return $hourStart->addHour()->toDateTimeImmutable();
        }

        return $hourStart->toDateTimeImmutable();
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    private function resolveSlot(
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        array $recentHistory,
        int $availableSeconds,
        ClockWheelScheduleMode $scheduleMode,
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        int $secondsIntoHour,
        bool $isEndOfHour = false,
    ): ?StationQueue {
        $type = $slot->type;
        $categoryId = $slot->category_id;
        $playlistId = $slot->playlist_id;

        if ($type === null) {
            $this->logger->warning('Clock Wheel slot has no type — skipping.');
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoSlotType,
                $secondsIntoHour,
            );

            return null;
        }

        if (
            ClockWheelSlotPoolModes::PlaylistRotation === $slot->pool_mode
            && $playlistId !== null
            && $playlistId > 0
        ) {
            return $this->resolveSlotViaPlaylistRotation(
                $wheel,
                $slot,
                $recentHistory,
                $availableSeconds,
                $scheduleMode,
                $station,
                $expectedPlayTime,
                $secondsIntoHour,
                $isEndOfHour,
            );
        }

        // An Ad slot with no pinned playlist serves the Advertising page's bookings:
        // approved orders inside their dates that still have plays left today, the one
        // furthest behind its daily count first. Pinning a playlist on the slot keeps
        // the old meaning, and with no live bookings the slot falls through to media
        // typed "ad", exactly as before.
        if (
            ClockWheelSlotTypes::Ad === $type
            && ($playlistId === null || $playlistId <= 0)
        ) {
            $fromOrders = $this->resolveAdSlotFromOrders(
                $wheel,
                $slot,
                $recentHistory,
                $availableSeconds,
                $scheduleMode,
                $station,
                $expectedPlayTime,
                $secondsIntoHour,
                $isEndOfHour,
            );

            if (null !== $fromOrders) {
                return $fromOrders;
            }
        }

        $params = ['storageLocation' => $station->media_storage_location];
        $dql = 'SELECT m FROM App\Entity\StationMedia m
             WHERE m.storage_location = :storageLocation';

        if (null !== $slot->smart_block) {
            // The smart block's songs that can play now (no repeat, DMCA ok).
            $smartBlockMediaIds = array_column(
                $this->smartBlockAvailability->getPlayablePool($slot->smart_block, $expectedPlayTime),
                'id'
            );
            $dql .= ' AND m.id IN (:smartBlockMediaIds)';
            $params['smartBlockMediaIds'] = [] !== $smartBlockMediaIds ? $smartBlockMediaIds : [0];
        } elseif ($playlistId !== null && $playlistId > 0) {
            $dql .= ' AND EXISTS (
                SELECT 1 FROM App\Entity\StationPlaylistMedia spm
                WHERE spm.media = m AND spm.playlist = :playlistId
            )';
            $params['playlistId'] = $playlistId;
        }

        $dql .= ' AND m.type = :type';
        $params['type'] = $type->value;

        if ($categoryId !== null) {
            $dql .= ' AND m.category_id = :categoryId';
            $params['categoryId'] = $categoryId;
        }

        /** @var StationMedia[] $candidates */
        $candidates = $this->em->createQuery($dql)
            ->setParameters($params)
            ->getResult();

        if ($candidates === []) {
            $this->logger->warning(
                sprintf(
                    'Clock Wheel slot: no media found (type=%s, category=%s, playlist=%s).',
                    $type?->value ?? '(any)',
                    $categoryId ?? '(any)',
                    $playlistId ?? '(any)',
                )
            );
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoMediaCandidates,
                $secondsIntoHour,
            );

            return null;
        }

        $maxDuration = $this->resolveMaxDuration($slot, $availableSeconds);
        $strictSchedule = ClockWheelScheduleMode::Strict === $scheduleMode;

        $soft = $this->isSoftSlot($slot, $scheduleMode);
        if (!$soft) {
            $candidates = $this->filterByDuration(
                $candidates,
                $maxDuration,
                $slot,
                $strictSchedule || $isEndOfHour,
                $isEndOfHour,
            );
        }

        if (!$soft && $isEndOfHour && $candidates !== []) {
            usort(
                $candidates,
                static fn (StationMedia $a, StationMedia $b): int =>
                    $b->getCalculatedLength() <=> $a->getCalculatedLength()
            );
        }

        $separationSettings = ClockWheelSeparationSettings::resolveForSlot($slot, $wheel);
        $separationResult = $this->separationChecker->apply(
            $candidates,
            $recentHistory,
            $separationSettings,
            $expectedPlayTime,
            $slot->category_id,
        );
        $candidates = $separationResult->candidates;

        if ($candidates === []) {
            $this->logger->warning(
                'Clock Wheel slot: no media fits the available window.',
                ['available_seconds' => $availableSeconds, 'max_duration' => $maxDuration]
            );
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoMediaFitsWindow,
                $secondsIntoHour,
            );

            return null;
        }

        $mediaQueue = [];
        foreach ($candidates as $m) {
            $q = new StationPlaylistQueue();
            $q->media_id = $m->id;
            $q->spm_id = 0;
            $q->song_id = $m->song_id;
            $q->artist = $m->artist ?? '';
            $q->title = $m->title ?? '';
            $mediaQueue[] = $q;
        }

        // Nothing fits the window: filterByDuration() returned the candidates
        // shortest first, and that order is the choice -- the slot's algorithm
        // would put a long song first and the cap would cut most of it.
        $noneFit = !$soft && $candidates[0]->getCalculatedLength() > $maxDuration;
        if (!$noneFit) {
            $algorithm = $slot->algorithm ?? ClockWheelSlotAlgorithms::Random;
            $mediaQueue = $this->applyAlgorithm($mediaQueue, $candidates, $algorithm, $recentHistory);
        }

        /** @var array<int, float> $landingRatios media id => stretch ratio that lands it on the ID */
        $landingRatios = [];
        if ($soft) {
            [$mediaQueue, $landingRatios, $needsFiller] = $this->orderForIdLanding($station, $candidates, $mediaQueue, $expectedPlayTime);
            if ($needsFiller) {
                $untilId = (float)$this->secondsUntilId($station, $expectedPlayTime);
                $filler = $this->resolveIdFiller($slot->clock_wheel, $slot, $scheduleMode, $station, $expectedPlayTime, $untilId);
                if (null !== $filler) {
                    return $filler;
                }
            }
        }

        // Past picks the DMCA rules would turn down (a third song in a row from
        // one album, say): the pick order is fixed, so every retry returned the
        // same rejected song and the log planned a hole.
        $validTrack = null;
        for ($dmcaTries = 0; $dmcaTries < 25; $dmcaTries++) {
            $validTrack = $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, false)
                ?? $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, true);
            if (null === $validTrack) {
                break;
            }

            $pick = $this->em->find(StationMedia::class, $validTrack->media_id);
            if (
                !$pick instanceof StationMedia
                || $this->dmca->allowsPick(StationQueue::fromMedia($station, $pick), $station, $expectedPlayTime)
            ) {
                break;
            }

            $rejectedId = $validTrack->media_id;
            $mediaQueue = array_values(array_filter(
                $mediaQueue,
                static fn (StationPlaylistQueue $q): bool => $q->media_id !== $rejectedId
            ));
            $validTrack = null;
        }

        // Every song that fits before the ID was turned down (repeats, DMCA):
        // a promo before the ID rather than a song that runs into it.
        if ($validTrack === null && $soft) {
            $untilId = $this->secondsUntilId($station, $expectedPlayTime);
            if (null !== $untilId && $untilId > self::ID_EARLY_SECONDS && $untilId < 2.0 * HourBoundaryPlanner::HOUR_SECONDS / 12) {
                $filler = $this->resolveIdFiller($slot->clock_wheel, $slot, $scheduleMode, $station, $expectedPlayTime, $untilId);
                if (null !== $filler) {
                    return $filler;
                }
            }
        }

        if ($validTrack === null) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::DuplicatePreventionEmpty,
                $secondsIntoHour,
            );

            return null;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::MediaNotFound,
                $secondsIntoHour,
            );

            return null;
        }

        $queueEntry = StationQueue::fromMedia($station, $media);
        $queueEntry->clock_wheel = $slot->clock_wheel;
        $queueEntry->smart_block = $slot->smart_block;
        $queueEntry->clock_wheel_max_play_seconds = (int)floor($maxDuration);
        $queueEntry->clock_wheel_schedule_mode = $scheduleMode->value;
        $queueEntry->clock_wheel_enforce_cap = $this->shouldEnforcePlaybackCap(
            $slot,
            $scheduleMode,
            $media,
            $maxDuration,
            $isEndOfHour,
        );
        if ($soft) {
            $this->playInFull($queueEntry, $slot, null);
            if (isset($landingRatios[$media->id])) {
                $queueEntry->clock_wheel_stretch_ratio = $landingRatios[$media->id];
            }
        } else {
            $this->guardHourBoundary($queueEntry, $media, $secondsIntoHour);

            // Every slot in the log is eligible for stretch/squeeze -- not just the
            // one before a mandatory anchor -- so gaps close smoothly station-wide.
            // Still only within the safe +/-5% range computed in the calculator.
            $queueEntry->clock_wheel_stretch_ratio = $this->stretchCalculator->calculate(
                $media->getCalculatedLength(),
                $availableSeconds,
            );
        }

        $this->em->persist($queueEntry);

        $this->eventLogger->recordTrackQueued(
            $station,
            $wheel,
            $slot,
            $media,
            $expectedPlayTime,
            $secondsIntoHour,
            $separationResult->separationRelaxed,
            $separationResult->burnRateWarning,
            $queueEntry,
        );

        $this->logger->info('Clock Wheel resolved track.', [
            'media_id' => $media->id,
            'title' => $media->title,
            'effective_length' => $media->getCalculatedLength(),
            'available_seconds' => $availableSeconds,
            'clock_wheel_schedule_mode' => $scheduleMode->value,
            'clock_wheel_enforce_cap' => $queueEntry->clock_wheel_enforce_cap,
        ]);

        return $queueEntry;
    }

    /**
     * True when an ID from this slot's pool has already aired or been queued in
     * the hour $expectedPlayTime falls in (history includes queued rows).
     *
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    private function isLegalIdInThisHour(
        Station $station,
        StationClockWheelSlot $slot,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        $hourStart = $this->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime)->getTimestamp();
        if ($hourStart > $expectedPlayTime->getTimestamp()) {
            // Past :00:30 the helper points at the next hour; this hour started one earlier.
            $hourStart -= HourBoundaryPlanner::HOUR_SECONDS;
        }

        $idSongs = [];
        foreach ($this->loadStationIdCandidates($station, $slot) as $media) {
            $idSongs[$media->song_id] = true;
        }

        foreach ($recentHistory as $row) {
            if (!isset($idSongs[$row['song_id'] ?? ''])) {
                continue;
            }
            $played = $row['timestamp_played'] ?? null;
            $playedTs = $played instanceof DateTimeInterface
                ? $played->getTimestamp()
                : (is_string($played) ? (int)strtotime($played) : 0);
            // The ID may start a moment before :00 (the station ID lane fires at :59:59).
            if ($playedTs >= $hourStart - 60) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mandatory legal_id at top of hour — never returns null; promo fallback if needed.
     *
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    private function resolveMandatoryLegalIdSlot(
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        array $recentHistory,
        ClockWheelScheduleMode $scheduleMode,
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        int $secondsIntoHour,
        int $availableSeconds,
    ): ?StationQueue {
        $legalIdExpectedAt = $this->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime);
        $usedSubstitute = false;
        $fallbackReason = null;

        $candidates = $this->loadStationIdCandidates($station, $slot);

        if ($candidates === []) {
            $candidates = $this->loadMediaCandidates($station, ClockWheelSlotTypes::Promo, $slot);
            $usedSubstitute = true;
            $fallbackReason = ClockWheelFallbackReason::LegalIdMissingUsedPromo;
        }

        if ($candidates === []) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $legalIdExpectedAt,
                ClockWheelFallbackReason::NoMediaCandidates,
                $secondsIntoHour,
            );

            $this->logger->error(
                'Clock Wheel top-of-hour ID: no id or promo media available — cannot queue mandatory ID.',
                ['clock_wheel_id' => $wheel->id]
            );

            return null;
        }

        // A mandatory legal ID/promo still must not overrun into the next anchor
        // slot. Use the real window before the next anchor (with a small floor
        // so a very tight window doesn't reject every candidate outright); was
        // previously hardcoded to 120s regardless of actual available time,
        // which could let a >availableSeconds clip bleed into the next slot.
        $maxDuration = $this->resolveMaxDuration($slot, max(self::MIN_SHORT_FORM_WINDOW_SECONDS, $availableSeconds));
        $allCandidates = $candidates;
        $candidates = $this->filterByDuration($allCandidates, $maxDuration, $slot, true, false);

        if ($candidates === [] && $allCandidates !== []) {
            usort(
                $allCandidates,
                static fn (StationMedia $a, StationMedia $b): int =>
                    $a->getCalculatedLength() <=> $b->getCalculatedLength()
            );
            $candidates = [$allCandidates[0]];
        }

        $mediaQueue = [];
        foreach ($candidates as $m) {
            $q = new StationPlaylistQueue();
            $q->media_id = $m->id;
            $q->spm_id = 0;
            $q->song_id = $m->song_id;
            $q->artist = $m->artist ?? '';
            $q->title = $m->title ?? '';
            $mediaQueue[] = $q;
        }

        $algorithm = $slot->algorithm ?? ClockWheelSlotAlgorithms::Sequential;
        if ($algorithm === ClockWheelSlotAlgorithms::Random) {
            $algorithm = ClockWheelSlotAlgorithms::Sequential;
        }

        $mediaQueue = $this->applyAlgorithm($mediaQueue, $candidates, $algorithm, $recentHistory);

        $validTrack = $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, false)
            ?? $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, true)
            ?? $mediaQueue[0] ?? null;

        if ($validTrack === null) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $legalIdExpectedAt,
                ClockWheelFallbackReason::DuplicatePreventionEmpty,
                $secondsIntoHour,
            );

            return null;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            return null;
        }

        if ($fallbackReason instanceof ClockWheelFallbackReason) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $legalIdExpectedAt,
                $fallbackReason,
                $secondsIntoHour,
            );
        }

        $queueEntry = StationQueue::fromMedia($station, $media);
        $queueEntry->clock_wheel = $slot->clock_wheel;
        $queueEntry->clock_wheel_max_play_seconds = (int)floor($maxDuration);
        $queueEntry->clock_wheel_schedule_mode = $scheduleMode->value;
        $queueEntry->clock_wheel_enforce_cap = true;
        $queueEntry->clock_wheel_legal_id_substitute = $usedSubstitute;
        $this->em->persist($queueEntry);

        $this->eventLogger->recordTrackQueued(
            $station,
            $wheel,
            $slot,
            $media,
            $expectedPlayTime,
            $secondsIntoHour,
            false,
            false,
            $queueEntry,
            $legalIdExpectedAt,
        );

        $this->logger->info('Clock Wheel resolved mandatory legal_id.', [
            'media_id' => $media->id,
            'substitute' => $usedSubstitute,
            'expected_top_of_hour' => $legalIdExpectedAt->format(DateTimeImmutable::ATOM),
        ]);

        return $queueEntry;
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    /**
     * The spots sold on the Advertising page, offered to an Ad slot. Only approved
     * orders inside their dates with plays still owed today are considered; anyone
     * behind the day's pace goes first, so the guarantee holds inside wheel hours
     * the same way it does outside them. Delegating to the rotation path keeps the
     * play attributed to the order's playlist, which is what the pace tracker and
     * the Sponsor Plays report both count.
     */
    private function resolveAdSlotFromOrders(
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        array $recentHistory,
        int $availableSeconds,
        ClockWheelScheduleMode $scheduleMode,
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        int $secondsIntoHour,
        bool $isEndOfHour = false,
    ): ?StationQueue {
        $now = CarbonImmutable::instance($expectedPlayTime);

        /** @var AdOrder[] $orders */
        $orders = $this->em->createQuery(
            <<<'DQL'
                SELECT o, p FROM App\Entity\AdOrder o
                JOIN o.playlist p
                WHERE o.station = :station
                AND o.status = 'approved'
                AND o.is_live_read = 0
                AND o.media IS NOT NULL
                AND p.is_enabled = 1
                AND (o.starts_at IS NULL OR o.starts_at <= :now)
                AND (o.ends_at IS NULL OR o.ends_at >= :now)
            DQL
        )->setParameter('station', $station)
            ->setParameter('now', $now->getTimestamp())
            ->getResult();

        $orders = array_filter($orders, static fn (AdOrder $o) => $o->getEffectiveSpotsPerDay() > 0);

        if ([] === $orders) {
            return null;
        }

        // Plays each order's playlist already has today, station-local midnight onward.
        $dayStartUtc = $now->setTimezone($station->getTimezoneObject())
            ->startOfDay()
            ->setTimezone(new DateTimeZone('UTC'));

        $playlistIds = array_map(static fn (AdOrder $o) => $o->playlist->id, $orders);
        $playCounts = [];
        $countRows = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(sh.playlist) AS pid, COUNT(sh.id) AS plays
                FROM App\Entity\SongHistory sh
                WHERE sh.playlist IN (:playlists)
                AND sh.timestamp_start >= :dayStart
                GROUP BY sh.playlist
            DQL
        )->setParameter('playlists', $playlistIds)
            ->setParameter('dayStart', $dayStartUtc->getTimestamp())
            ->getArrayResult();
        foreach ($countRows as $row) {
            $playCounts[(int)$row['pid']] = (int)$row['plays'];
        }

        $remaining = [];
        foreach ($orders as $order) {
            $left = $order->getEffectiveSpotsPerDay() - ($playCounts[$order->playlist->id] ?? 0);
            if ($left > 0) {
                $remaining[$order->playlist->id] = ['order' => $order, 'left' => $left];
            }
        }

        if ([] === $remaining) {
            // Every booking already got its day. The slot owes nobody anything, so it
            // falls through to the station's own ad-typed media or the wheel's fallback.
            return null;
        }

        $behindPace = [];
        foreach ($this->sponsorGuarantee->getPlaylistsBehindPace($station, $expectedPlayTime) as $behind) {
            $behindPace[$behind->id] = true;
        }

        $pool = array_filter(
            $remaining,
            static fn (array $r, int $pid) => isset($behindPace[$pid]),
            ARRAY_FILTER_USE_BOTH
        );
        if ([] === $pool) {
            $pool = $remaining;
        }

        // The most owed goes first; a tie goes to whoever has aired less today.
        uasort($pool, static function (array $a, array $b): int {
            $aired = static fn (array $r): int => $r['order']->getEffectiveSpotsPerDay() - $r['left'];

            return [$b['left'], $aired($a)] <=> [$a['left'], $aired($b)];
        });

        foreach ($pool as $entry) {
            $row = $this->resolveSlotViaPlaylistRotation(
                $wheel,
                $slot,
                $recentHistory,
                $availableSeconds,
                $scheduleMode,
                $station,
                $expectedPlayTime,
                $secondsIntoHour,
                $isEndOfHour,
                $entry['order']->playlist,
            );

            if (null !== $row) {
                return $row;
            }
        }

        return null;
    }

    private function resolveSlotViaPlaylistRotation(
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        array $recentHistory,
        int $availableSeconds,
        ClockWheelScheduleMode $scheduleMode,
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        int $secondsIntoHour,
        bool $isEndOfHour = false,
        ?StationPlaylist $playlistOverride = null,
    ): ?StationQueue {
        $type = $slot->type;
        $playlist = $playlistOverride ?? $this->em->find(StationPlaylist::class, $slot->playlist_id);
        if (!$playlist instanceof StationPlaylist || $playlist->station->id !== $station->id) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoMediaCandidates,
                $secondsIntoHour,
            );

            return null;
        }

        $maxDuration = $this->resolveMaxDuration($slot, $availableSeconds);
        $strictSchedule = ClockWheelScheduleMode::Strict === $scheduleMode;
        $separationSettings = ClockWheelSeparationSettings::resolveForSlot($slot, $wheel);
        $attemptHistory = $recentHistory;

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $validTrack = $this->queueBuilder->pickNextTrackFromPlaylist(
                $playlist,
                $attemptHistory,
                $attempt > 0,
            );

            if ($validTrack === null) {
                break;
            }

            $media = $this->em->find(StationMedia::class, $validTrack->media_id);
            if (!$media instanceof StationMedia) {
                continue;
            }

            // An override playlist comes from an ad order, which already vouches for the
            // file; order spots are stored as ordinary music, so the type gate stands down.
            if (null === $playlistOverride && $type !== null && $media->type !== $type->value) {
                $attemptHistory = $this->appendSyntheticHistory($attemptHistory, $media, $expectedPlayTime);
                continue;
            }

            if ($slot->category_id !== null && $media->category_id !== $slot->category_id) {
                $attemptHistory = $this->appendSyntheticHistory($attemptHistory, $media, $expectedPlayTime);
                continue;
            }

            if (!$this->dmca->allowsPick(StationQueue::fromMedia($station, $media), $station, $expectedPlayTime)) {
                $attemptHistory = $this->appendSyntheticHistory($attemptHistory, $media, $expectedPlayTime);
                continue;
            }

            $durationCandidates = $this->isSoftSlot($slot, $scheduleMode)
                ? [$media]
                : $this->filterByDuration(
                    [$media],
                    $maxDuration,
                    $slot,
                    $strictSchedule || $isEndOfHour,
                    $isEndOfHour,
                );

            if ($durationCandidates === []) {
                $attemptHistory = $this->appendSyntheticHistory($attemptHistory, $media, $expectedPlayTime);
                continue;
            }

            $separationResult = $this->separationChecker->apply(
                $durationCandidates,
                $attemptHistory,
                $separationSettings,
                $expectedPlayTime,
                $slot->category_id,
            );

            if ($separationResult->candidates === []) {
                $attemptHistory = $this->appendSyntheticHistory($attemptHistory, $media, $expectedPlayTime);
                continue;
            }

            $media = $separationResult->candidates[0];

            $queueEntry = StationQueue::fromMedia($station, $media);
            $queueEntry->clock_wheel = $slot->clock_wheel;
            $queueEntry->playlist = $playlist;
            $queueEntry->clock_wheel_max_play_seconds = (int)floor($maxDuration);
            $queueEntry->clock_wheel_schedule_mode = $scheduleMode->value;
            $queueEntry->clock_wheel_enforce_cap = $this->shouldEnforcePlaybackCap(
                $slot,
                $scheduleMode,
                $media,
                $maxDuration,
                $isEndOfHour,
            );
            if ($this->isSoftSlot($slot, $scheduleMode)) {
                $this->playInFull($queueEntry, $slot, $playlist);
            } else {
                $this->guardHourBoundary($queueEntry, $media, $secondsIntoHour);
                $queueEntry->clock_wheel_stretch_ratio = $this->stretchCalculator->calculate(
                    $media->getCalculatedLength(),
                    $availableSeconds,
                );
            }
            $this->em->persist($queueEntry);

            $this->eventLogger->recordTrackQueued(
                $station,
                $wheel,
                $slot,
                $media,
                $expectedPlayTime,
                $secondsIntoHour,
                $separationResult->separationRelaxed,
                $separationResult->burnRateWarning,
                $queueEntry,
            );

            return $queueEntry;
        }

        $this->eventLogger->recordFallback(
            $station,
            $wheel,
            $slot,
            $expectedPlayTime,
            ClockWheelFallbackReason::NoMediaCandidates,
            $secondsIntoHour,
        );

        return null;
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     *
     * @return array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}>
     */
    private function appendSyntheticHistory(
        array $recentHistory,
        StationMedia $media,
        DateTimeImmutable $expectedPlayTime,
    ): array {
        array_unshift($recentHistory, [
            'song_id' => $media->song_id,
            'timestamp_played' => $expectedPlayTime->getTimestamp(),
            'title' => $media->title,
            'artist' => $media->artist,
        ]);

        return $recentHistory;
    }

    /**
     * @return StationMedia[]
     */
    private function loadStationIdCandidates(
        Station $station,
        StationClockWheelSlot $slot,
    ): array {
        $params = [
            'storageLocation' => $station->media_storage_location,
            'types' => StationMediaTypes::stationIdTypeValues(),
        ];

        $dql = 'SELECT m FROM App\Entity\StationMedia m
             WHERE m.storage_location = :storageLocation
             AND m.type IN (:types)';

        if ($slot->playlist_id !== null && $slot->playlist_id > 0) {
            $dql .= ' AND EXISTS (
                SELECT 1 FROM App\Entity\StationPlaylistMedia spm
                WHERE spm.media = m AND spm.playlist = :playlistId
            )';
            $params['playlistId'] = $slot->playlist_id;
        }

        if ($slot->category_id !== null) {
            $dql .= ' AND m.category_id = :categoryId';
            $params['categoryId'] = $slot->category_id;
        }

        /** @var StationMedia[] $result */
        $result = $this->em->createQuery($dql)
            ->setParameters($params)
            ->getResult();

        return array_values(array_filter(
            $result,
            static fn (StationMedia $media): bool => MediaPlayability::isEligibleForPlayback($media, null, $station->getTimezoneObject()),
        ));
    }

    /**
     * @return StationMedia[]
     */
    private function loadMediaCandidates(
        Station $station,
        ClockWheelSlotTypes $type,
        StationClockWheelSlot $slot,
    ): array {
        $params = [
            'storageLocation' => $station->media_storage_location,
            'type' => $type->value,
        ];

        $dql = 'SELECT m FROM App\Entity\StationMedia m
             WHERE m.storage_location = :storageLocation
             AND m.type = :type';

        if ($slot->playlist_id !== null && $slot->playlist_id > 0) {
            $dql .= ' AND EXISTS (
                SELECT 1 FROM App\Entity\StationPlaylistMedia spm
                WHERE spm.media = m AND spm.playlist = :playlistId
            )';
            $params['playlistId'] = $slot->playlist_id;
        }

        if ($slot->category_id !== null) {
            $dql .= ' AND m.category_id = :categoryId';
            $params['categoryId'] = $slot->category_id;
        }

        /** @var StationMedia[] $result */
        $result = $this->em->createQuery($dql)
            ->setParameters($params)
            ->getResult();

        return array_values(array_filter(
            $result,
            static fn (StationMedia $media): bool => MediaPlayability::isEligibleForPlayback($media, null, $station->getTimezoneObject()),
        ));
    }

    private function resolveMaxDuration(StationClockWheelSlot $slot, int $availableSeconds): float
    {
        if ($slot->duration_seconds !== null && $slot->duration_seconds > 0) {
            return (float)min($slot->duration_seconds, $availableSeconds);
        }

        return (float)$availableSeconds;
    }

    /**
     * When true, AutoDJ applies a cue_out cap (Liquidsoap) as a fallback after PHP track selection.
     */
    private function shouldEnforcePlaybackCap(
        StationClockWheelSlot $slot,
        ClockWheelScheduleMode $scheduleMode,
        StationMedia $media,
        float $maxDuration,
        bool $isEndOfHour = false,
    ): bool {
        if ($isEndOfHour) {
            return true;
        }

        if (ClockWheelScheduleMode::Strict === $scheduleMode) {
            return true;
        }

        // A slot with its own length set keeps its cap.
        if ($slot->duration_seconds !== null && $slot->duration_seconds > 0) {
            return $media->getCalculatedLength() > $maxDuration;
        }

        // Flexible: never cut mid-hour. A track longer than the window plays in full
        // and the next slot starts later; the hour boundary is still capped above.
        return false;
    }

    /**
     * A soft slot: a flexible wheel's music slot with no length of its own and
     * no hard anchor. It plays as a music log's category does -- the clock
     * picks the category and the order, the song plays in full, and the slot
     * starts when the song before it ends. The hour is landed on the ID the
     * way every other hour is (Linear Log hour fit, backtime, Top-of-Hour
     * swap), and any song they swap in comes from this slot's own playlist.
     */
    private function isSoftSlot(StationClockWheelSlot $slot, ClockWheelScheduleMode $scheduleMode): bool
    {
        return ClockWheelScheduleMode::Flexible === $scheduleMode
            && $this->isFlexibleMusicSlot($slot)
            && !$slot->is_hard_anchor;
    }

    /**
     * Seconds from $at to this hour's Top-of-Hour ID start, the same deadline the
     * Top-of-Hour swap lands ordinary hours on. Null when the station has no ID.
     */
    private function secondsUntilId(Station $station, DateTimeImmutable $at): ?float
    {
        if (!$this->topOfHourClock->isEnabled($station)) {
            return null;
        }

        $target = CarbonImmutable::instance($this->topOfHourClock->getNextBoundary($station, $at))
            ->subMinute()
            ->startOfMinute()
            ->subMinutes($this->topOfHourClock->getIdStartMinuteOffset($station))
            ->addSeconds($this->topOfHourClock->getIdStartSecond($station));

        return (float)($target->getTimestamp() - $at->getTimestamp())
            - (float)$at->format('u') / 1_000_000;
    }

    /** @return array{0: float, 1: float} the most a song may be slowed down and sped up, as ratios */
    private function stretchRange(Station $station): array
    {
        $raw = $station->backend_config->toArray(true) ?? [];
        if (false === (bool)($raw['playout_stretch_squeeze_enabled'] ?? true)) {
            return [1.0, 1.0];
        }

        [$stretch, $squeeze] = StretchSqueezeQueueTiming::getStretchSqueezePercents($raw);

        return [1.0 - $stretch / 100.0, 1.0 + $squeeze / 100.0];
    }

    /** The shortest music in a slot's playlist, in aired seconds (150 when unknown). */
    private function shortestSongSeconds(StationClockWheelSlot $slot): float
    {
        if (null === $slot->playlist_id || $slot->playlist_id <= 0) {
            return 150.0;
        }

        $min = $this->em->createQuery(
            'SELECT MIN(m.length) FROM App\Entity\StationPlaylistMedia spm JOIN spm.media m
            WHERE spm.playlist = :playlist AND m.type = :music AND m.length > 0'
        )->setParameter('playlist', $slot->playlist_id)
            ->setParameter('music', 'music')
            ->getSingleScalarResult();

        return null !== $min ? max(30.0, (float)$min - 15.0) : 150.0;
    }

    /**
     * Puts first the picks that end the hour on its ID, as music logs are
     * backtimed: a song that lands on the ID at a tempo within the station's
     * stretch/squeeze range, then a song after which one more song lands on it,
     * then the slot's own order. Outside the hour's last stretch the order is
     * left as the slot's algorithm made it.
     *
     * @param StationMedia[] $candidates
     * @param StationPlaylistQueue[] $mediaQueue
     * @return array{0: StationPlaylistQueue[], 1: array<int, float>, 2: bool} the order, landing
     *     tempos, and whether a promo has to go first because no song can
     */
    private function orderForIdLanding(
        Station $station,
        array $candidates,
        array $mediaQueue,
        DateTimeImmutable $expectedPlayTime,
    ): array {
        $untilId = $this->secondsUntilId($station, $expectedPlayTime);
        if (null === $untilId || $untilId <= self::ID_EARLY_SECONDS || [] === $candidates) {
            return [$mediaQueue, [], false];
        }

        $aired = [];
        foreach ($candidates as $m) {
            $aired[$m->id] = $this->airedLength->lengthOf($m);
        }
        $longest = max($aired);
        $shortest = min($aired);
        if ($untilId > 2.0 * $longest) {
            return [$mediaQueue, [], false];
        }

        [$slowest, $fastest] = $this->stretchRange($station);
        $early = (float)self::ID_EARLY_SECONDS;
        // The tempo that ends $length on the ID, or as close before it as the
        // slowest tempo allows (no more than ID_EARLY_SECONDS early). Null when
        // it would run into the ID or end too early.
        $ratioFor = static function (float $length, float $room) use ($slowest, $fastest, $early): ?float {
            if ($room <= 0.0) {
                return null;
            }
            $ratio = $length / $room;
            if ($ratio > $fastest + 0.0005) {
                return null;
            }
            if ($ratio >= $slowest - 0.0005) {
                return round(min(max($ratio, $slowest), $fastest), 4);
            }
            return $room - $length / $slowest <= $early ? round($slowest, 4) : null;
        };
        $promoLengths = $this->promoLengths($station);
        $landable = static function (float $room, int $exceptId) use ($aired, $ratioFor, $promoLengths, $early): bool {
            if ($room <= $early) {
                return true;
            }
            foreach ($aired as $id => $length) {
                if ($id !== $exceptId && null !== $ratioFor($length, $room)) {
                    return true;
                }
            }
            foreach ($promoLengths as $promo) {
                if ($promo <= $room && $room - $promo <= $early) {
                    return true;
                }
            }
            return false;
        };

        $lands = [];
        $opens = [];
        $roomy = [];
        $rest = [];
        $ratios = [];
        foreach ($mediaQueue as $q) {
            $length = $aired[$q->media_id] ?? null;
            if (null === $length) {
                continue;
            }
            $ratio = $ratioFor($length, $untilId);
            if (null !== $ratio) {
                $lands[] = $q;
                $ratios[$q->media_id] = $ratio;
            } elseif ($length < $untilId && $landable($untilId - $length, $q->media_id)) {
                $opens[] = $q;
            } elseif ($length < $untilId && $untilId - $length >= 2.0 * $shortest) {
                // Leaves room for two more songs: the landing is worked out later.
                $roomy[] = $q;
            } else {
                $rest[] = $q;
            }
        }

        // Never a song that runs into the ID or leaves a gap no song fits:
        // with nothing else, a promo goes first and the hour lands after it.
        if ([] === $lands && [] === $opens && [] === $roomy) {
            return [$mediaQueue, [], true];
        }

        return [[...$lands, ...$opens, ...$roomy], $ratios, false];
    }

    /** @return list<float> lengths of the promos the ID filler may use */
    private function promoLengths(Station $station): array
    {
        $rows = $this->em->createQuery(
            'SELECT DISTINCT m.length FROM App\Entity\StationPlaylistMedia spm JOIN spm.media m JOIN spm.playlist p
            WHERE p.station = :station AND p.is_enabled = true AND p.is_jingle = true
            AND m.type = :promo AND m.length > 0'
        )->setParameter('station', $station)
            ->setParameter('promo', 'promo')
            ->getSingleColumnResult();

        return array_map('floatval', $rows);
    }

    /**
     * Less time before the ID than any song in the slot: the closest-fitting
     * promo from the station's enabled promo pools, as the Top-of-Hour swap
     * fills the last seconds of an ordinary hour. Null leaves the seconds to
     * the ID lane.
     */
    private function resolveIdFiller(
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        ClockWheelScheduleMode $scheduleMode,
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        float $untilId,
    ): ?StationQueue {
        if ($untilId < 5.0) {
            return null;
        }

        /** @var \App\Entity\StationPlaylistMedia[] $rows */
        $rows = $this->em->createQuery(
            'SELECT spm, m, p FROM App\Entity\StationPlaylistMedia spm JOIN spm.media m JOIN spm.playlist p
            WHERE p.station = :station AND p.is_enabled = true AND p.is_jingle = true
            AND m.type = :promo AND m.length > 0 AND m.length <= :room
            ORDER BY m.length DESC, spm.last_played ASC'
        )->setParameter('station', $station)
            ->setParameter('promo', 'promo')
            ->setParameter('room', $untilId + 2.0)
            ->getResult();

        foreach ($rows as $spm) {
            $media = $spm->media;
            $entry = StationQueue::fromMedia($station, $media);
            if (!$this->dmca->allowsPick($entry, $station, $expectedPlayTime)) {
                continue;
            }

            $entry->clock_wheel = $wheel;
            $entry->playlist = $spm->playlist;
            $entry->clock_wheel_schedule_mode = $scheduleMode->value;
            $entry->clock_wheel_enforce_cap = false;
            $this->em->persist($entry);

            $this->logger->info('Clock Wheel: promo fills the seconds before the ID.', [
                'media_id' => $media->id,
                'until_id' => round($untilId, 1),
                'length' => $media->length,
            ]);

            return $entry;
        }

        return null;
    }

    /**
     * A soft slot's song: no cap, no tempo change, and the slot's playlist on
     * the row so a song swapped in for it comes from the same category.
     */
    private function playInFull(StationQueue $queueEntry, StationClockWheelSlot $slot, ?StationPlaylist $playlist): void
    {
        $queueEntry->clock_wheel_enforce_cap = false;
        $queueEntry->clock_wheel_max_play_seconds = null;
        $queueEntry->clock_wheel_stretch_ratio = null;

        if (null === $playlist && null === $slot->smart_block && null !== $slot->playlist_id && $slot->playlist_id > 0) {
            $playlist = $this->em->find(StationPlaylist::class, $slot->playlist_id);
        }
        if ($playlist instanceof StationPlaylist) {
            $queueEntry->playlist = $playlist;
        }
    }

    /**
     * Flexible tracks may run past their own window, never past the top of the hour:
     * a track that would cross :00 is capped there, as the last slot of the hour is.
     */
    private function guardHourBoundary(StationQueue $queueEntry, StationMedia $media, int $secondsIntoHour): void
    {
        $untilHour = HourBoundaryPlanner::HOUR_SECONDS - $secondsIntoHour;
        if (!$queueEntry->clock_wheel_enforce_cap && $media->getCalculatedLength() > $untilHour) {
            $queueEntry->clock_wheel_enforce_cap = true;
            $queueEntry->clock_wheel_max_play_seconds = max(1, $untilHour);
        }
    }

    private function filterByDuration(
        array $candidates,
        float $maxDuration,
        StationClockWheelSlot $slot,
        bool $strictSchedule,
        bool $isEndOfHour = false,
    ): array {
        $fitting = array_values(array_filter(
            $candidates,
            static fn (StationMedia $m): bool => $m->getCalculatedLength() <= $maxDuration
        ));

        if ($fitting !== []) {
            return $fitting;
        }

        if ($strictSchedule || $isEndOfHour) {
            $this->logger->warning(
                'Clock Wheel: no track fits before hour boundary; end-of-hour lookahead could not place a song.',
                [
                    'available_seconds' => $maxDuration,
                    'slot_type' => $slot->type?->value,
                ]
            );

            return [];
        }

        if ($candidates === []) {
            return [];
        }

        // Every candidate, shortest first, so duplicate prevention takes the
        // shortest one that has not just played. Returning the shortest alone
        // left it no choice: the same 2-minute song filled every tight slot of
        // the "Test" wheel, three times in a row at Sun 2026-10-04 21:00.
        usort(
            $candidates,
            static fn (StationMedia $a, StationMedia $b): int =>
                $a->getCalculatedLength() <=> $b->getCalculatedLength()
        );

        $shortest = $candidates[0];
        $logKey = $this->isFlexibleMusicSlot($slot)
            ? 'Clock Wheel: no track fits the available window; using shortest music/talk candidate.'
            : 'Clock Wheel: no short-form track fits the available window; using shortest candidate with playback cap.';

        $this->logger->warning(
            $logKey,
            [
                'available_seconds' => $maxDuration,
                'media_id' => $shortest->id,
                'effective_length' => $shortest->getCalculatedLength(),
                'slot_type' => $slot->type?->value,
            ]
        );

        return $candidates;
    }

    /**
     * @param StationPlaylistQueue[] $mediaQueue
     * @param StationMedia[] $candidates
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     *
     * @return StationPlaylistQueue[]
     */
    private function applyAlgorithm(
        array $mediaQueue,
        array $candidates,
        ClockWheelSlotAlgorithms $algorithm,
        array $recentHistory,
    ): array {
        if ($algorithm === ClockWheelSlotAlgorithms::Random) {
            shuffle($mediaQueue);
            return $mediaQueue;
        }

        if ($algorithm === ClockWheelSlotAlgorithms::Sequential) {
            $algorithm = ClockWheelSlotAlgorithms::OldestTrack;
        }

        $histTimestamp = [];
        $histArtist = [];
        foreach ($recentHistory as $h) {
            $songId = $h['song_id'];
            $ts = $h['timestamp_played'];
            if ($ts instanceof DateTimeInterface) {
                $ts = $ts->getTimestamp();
            }
            $ts = (int)$ts;
            if (!isset($histTimestamp[$songId]) || $ts > $histTimestamp[$songId]) {
                $histTimestamp[$songId] = $ts;
            }
            $histArtist[$songId] = $h['artist'] ?? '';
        }

        if ($algorithm === ClockWheelSlotAlgorithms::OldestTrack) {
            usort(
                $mediaQueue,
                static function (StationPlaylistQueue $a, StationPlaylistQueue $b) use ($histTimestamp): int {
                    $tsA = $histTimestamp[$a->song_id] ?? 0;
                    $tsB = $histTimestamp[$b->song_id] ?? 0;
                    return $tsA <=> $tsB;
                }
            );
            return $mediaQueue;
        }

        $isAlbum = in_array($algorithm, [
            ClockWheelSlotAlgorithms::OldestAlbum,
            ClockWheelSlotAlgorithms::MostRecentAlbum,
        ], true);
        $isOldest = in_array($algorithm, [
            ClockWheelSlotAlgorithms::OldestAlbum,
            ClockWheelSlotAlgorithms::OldestArtist,
        ], true);

        $candidatesById = [];
        $candidatesBySongId = [];
        foreach ($candidates as $m) {
            $candidatesById[$m->id] = $m;
            $candidatesBySongId[$m->song_id] = $m;
        }

        $getGroupKey = static function (StationPlaylistQueue $q) use ($candidatesById, $isAlbum): string {
            $m = $candidatesById[$q->media_id] ?? null;
            if ($m === null) {
                return '';
            }
            return strtolower(trim((string)($isAlbum ? ($m->album ?? '') : ($m->artist ?? ''))));
        };

        $groups = [];
        foreach ($mediaQueue as $q) {
            $groups[$getGroupKey($q)][] = $q;
        }

        $groupLastPlayed = array_fill_keys(array_keys($groups), 0);

        if ($isAlbum) {
            $histSongIds = array_keys($histTimestamp);
            $histAlbum = [];

            foreach ($histSongIds as $songId) {
                if (isset($candidatesBySongId[$songId])) {
                    $histAlbum[$songId] = strtolower(trim((string)($candidatesBySongId[$songId]->album ?? '')));
                }
            }

            $missingSongIds = array_diff($histSongIds, array_keys($histAlbum));
            if ($missingSongIds !== []) {
                $rows = $this->em->createQuery(
                    'SELECT m.song_id, m.album FROM App\Entity\StationMedia m WHERE m.song_id IN (:ids)'
                )->setParameter('ids', array_values($missingSongIds))->getArrayResult();
                foreach ($rows as $row) {
                    $histAlbum[$row['song_id']] = strtolower(trim((string)($row['album'] ?? '')));
                }
            }

            foreach ($histSongIds as $songId) {
                $albumKey = $histAlbum[$songId] ?? '';
                if (!array_key_exists($albumKey, $groupLastPlayed)) {
                    continue;
                }
                $ts = $histTimestamp[$songId];
                if ($ts > $groupLastPlayed[$albumKey]) {
                    $groupLastPlayed[$albumKey] = $ts;
                }
            }
        } else {
            foreach ($histTimestamp as $songId => $ts) {
                $artistKey = strtolower(trim((string)($histArtist[$songId] ?? '')));
                if (!array_key_exists($artistKey, $groupLastPlayed)) {
                    continue;
                }
                if ($ts > $groupLastPlayed[$artistKey]) {
                    $groupLastPlayed[$artistKey] = $ts;
                }
            }
        }

        $groupKeys = array_keys($groups);
        shuffle($groupKeys);
        usort($groupKeys, static function (string $a, string $b) use ($groupLastPlayed, $isOldest): int {
            $tsA = $groupLastPlayed[$a];
            $tsB = $groupLastPlayed[$b];
            return $isOldest ? ($tsA <=> $tsB) : ($tsB <=> $tsA);
        });

        $sorted = [];
        foreach ($groupKeys as $key) {
            $groupItems = $groups[$key];
            shuffle($groupItems);
            foreach ($groupItems as $q) {
                $sorted[] = $q;
            }
        }

        return $sorted;
    }
}
