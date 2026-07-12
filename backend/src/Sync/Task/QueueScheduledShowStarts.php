<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Entity\StationSchedule;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
use App\Radio\AutoDJ\QueueBuilder;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Extends the same wall-clock "hard start" principle used for the top-of-hour ID to
 * EVERY calendar-scheduled show (any playlist with schedule items), not just the hour
 * boundary.
 *
 * WHY: {@see \App\Radio\AutoDJ\Scheduler::shouldSchedulePlayNow} only answers "is now
 * inside this show's window" — it gets asked that question exclusively when
 * {@see \App\Radio\AutoDJ\Queue::buildQueue} runs, which only meaningfully changes the
 * queue once the CURRENTLY PLAYING item finishes. A show can sit "eligible" for its
 * whole window without ever actually being picked promptly if whatever's already
 * playing runs long. That's the "shows start 10-15 minutes late" behavior.
 *
 * This task runs every minute (no new crontab, no manual setup — same as
 * QueueTopOfHourId) and, the moment a schedule's start time has JUST arrived (within
 * the last ~90 seconds), proactively pushes that show's first track to Liquisoap's
 * `requests` queue — a SOFT push, same as the ID: it waits for whatever's currently
 * playing to end, it does not hard-cut. That alone takes a show from "sometime in a
 * 15-minute window, whenever AutoDJ happens to notice" to "queued the moment its slot
 * begins, plays as soon as the current song is done" — a large, low-risk improvement
 * without introducing hard interruptions for regular scheduled content.
 *
 * SAFETY: this only ever ADDS a queue entry, using the exact same
 * {@see StationQueue::fromMedia} + {@see AnnotateNextSong} pattern already proven by
 * the top-of-hour ID and AI DJ code — nothing here touches existing queue rows, Song
 * History, or Now Playing data. Every station/schedule is wrapped in its own
 * try/catch so one bad schedule can never affect another or break the sync cycle.
 *
 * Deliberately scoped to playlist-driven schedules only — streamer schedules and Clock
 * Wheel schedules are excluded (Clock Wheel already has its own dedicated precision
 * system; streamers are live humans, not something to auto-queue).
 */
final class QueueScheduledShowStarts extends AbstractTask
{
    /** How long after a show's start time we still consider it "just starting". */
    private const int START_WINDOW_SECONDS = 90;

    public function __construct(
        private readonly StationScheduleRepository $scheduleRepo,
        private readonly StationQueueRepository $queueRepo,
        private readonly Scheduler $scheduler,
        private readonly QueueBuilder $queueBuilder,
        private readonly Adapters $adapters,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CacheInterface $cache,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            try {
                $this->queueForStation($station);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Hard-start scheduler: failed for station "%s": %s', $station->name, $e->getMessage())
                );
            }
        }
    }

    private function queueForStation(Station $station): void
    {
        $backend = $this->adapters->getBackendAdapter($station);
        if (!($backend instanceof Liquidsoap)) {
            return;
        }

        $tz = $station->getTimezoneObject();
        $now = new DateTimeImmutable('now');

        foreach ($this->scheduleRepo->getAllScheduledItemsForStation($station) as $schedule) {
            /** @var StationSchedule $schedule */
            try {
                $this->maybeHardStart($station, $backend, $schedule, $tz, $now);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Hard-start scheduler: failed for schedule #%d on station "%s": %s',
                    $schedule->id ?? 0,
                    $station->name,
                    $e->getMessage()
                ));
            }
        }
    }

    private function maybeHardStart(
        Station $station,
        Liquidsoap $backend,
        StationSchedule $schedule,
        \DateTimeZone $tz,
        DateTimeImmutable $now,
    ): void {
        // Only plain playlist-driven shows — Clock Wheel has its own precision system,
        // and streamer schedules aren't something to auto-queue a track for.
        $playlist = $schedule->playlist;
        if (null === $playlist || null !== $schedule->clock_wheel) {
            return;
        }

        if (!$playlist->isPlayable() || PlaylistSources::Songs !== $playlist->source) {
            return;
        }

        // Only for genuine one-time-of-day starts, not continuous/always-on playlists.
        if ($schedule->start_time === $schedule->end_time) {
            return;
        }

        $secondsSinceStart = $this->secondsSinceMostRecentStart($schedule, $tz, $now);
        if (null === $secondsSinceStart || $secondsSinceStart < 0 || $secondsSinceStart > self::START_WINDOW_SECONDS) {
            return;
        }

        // Confirms day-of-week / date-range validity using the exact same logic the
        // regular AutoDJ path already relies on — reused, not reimplemented.
        if (!$this->scheduler->shouldSchedulePlayNow($schedule, $tz, $now)) {
            return;
        }

        // Per-occurrence idempotency lock: keyed to this specific start instant, not
        // just "today", so a schedule that starts twice in one day (rare, but
        // possible with certain recurrence setups) still gets a fresh lock each time,
        // while a single occurrence can never fire twice.
        $occurrenceStart = $now->getTimestamp() - $secondsSinceStart;
        $lockKey = sprintf('hard_start_lock_%d_%d', $schedule->id, $occurrenceStart);

        if (null !== $this->cache->get($lockKey)) {
            return;
        }
        // Held for 6 hours: comfortably longer than any single show, self-clears.
        $this->cache->set($lockKey, time(), 21600);

        // Don't double-queue if the regular AutoDJ path already picked this playlist
        // up on its own within the last couple minutes (e.g. lucky timing).
        if (null !== $playlist->played_at && $playlist->played_at->getTimestamp() >= $occurrenceStart) {
            $this->logger->debug('Hard-start scheduler: playlist already played for this occurrence, skipping.', [
                'playlist_id' => $playlist->id,
            ]);
            return;
        }

        // Don't stack on top of something already sitting in the requests queue.
        if (!$backend->isQueueEmpty($station, LiquidsoapQueues::Requests)) {
            $this->logger->debug('Hard-start scheduler: requests queue not empty, will retry next tick.');
            $this->cache->delete($lockKey);
            return;
        }

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $now,
            $station->backend_config->duplicate_prevention_time_range
        );

        $validTrack = $this->queueBuilder->pickNextTrackFromPlaylist($playlist, $recentHistory);
        if (null === $validTrack) {
            $this->logger->warning('Hard-start scheduler: no playable track found for scheduled show.', [
                'playlist_id' => $playlist->id,
            ]);
            $this->cache->delete($lockKey);
            return;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            $this->cache->delete($lockKey);
            return;
        }

        // Same construction pattern already proven by the top-of-hour ID and Clock
        // Wheel code (StationQueue::fromMedia + AnnotateNextSong) — this is what keeps
        // Now Playing and Song History accurate; nothing new is invented here.
        $queueEntry = StationQueue::fromMedia($station, $media);
        $queueEntry->playlist = $playlist;
        $this->em->persist($queueEntry);

        $playlist->played_at = $now;
        $this->em->persist($playlist);
        $this->em->flush();

        $event = AnnotateNextSong::fromStationQueue($queueEntry, true);
        $this->eventDispatcher->dispatch($event);

        $track = $event->buildAnnotations();
        // Soft push only — waits for the current song to end, same philosophy as the
        // ID's normal path. A scheduled show starting promptly doesn't need (and
        // shouldn't get) a hard cut; that's reserved for true legal-compliance cases.
        $response = $backend->enqueue($station, LiquidsoapQueues::Requests, $track);

        $this->logger->info('Hard-start scheduler: queued scheduled show at its start time.', [
            'station_id' => $station->id,
            'playlist_id' => $playlist->id,
            'schedule_id' => $schedule->id,
            'seconds_since_start' => $secondsSinceStart,
            'response' => $response,
        ]);
    }

    /**
     * Seconds since this schedule's most recent start instant, checking both today's
     * and yesterday's date so overnight schedules (start_time > end_time) are handled
     * correctly. Returns null if neither candidate is a sane match.
     */
    private function secondsSinceMostRecentStart(
        StationSchedule $schedule,
        \DateTimeZone $tz,
        DateTimeImmutable $now,
    ): ?int {
        $nowTs = $now->getTimestamp();

        $candidates = [
            StationSchedule::getDateTime($schedule->start_time, $tz, $now),
            StationSchedule::getDateTime($schedule->start_time, $tz, $now)->subDay(),
        ];

        foreach ($candidates as $candidate) {
            $diff = $nowTs - $candidate->getTimestamp();
            if ($diff >= 0 && $diff <= self::START_WINDOW_SECONDS) {
                return $diff;
            }
        }

        return null;
    }
}
