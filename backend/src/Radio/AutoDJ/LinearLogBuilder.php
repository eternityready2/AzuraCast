<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\AiDjScheduleRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationRepository;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Lock\LockFactory;
use App\Message\AbstractMessage;
use App\Message\BuildLinearLogMessage;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Service\AiNewsGenerator;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class LinearLogBuilder
{
    use EntityManagerAwareTrait;

    /**
     * The selected horizon is a minimum. Keep one additional program hour so a
     * 24-hour log does not hard-stop at exactly +24:00 and operators can see the
     * handoff into the next hour. This also gives the scheduled 12-hour rebuild a
     * small safety runway if a build is delayed.
     */
    public const int SAFETY_RUNWAY_MINUTES = 60;

    /** Longest a build may hold the station's build lock, and wait for it. */
    private const int BUILD_LOCK_SECONDS = 1200;

    public function __construct(
        private readonly Queue $queue,
        private readonly StationQueueRepository $queueRepo,
        private readonly StationRepository $stationRepo,
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly LinearLogPreviewContext $previewContext,
        private readonly AiDjScheduleRepository $aiDjScheduleRepo,
        private readonly RigidScheduleWindowResolver $rigidScheduleWindowResolver,
        private readonly RigidScheduleForecastService $rigidScheduleForecast,
        private readonly LinearLog\LinearLogStore $logStore,
        private readonly LinearLog\LinearLogRules $logRules,
        private readonly TopOfHourClock $topOfHourClock,
        private readonly AiNewsScheduleForecastService $aiNewsScheduleForecast,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function __invoke(AbstractMessage $message): void
    {
        if (!$message instanceof BuildLinearLogMessage) {
            return;
        }

        $station = $this->stationRepo->findByIdentifier((string)$message->stationId);
        if (!$station instanceof Station || !$station->supportsAutoDjQueue()) {
            return;
        }

        if (!$message->force && !$station->backend_config->linear_log_enabled) {
            $this->snapshotStore->cancelQueued($station);
            return;
        }

        $this->build(
            $station,
            $message->hours,
            $message->rebuild,
            $message->throughTomorrow,
            $message->replanLockWindow,
        );
    }

    /** @return array<string, mixed> */
    public function build(
        Station $station,
        ?int $hoursOverride = null,
        bool $rebuild = false,
        bool $throughTomorrow = false,
        bool $replanLockWindow = false,
    ): array {
        $stationId = $station->id;

        // Any earlier failure in this worker process leaves Doctrine's
        // EntityManager closed, and the build then dies on "The EntityManager is
        // closed." before touching the database -- the failure operators actually
        // see on the Linear Log page. Start from a usable one.
        if (!$this->em->isOpen()) {
            $this->em->open();
            $station = $this->stationRepo->findByIdentifier((string)$stationId) ?? $station;
        }

        // One build per station at a time. Two overlapping builds each replace
        // the plan they read at their start, so both plans survive: the 3am daily
        // build and a manual rebuild on 2026-10-05 left 529 overlapping lines and
        // two copies of the 10:00 and 11:00 shows.
        $lock = $this->lockFactory->createLock('linear_log_build_' . $stationId, self::BUILD_LOCK_SECONDS);
        $waitUntil = time() + self::BUILD_LOCK_SECONDS;
        while (!$lock->acquire()) {
            if (time() >= $waitUntil) {
                throw new RuntimeException('Another Linear Log build for this station is still running.');
            }
            sleep(2);
        }

        try {
            // The build that held the lock changed the plan; start from it.
            $this->em->clear();
            $station = $this->stationRepo->findByIdentifier((string)$stationId) ?? $station;

            return $this->buildWithRetry($station, $hoursOverride, $rebuild, $throughTomorrow, $replanLockWindow);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function buildWithRetry(
        Station $station,
        ?int $hoursOverride,
        bool $rebuild,
        bool $throughTomorrow,
        bool $replanLockWindow,
    ): array {
        $stationId = $station->id;

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->buildOnce($station, $hoursOverride, $rebuild, $throughTomorrow, $replanLockWindow);
            } catch (Throwable $e) {
                // A plan conflict gets a third attempt: playout keeps taking
                // lines while a lock-window re-plan runs, and each retry starts
                // from the log as playout left it.
                $isConflict = $e instanceof LinearLog\LinearLogPlanConflict;
                $maxAttempts = $isConflict ? 3 : 2;
                if ($attempt >= $maxAttempts || !($isConflict || self::isTransientTransactionError($e))) {
                    // Failed only once there is no retry left: marked failed
                    // before a retry that then succeeded, the page showed "build
                    // failed" for a build that recovered 28s later (11:59
                    // 2026-10-07).
                    // The failed flush may have closed the EntityManager; reopen
                    // it so recording the failure cannot replace the real error.
                    $this->resetConnectionState();
                    try {
                        $this->snapshotStore->markFailed(
                            $station,
                            max(1, min(48, $hoursOverride ?? $station->backend_config->linear_log_hours)),
                            self::describeFailure($e),
                        );
                    } catch (Throwable $markError) {
                        $this->logger->error(
                            'Linear Log: could not record the failed build.',
                            ['station_id' => $stationId, 'error' => $markError->getMessage()]
                        );
                    }
                    throw $e;
                }

                // The preview runs inside one long REPEATABLE READ transaction that
                // writes to live queue and playlist rows. When live playout changes
                // one of those rows first, MariaDB ends the whole transaction: a
                // deadlock, or (with innodb_snapshot_isolation, on by default in
                // 11.8) error 1020 "Record has changed since last read". Doctrine's
                // savepoint rollback then fails with "SAVEPOINT DOCTRINE_n does not
                // exist", which replaces the real cause. Wait briefly and retry
                // once from a clean state.
                $this->logger->warning(
                    'Linear Log build hit a transient error; retrying.',
                    [
                        'station_id' => $stationId,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                        'cause' => self::describeFailure($e),
                    ]
                );

                $this->resetConnectionState();

                $reloaded = $this->stationRepo->findByIdentifier((string)$stationId);
                if (!$reloaded instanceof Station) {
                    throw $e;
                }
                $station = $reloaded;

                usleep(random_int(1_500_000, 4_000_000));
            }
        }
    }

    /**
     * Unwind every open nesting level, then make sure the EntityManager is usable.
     *
     * A single rollBack() drops only one level, and once MySQL has killed the
     * transaction the savepoint rollback throws without DBAL decrementing its
     * counter -- so the retry opened SAVEPOINT DOCTRINE_3 on a connection with no
     * transaction and died the same way. Closing the connection resets the level
     * to zero. Doctrine also closes the EntityManager on the original exception,
     * which otherwise leaves this worker unable to handle any later message
     * ("The EntityManager is closed." on every NowPlaying/webhook until restart).
     */
    private function resetConnectionState(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                $connection->close();
                break;
            }
        }

        if (!$this->em->isOpen()) {
            $this->em->open();
        }

        $this->em->clear();
    }

    private static function isTransientTransactionError(Throwable $e): bool
    {
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            $message = $current->getMessage();
            if (
                str_contains($message, 'SAVEPOINT')
                || str_contains($message, 'Deadlock')
                || str_contains($message, 'Record has changed since last read')
                || str_contains($message, 'Lock wait timeout')
                || str_contains($message, 'server has gone away')
                || str_contains($message, 'Lost connection')
                // Doctrine closes the EntityManager on the underlying DBAL error,
                // so a mid-build closure is the same transient fault seen one
                // layer up; resetConnectionState() reopens it before the retry.
                || str_contains($message, 'EntityManager is closed')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the page says when a build fails. A transaction the database ended
     * under the build reads as "SAVEPOINT DOCTRINE_2 does not exist", which
     * names Doctrine's failed cleanup, not what happened.
     */
    private static function describeFailure(Throwable $e): string
    {
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            $message = $current->getMessage();
            if (
                str_contains($message, 'SAVEPOINT')
                || str_contains($message, 'Deadlock')
                || str_contains($message, 'Record has changed since last read')
            ) {
                return 'Live playout changed the queue or playlist rows this build was simulating, '
                    . 'so the database stopped the build. The saved log is unchanged; '
                    . 'the next build tries again. (' . $e->getMessage() . ')';
            }
        }

        return $e->getMessage();
    }

    /** @return array<string, mixed> */
    private function buildOnce(
        Station $station,
        ?int $hoursOverride = null,
        bool $rebuild = false,
        bool $throughTomorrow = false,
        bool $replanLockWindow = false,
    ): array {
        $stationId = $station->id;
        $hours = max(1, min(48, $hoursOverride ?? $station->backend_config->linear_log_hours));
        $requestedLookaheadMinutes = $hours * 60;
        if ($throughTomorrow) {
            // FM-style daily log: a day until the next daily build, plus the
            // configured horizon (at most 48h), so the log still holds that
            // horizon just before the next daily build. Reaching only the end of
            // tomorrow left 21h of a 24h log at 2:59am, which the hourly top-up
            // used to cover.
            $requestedLookaheadMinutes = min(48 * 60, (24 + $hours) * 60);
        }
        $lookaheadMinutes = $requestedLookaheadMinutes + self::SAFETY_RUNWAY_MINUTES;
        $maxTracks = max(1000, $lookaheadMinutes * 2);
        $buildStartedAt = time();
        $projectionStart = Time::nowUtc();
        $projectionStartTs = $projectionStart->getTimestamp();
        $projectionEnd = $projectionStart->modify('+' . $lookaheadMinutes . ' minutes');
        $projectionEndTs = $projectionEnd->getTimestamp();

        // Strict / Exact Time schedules are rendered by a dedicated native
        // Liquidsoap source above the ordinary AutoDJ queue. Capture both the
        // authoritative windows and that native source's exact song cursor before
        // starting the isolated PHP queue simulation.
        $rigidWindows = $this->rigidScheduleWindowResolver->getWindows(
            $station,
            $projectionStart,
            $projectionEnd,
        );
        $rigidForecastItems = $this->rigidScheduleForecast->getForecast(
            $station,
            $projectionStart,
            $projectionEnd,
            $maxTracks,
        );

        $liveQueueIds = [];
        foreach ($this->queueRepo->getUnplayedQueue($station) as $queueRow) {
            $liveQueueIds[$queueRow->id] = true;
        }

        $remoteUrlPlaylistIds = [];
        foreach ($station->playlists as $pl) {
            if (PlaylistSources::RemoteUrl === $pl->source) {
                $remoteUrlPlaylistIds[$pl->id] = true;
            }
        }

        // Saved linear log (log controls playout): keep the planned lines and
        // only extend them; a rebuild request re-plans hours past the lock.
        $playout = LinearLog\LinearLogPlayout::isPlayoutEnabled($station);

        // A rebuild re-plans the unlocked hours past the lock window. The lines
        // it replaces are only read here, and deleted at the end together with
        // the new plan: deleting them up front left the log empty whenever a
        // build died in between.
        // After a schedule change the lock window is re-planned too: only lines
        // already handed to the live queue (and hand-locked lines) stay.
        $lockedUntil = $replanLockWindow
            ? $projectionStartTs
            : $projectionStartTs + LinearLog\LinearLogStore::LOCK_SECONDS;
        $replacedPlanIds = ($playout && $rebuild)
            ? $this->logStore->unlockedPlanIds($station, $lockedUntil)
            : [];
        $logRows = [];
        $seededPlannedAt = [];
        $survivingIds = [];

        $this->snapshotStore->markBuilding($station, $hours);
        $this->previewContext->begin();

        $connection = $this->em->getConnection();
        $entries = [];
        $gaps = [];
        $coverageEnd = $projectionStartTs;

        try {
            $connection->beginTransaction();

            if ($playout) {
                $seededPlannedAt = $this->logStore->seedQueue(
                    $station,
                    $replacedPlanIds,
                    $rebuild ? $lockedUntil : null,
                );
            }

            $gaps = $this->queue->buildQueue(
                $station,
                $lookaheadMinutes,
                $maxTracks,
                true,
                $seededPlannedAt,
            );

            $rows = $this->queueRepo->getUnplayedQueue($station);
            usort(
                $rows,
                static fn(StationQueue $a, StationQueue $b): int =>
                    ($a->timestamp_played?->getTimestamp() ?? 0) <=> ($b->timestamp_played?->getTimestamp() ?? 0)
            );

            foreach ($rows as $row) {
                if (null !== $row->log_entry_id) {
                    $survivingIds[$row->log_entry_id] = true;
                }
            }

            // When each strict programme's own songs run out. Past that point the
            // native strict source has nothing to play and the air falls through
            // to the AutoDJ, so its rows there are real airplay, not duplicates.
            $rigidContentEnds = [];
            foreach ($rigidForecastItems as $forecastItem) {
                $key = $this->scheduleKey($forecastItem->schedule);
                $itemEnd = $forecastItem->playedAt->getTimestamp() + (int)ceil($forecastItem->duration);
                $rigidContentEnds[$key] = max($rigidContentEnds[$key] ?? 0, $itemEnd);
            }

            $sequence = 0;
            foreach ($rows as $row) {
                $playedAt = $row->timestamp_played?->getTimestamp();
                if (null === $playedAt) {
                    continue;
                }

                if ($playedAt < ($projectionStartTs - 300) || $playedAt > $projectionEndTs) {
                    continue;
                }

                // Read the relation, not playlist_id: that column is read-only and
                // stays null on rows created in this same unit of work (the seeded
                // stream programmes), which let them be re-timed off the window.
                $rowPlaylistId = $row->playlist?->id;
                if (null !== $rowPlaylistId && isset($remoteUrlPlaylistIds[$rowPlaylistId])) {
                    continue;
                }

                $entry = $this->mapQueueRow(
                    $row,
                    ++$sequence,
                    isset($liveQueueIds[$row->id]),
                );
                $entry = $this->applyRigidWindowsToQueueEntry($entry, $rigidWindows, $rigidContentEnds);

                if ($playout) {
                    $logRows[] = $this->logStore->describeRow(
                        $row,
                        $playedAt,
                        isset($liveQueueIds[$row->id]),
                        null === $entry ? null : (string)$entry['id'],
                    );
                }

                if (null === $entry) {
                    continue;
                }

                $duration = max(1.0, (float)$entry['duration']);
                $coverageEnd = max($coverageEnd, $playedAt + (int)ceil($duration));
                $entries[] = $entry;
            }

            // A PHP preview gap beneath a native strict programme is not a real
            // on-air gap. The strict native source owns that interval.
            $gaps = array_values(array_filter(
                $gaps,
                fn(array $gap): bool => !$this->rangeOverlapsRigidWindow(
                    (int)$gap['started_at'],
                    (int)$gap['started_at'] + (int)$gap['duration'],
                    $rigidWindows,
                ),
            ));

            foreach ($gaps as $gap) {
                $coverageEnd = max(
                    $coverageEnd,
                    (int)$gap['started_at'] + (int)$gap['duration'],
                );
            }

            // Insert the actual songs from the strict native playlist cursor.
            // These are the same songs exposed by Overview -> Playing Next and
            // Broadcasting -> Upcoming Song Queue.
            $forecastedScheduleKeys = [];
            foreach ($rigidForecastItems as $forecastItem) {
                $entry = $this->mapRigidForecastItem($forecastItem, ++$sequence);
                $entries[] = $entry;
                $forecastedScheduleKeys[$this->scheduleKey($forecastItem->schedule)] = true;
                $coverageEnd = max(
                    $coverageEnd,
                    (int)$entry['played_at'] + (int)ceil((float)$entry['duration']),
                );
            }

            // Remote strict sources cannot be expanded into local StationMedia
            // songs. Keep one truthful programme block for those only; local song
            // playlists always use the actual per-song rows above.
            foreach ($rigidWindows as $window) {
                $scheduleKey = $this->scheduleKey($window['schedule']);
                if (isset($forecastedScheduleKeys[$scheduleKey])) {
                    continue;
                }

                $marker = $this->mapRigidScheduleWindow(
                    $window,
                    $projectionStartTs,
                    $projectionEndTs,
                );
                if (null === $marker) {
                    continue;
                }

                $entries[] = $marker;
                $coverageEnd = max(
                    $coverageEnd,
                    (int)$marker['played_at'] + (int)ceil((float)$marker['duration']),
                );
            }

            // The Top-of-Hour Station ID is injected natively by Liquidsoap at
            // :59:59, so it is never an AutoDJ queue row and would otherwise be
            // invisible in the log even though it airs every hour. Synthesize a
            // marker line for each boundary the ID owns so the log reflects what
            // is really on air across the hour change.
            $idMarkers = $this->buildTopOfHourIdMarkers($station, $projectionStartTs, $projectionEndTs);
            foreach ($idMarkers as $marker) {
                $entries[] = $marker;
                $coverageEnd = max(
                    $coverageEnd,
                    (int)$marker['played_at'] + (int)ceil((float)$marker['duration']),
                );
            }

            // AI News is likewise Liquidsoap-owned with no queue row: it is
            // pushed straight into its own lane after the Station ID. Without a
            // marker the log showed music running across an hour that really
            // opens with a bulletin.
            foreach ($this->buildAiNewsMarkers($station, $projectionStartTs, $projectionEndTs, $idMarkers) as $marker) {
                $entries[] = $marker;
                $coverageEnd = max(
                    $coverageEnd,
                    (int)$marker['played_at'] + (int)ceil((float)$marker['duration']),
                );
            }

            usort(
                $entries,
                static fn(array $a, array $b): int =>
                    ((int)($a['played_at'] ?? 0)) <=> ((int)($b['played_at'] ?? 0)),
            );
        } finally {
            // A failure is marked by buildWithRetry() once no retry is left.
            // Unguarded, a throwing rollBack() here replaces the real exception
            // and hides why the build failed. Unwind every level instead.
            while ($connection->isTransactionActive()) {
                try {
                    $connection->rollBack();
                } catch (Throwable) {
                    $connection->close();
                    break;
                }
            }

            $this->previewContext->end();

            if ($this->em->isOpen()) {
                $this->em->clear();
            }
        }

        $managedStation = $this->stationRepo->findByIdentifier((string)$stationId);
        if (!$managedStation instanceof Station) {
            throw new RuntimeException('Station could not be reloaded after Linear Log preview.');
        }
        $station = $managedStation;

        if ($playout) {
            // Planned lines the simulation removed no longer play; drop them.
            $this->logStore->dropUnplannable(
                $station,
                array_values(array_filter(
                    array_keys($seededPlannedAt),
                    static fn(int $id): bool => !isset($survivingIds[$id]),
                )),
            );
            $entries = $this->logStore->applyPlan($station, $logRows, $entries, $replacedPlanIds);

            // Create log entries for scheduled_programme markers so operators
            // can hand-edit (drop / replace) them like any other log line.
            $entries = $this->persistProgrammeLogEntries($station, $entries);

            // Standing operator rules police the plan the builder just wrote, so
            // a line that may not play at its planned time never reaches the log
            // the operator reads (or the queue playout takes it from).
            $ruleResult = $this->logRules->apply($station, $projectionStartTs);
            if ($ruleResult['dropped'] > 0) {
                // Match on the log entry id, not the report entry's 'id': a queue
                // line keeps its 'projection-N' id, so comparing 'log-N' strings
                // left every rule-dropped line visible in the log.
                $droppedIds = array_fill_keys($ruleResult['dropped_ids'], true);
                /** @var list<array<string, mixed>> $entries */
                $entries = array_values(array_filter(
                    $entries,
                    static fn(array $entry): bool => empty($entry['log_entry_id'])
                        || !isset($droppedIds[(int)$entry['log_entry_id']]),
                ));
            }
        }

        $aiDjShifts = $this->buildAiDjShifts($station, $projectionStartTs, $projectionEndTs);

        $this->snapshotStore->storeReady(
            $station,
            $hours,
            $buildStartedAt,
            $projectionStartTs,
            $coverageEnd,
            $entries,
            $gaps,
            $aiDjShifts,
        );

        return $this->snapshotStore->get($station);
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<array{playlist: \App\Entity\StationPlaylist, schedule: \App\Entity\StationSchedule, start: \Carbon\CarbonImmutable, end: \Carbon\CarbonImmutable}> $windows
     * @return array<string, mixed>|null
     */
    private function applyRigidWindowsToQueueEntry(array $entry, array $windows, array $contentEnds = []): ?array
    {
        if ((bool)$entry['top_of_hour_legal_id']) {
            return $entry;
        }

        $start = (int)($entry['played_at'] ?? 0);
        $end = $start + (int)ceil((float)$entry['duration']);

        foreach ($windows as $window) {
            $windowStart = $window['start']->getTimestamp();
            $windowEnd = $window['end']->getTimestamp();

            if ($start >= $windowStart && $start < $windowEnd) {
                // The strict programme's own episode is aired by the native
                // strict source; an AutoDJ copy of it is always a duplicate.
                if ((int)($entry['playlist_id'] ?? 0) === $window['playlist']->id) {
                    return null;
                }

                // After the programme's content has finished, the AutoDJ fills
                // the rest of the window. Remote strict sources have no forecast
                // and own the whole window.
                $contentEnd = $contentEnds[$this->scheduleKey($window['schedule'])] ?? $windowEnd;
                if ($start >= $contentEnd) {
                    continue;
                }

                return null;
            }

            if ($start < $windowStart && $end > $windowStart) {
                $entry['duration'] = max(1.0, (float)($windowStart - $start));
                return $entry;
            }
        }

        return $entry;
    }

    /** @return array<string, mixed> */
    private function mapRigidForecastItem(
        RigidScheduleForecastItem $item,
        int $sequence,
    ): array {
        $media = $item->media;
        $playedAt = $item->playedAt->getTimestamp();

        return [
            'id' => 'strict-forecast-' . $sequence,
            'queue_id' => 0,
            'song_id' => $media->song_id,
            'played_at' => $playedAt,
            'cued_at' => $playedAt,
            'duration' => max(1.0, $item->duration),
            'title' => $media->title,
            'artist' => $media->artist,
            'album' => $media->album,
            'text' => $media->text,
            'playlist' => $item->playlist->name,
            'playlist_id' => $item->playlist->id,
            'playlist_chain' => null,
            'clock_wheel' => null,
            'clock_wheel_id' => null,
            'media_type' => $media->type,
            'source_type' => 'scheduled_programme',
            'is_request' => false,
            'is_live_queue' => false,
            'sent_to_autodj' => true,
            'top_of_hour_legal_id' => false,
            'autodj_custom_uri' => null,
            'clock_wheel_schedule_mode' => null,
            'clock_wheel_enforce_cap' => false,
            'clock_wheel_stretch_ratio' => null,
            'clock_wheel_legal_id_substitute' => false,
            'hour_boundary_enforce_cap' => false,
            'hour_boundary_max_play_seconds' => null,
            'top_of_hour_pre_id_fade' => false,
        ];
    }

    /**
     * @param array{playlist: \App\Entity\StationPlaylist, schedule: \App\Entity\StationSchedule, start: \Carbon\CarbonImmutable, end: \Carbon\CarbonImmutable} $window
     * @return array<string, mixed>|null
     */
    private function mapRigidScheduleWindow(array $window, int $projectionStartTs, int $projectionEndTs): ?array
    {
        // A programme already on air keeps its real start. Clipping it to the
        // build time gave the same block a new start, and so a new log line, on
        // every rebuild.
        $start = $window['start']->getTimestamp();
        $end = min($projectionEndTs, $window['end']->getTimestamp());
        if ($end <= max($projectionStartTs, $start)) {
            return null;
        }

        $playlist = $window['playlist'];
        $schedule = $window['schedule'];

        return [
            'id' => 'rigid-schedule-' . $this->scheduleKey($schedule) . '-' . $start,
            'queue_id' => 0,
            'song_id' => '',
            'played_at' => $start,
            'cued_at' => $start,
            'duration' => (float)($end - $start),
            'title' => $playlist->name,
            'artist' => null,
            'album' => null,
            'text' => PlaylistSources::RemoteUrl === $playlist->source
                ? 'Strict scheduled remote programme'
                : 'Strict scheduled programme',
            'playlist' => $playlist->name,
            'playlist_id' => $playlist->id,
            'playlist_chain' => null,
            'clock_wheel' => null,
            'clock_wheel_id' => null,
            'media_type' => 'programme',
            'source_type' => 'scheduled_programme',
            'is_request' => false,
            'is_live_queue' => false,
            'sent_to_autodj' => true,
            'top_of_hour_legal_id' => false,
            'autodj_custom_uri' => null,
            'clock_wheel_schedule_mode' => null,
            'clock_wheel_enforce_cap' => false,
            'clock_wheel_stretch_ratio' => null,
            'clock_wheel_legal_id_substitute' => false,
            'hour_boundary_enforce_cap' => false,
            'hour_boundary_max_play_seconds' => null,
            'top_of_hour_pre_id_fade' => false,
        ];
    }

    /**
     * One synthetic Top-of-Hour Station ID marker per boundary the native ID lane
     * owns, for the whole projection range. The ID starts at the configured
     * :MM:SS (this station: :59:59) and runs its aired length into the next hour.
     * Boundaries a Clock Wheel owns are skipped -- those carry their own legal-ID
     * substitute, which already appears as a real queue row.
     *
     * @return list<array<string, mixed>>
     */
    private function buildTopOfHourIdMarkers(Station $station, int $startTs, int $endTs): array
    {
        if (!$this->topOfHourClock->isEnabled($station)) {
            return [];
        }

        $tz = $station->getTimezoneObject();
        $idStartMinute = $this->topOfHourClock->getIdStartMinute($station);
        $idStartSecond = $this->topOfHourClock->getIdStartSecond($station);
        $idLength = $this->recentTopOfHourIdLength($station);

        $markers = [];

        // Walk each hour in range. The ID for boundary HH+1:00:00 begins in hour
        // HH at :MM:SS, so start from the hour containing $startTs.
        $cursor = CarbonImmutable::createFromTimestamp($startTs, $tz)->startOfHour();
        $rangeEnd = CarbonImmutable::createFromTimestamp($endTs, $tz);

        while ($cursor->getTimestamp() <= $endTs) {
            $idStart = $cursor->setTime($cursor->hour, $idStartMinute, $idStartSecond);
            $idStartTs = $idStart->getTimestamp();
            $cursor = $cursor->addHour();

            if ($idStartTs < $startTs || $idStartTs > $endTs) {
                continue;
            }

            $boundary = $idStart->startOfHour()->addHour()->toDateTimeImmutable();
            if ($this->topOfHourClock->clockWheelOwnsBoundary($station, $boundary)) {
                continue;
            }

            $markers[] = [
                'id' => 'top-of-hour-id-' . $idStartTs,
                'queue_id' => 0,
                'song_id' => '',
                'played_at' => $idStartTs,
                'cued_at' => $idStartTs,
                'duration' => (float)$idLength,
                'title' => 'Top-of-Hour Station ID',
                'artist' => null,
                'album' => null,
                'text' => 'Top-of-Hour Station ID',
                'playlist' => null,
                'playlist_id' => null,
                'playlist_chain' => null,
                'clock_wheel' => null,
                'clock_wheel_id' => null,
                'media_type' => 'id',
                'source_type' => 'top_of_hour_id',
                'is_request' => false,
                'is_live_queue' => false,
                'sent_to_autodj' => true,
                'top_of_hour_legal_id' => true,
                'autodj_custom_uri' => null,
                'clock_wheel_schedule_mode' => null,
                'clock_wheel_enforce_cap' => false,
                'clock_wheel_stretch_ratio' => null,
                'clock_wheel_legal_id_substitute' => false,
                'hour_boundary_enforce_cap' => false,
                'hour_boundary_max_play_seconds' => null,
                'top_of_hour_pre_id_fade' => false,
            ];
        }

        return $markers;
    }

    /**
     * Marker lines for each AI News bulletin due to air in the range.
     *
     * A bulletin due while a Station ID airs starts when the ID ends, as it does
     * on air: ID 12:59:59 for 38s, News 13:00:36. The forecast gives the hour
     * boundary, and a marker drawn there ran 36s early and ended while the
     * bulletin was still on air.
     *
     * @param list<array<string, mixed>> $idMarkers from buildTopOfHourIdMarkers()
     * @return list<array<string, mixed>>
     */
    private function buildAiNewsMarkers(Station $station, int $startTs, int $endTs, array $idMarkers): array
    {
        $config = $station->backend_config;
        if (!$config->ai_news_enabled) {
            return [];
        }

        $airingTimes = $this->aiNewsScheduleForecast->getAiringTimes(
            $station,
            CarbonImmutable::createFromTimestamp($startTs)->toDateTimeImmutable(),
            CarbonImmutable::createFromTimestamp($endTs)->toDateTimeImmutable(),
        );

        $duration = $this->currentAiNewsBulletinLength($station);
        $markers = [];

        foreach ($airingTimes as $airsAt) {
            $airsAtTs = $airsAt->getTimestamp();
            if ($airsAtTs < $startTs || $airsAtTs > $endTs) {
                continue;
            }

            $startsAt = $airsAtTs;
            foreach ($idMarkers as $id) {
                $idEnd = (int)$id['played_at'] + (int)round((float)$id['duration']);
                if ((int)$id['played_at'] <= $airsAtTs && $idEnd > $airsAtTs) {
                    $startsAt = $idEnd;
                    break;
                }
            }

            $markers[] = [
                'id' => 'ai-news-' . $airsAtTs,
                'queue_id' => 0,
                'song_id' => '',
                'played_at' => $startsAt,
                'cued_at' => $startsAt,
                'duration' => $duration,
                'title' => 'News Hour',
                'artist' => 'Eternity Ready',
                'album' => 'News Bulletin',
                'text' => 'Eternity Ready - News Hour',
                'playlist' => null,
                'playlist_id' => null,
                'playlist_chain' => null,
                'clock_wheel' => null,
                'clock_wheel_id' => null,
                'media_type' => 'talk',
                'source_type' => 'ai_news',
                'is_request' => false,
                'is_live_queue' => false,
                'sent_to_autodj' => true,
                'top_of_hour_legal_id' => false,
                'autodj_custom_uri' => null,
                'clock_wheel_schedule_mode' => null,
                'clock_wheel_enforce_cap' => false,
                'clock_wheel_stretch_ratio' => null,
                'clock_wheel_legal_id_substitute' => false,
                'hour_boundary_enforce_cap' => false,
                'hour_boundary_max_play_seconds' => null,
                'top_of_hour_pre_id_fade' => false,
            ];
        }

        return $markers;
    }

    /**
     * Length of the bulletin currently staged on disk, so the marker reflects
     * the real thing rather than a guess.
     */
    private function currentAiNewsBulletinLength(Station $station): float
    {
        $path = $station->getRadioTempDir() . '/' . AiNewsGenerator::OUTPUT_FILENAME;
        if (!is_file($path)) {
            return 120.0;
        }

        $probe = @shell_exec(
            'ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null'
        );
        $duration = (float)trim((string)$probe);

        return $duration > 0.0 ? $duration : 120.0;
    }

    /**
     * The aired length of the most recent Top-of-Hour ID, so the marker's
     * duration matches reality. Falls back to a typical ID length when none has
     * aired yet.
     */
    private function recentTopOfHourIdLength(Station $station): float
    {
        $duration = (float)($this->em->getConnection()->fetchOne(
            'SELECT duration FROM station_queue
            WHERE station_id = ? AND top_of_hour_legal_id = 1 AND duration > 0
            ORDER BY id DESC LIMIT 1',
            [$station->id]
        ) ?: 0.0);

        if ($duration <= 0.0) {
            $duration = (float)($this->em->getConnection()->fetchOne(
                'SELECT duration FROM song_history
                WHERE station_id = ? AND duration > 0 AND duration <= 60 AND media_id IS NULL
                AND (title LIKE ? OR text LIKE ?)
                ORDER BY id DESC LIMIT 1',
                [$station->id, '%Station ID%', '%Station ID%']
            ) ?: 0.0);
        }

        return $duration > 0.0 ? $duration : 38.0;
    }

    private function scheduleKey(\App\Entity\StationSchedule $schedule): int
    {
        return isset($schedule->id) ? $schedule->id : spl_object_id($schedule);
    }

    /**
     * @param list<array{playlist: \App\Entity\StationPlaylist, schedule: \App\Entity\StationSchedule, start: \Carbon\CarbonImmutable, end: \Carbon\CarbonImmutable}> $windows
     */
    private function rangeOverlapsRigidWindow(int $start, int $end, array $windows): bool
    {
        foreach ($windows as $window) {
            if ($start < $window['end']->getTimestamp() && $window['start']->getTimestamp() < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * AI DJ speech is deliberately not generated by the preview. These rows only
     * describe scheduled work shifts so the log still shows who is expected to be
     * on-air while all speech timing and content remain live.
     *
     * @return list<array<string, mixed>>
     */
    private function buildAiDjShifts(Station $station, int $startTs, int $endTs): array
    {
        $timezone = $station->getTimezoneObject();
        $firstDay = (new DateTimeImmutable('@' . $startTs))
            ->setTimezone($timezone)
            ->setTime(0, 0)
            ->modify('-1 day');
        $lastDay = (new DateTimeImmutable('@' . $endTs))
            ->setTimezone($timezone)
            ->setTime(0, 0);

        $shifts = [];
        foreach ($this->aiDjScheduleRepo->findByStation($station->id) as $schedule) {
            $dj = $schedule->getAiDj();
            if (!$schedule->isEnabled() || !$dj->isEnabled()) {
                continue;
            }

            for ($day = $firstDay; $day <= $lastDay; $day = $day->modify('+1 day')) {
                if (!in_array((int)$day->format('N'), $schedule->getLoopDays(), true)) {
                    continue;
                }

                $startParts = array_map('intval', explode(':', $schedule->getStartTime()->format('H:i:s')));
                $endParts = array_map('intval', explode(':', $schedule->getEndTime()->format('H:i:s')));

                $shiftStart = $day->setTime($startParts[0], $startParts[1], $startParts[2]);
                $shiftEnd = $day->setTime($endParts[0], $endParts[1], $endParts[2]);
                if ($shiftEnd <= $shiftStart) {
                    $shiftEnd = $shiftEnd->modify('+1 day');
                }

                $shiftStartTs = $shiftStart->getTimestamp();
                $shiftEndTs = $shiftEnd->getTimestamp();
                if ($shiftEndTs <= $startTs || $shiftStartTs >= $endTs) {
                    continue;
                }

                $shifts[] = [
                    'schedule_id' => $schedule->getId(),
                    'schedule_name' => $schedule->getName(),
                    'dj_id' => $dj->getId(),
                    'dj_name' => $dj->getName(),
                    'starts_at' => $shiftStartTs,
                    'ends_at' => $shiftEndTs,
                ];
            }
        }

        usort($shifts, static fn(array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);

        return $shifts;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function persistProgrammeLogEntries(Station $station, array $entries): array
    {
        // Remove stale programme log entries from previous builds. A programme
        // whose window has already opened is on air, not a stale plan: its line
        // stays "planned" until the stream's first title reaches song history,
        // and a build in that time deleted it. The 07:00 Morning Show line went
        // that way (first title 07:19), so the show was logged as a "Live: AI
        // DJ" line at 09:00:36 instead (Mon 2026-10-05).
        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
                AND e.payload LIKE :marker
                AND e.status = :planned
                AND e.is_locked = false
                AND e.planned_at > :now
            DQL
        )->setParameter('station', $station)
            ->setParameter('marker', '%scheduled_programme%')
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->setParameter('now', time())
            ->execute();

        $maxSequence = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT MAX(e.sequence) FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
            DQL
        )->setParameter('station', $station)
            ->getSingleScalarResult();

        // Lines the delete above spared on purpose (already aired, queued or
        // locked by an operator) must be re-used, not duplicated.
        /** @var StationLogEntry[] $survivors */
        $survivors = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
                AND e.payload LIKE :marker
            DQL
        )->setParameter('station', $station)
            ->setParameter('marker', '%scheduled_programme%')
            ->getResult();

        $survivorByKey = [];
        foreach ($survivors as $survivor) {
            $survivorByKey[$survivor->planned_at . '|' . ($survivor->playlist->id ?? '') . '|' . $survivor->title]
                = $survivor;
        }

        /** @var array<int, StationLogEntry> $pending */
        $pending = [];
        foreach ($entries as $idx => $entry) {
            // Only this build's programme blocks; as-run lines read back from
            // the log already have their line and stay as they are.
            if ('scheduled_programme' !== ($entry['source_type'] ?? '') || !empty($entry['log_entry_id'])) {
                continue;
            }

            $plannedAt = (int)($entry['played_at'] ?? 0);
            $survivorKey = $plannedAt . '|' . ($entry['playlist_id'] ?? '') . '|' . ($entry['title'] ?? '');
            if (isset($survivorByKey[$survivorKey])) {
                $survivor = $survivorByKey[$survivorKey];

                // A programme block a past bug dropped (e.g. a remote stream
                // mistaken for a missing file) must come back as planned: the
                // window is still scheduled, so the log has to honor it.
                if (StationLogEntry::STATUS_DROPPED === $survivor->status && !$survivor->is_locked) {
                    $survivor->status = StationLogEntry::STATUS_PLANNED;
                    $survivor->note = null;
                    $this->em->persist($survivor);
                }

                $pending[$idx] = $survivor;
                continue;
            }

            $playlistId = $entry['playlist_id'] ?? null;

            $logEntry = new StationLogEntry($station, $plannedAt, ++$maxSequence);
            $logEntry->title = $entry['title'] ?? null;
            $logEntry->text = $entry['text'] ?? null;
            $logEntry->duration = (float)($entry['duration'] ?? 0);
            if (null !== $playlistId) {
                $logEntry->playlist = $this->em->getReference(StationPlaylist::class, (int)$playlistId);
            }
            $logEntry->payload = ['source_type' => 'scheduled_programme'];
            $this->em->persist($logEntry);
            $pending[$idx] = $logEntry;
        }

        if ([] !== $pending) {
            $this->em->flush();

            // A re-used line that was dropped is already in $entries as an
            // as-run line; left there it showed the same programme twice.
            $reusedKeys = [];
            foreach ($pending as $logEntry) {
                $reusedKeys['log-' . $logEntry->id] = true;
            }
            foreach ($entries as $idx => $entry) {
                if (!isset($pending[$idx]) && isset($reusedKeys[(string)($entry['id'] ?? '')])) {
                    unset($entries[$idx]);
                }
            }

            foreach ($pending as $idx => $logEntry) {
                $entries[$idx]['log_entry_id'] = $logEntry->id;
                $entries[$idx]['id'] = 'log-' . $logEntry->id;
                $entries[$idx]['log_status'] = $logEntry->status;
                $entries[$idx]['log_note'] = $logEntry->note;
                $entries[$idx]['aired_at'] = $logEntry->aired_at;
                $entries[$idx]['is_locked'] = $logEntry->is_locked;
            }
        }

        return array_values($entries);
    }

    /** @return array<string, mixed> */
    private function mapQueueRow(StationQueue $row, int $sequence, bool $isLiveQueue): array
    {
        $mediaType = match (true) {
            $row->top_of_hour_legal_id => 'id',
            null !== $row->autodj_custom_uri => 'stream',
            null !== $row->media => $row->media->type,
            default => 'music',
        };

        $sourceType = match (true) {
            null !== $row->request => 'request',
            null !== $row->clock_wheel => 'clock_wheel',
            null !== $row->autodj_custom_uri => 'stream',
            null !== $row->playlist => 'playlist',
            default => 'autodj',
        };

        return [
            'id' => 'projection-' . $sequence,
            'queue_id' => $row->id,
            'log_entry_id' => $row->log_entry_id,
            'song_id' => $row->song_id,
            'played_at' => $row->timestamp_played?->getTimestamp(),
            'cued_at' => $row->timestamp_cued->getTimestamp(),
            'duration' => max(5.0, $row->duration ?? 0.0),
            'title' => $row->title,
            'artist' => $row->artist,
            'album' => $row->album,
            'text' => $row->text,
            'playlist' => $row->playlist?->name,
            'playlist_id' => $row->playlist?->id,
            'playlist_chain' => $row->playlist_chain,
            'clock_wheel' => $row->clock_wheel?->name,
            'clock_wheel_id' => $row->clock_wheel?->id,
            'media_type' => $mediaType,
            'source_type' => $sourceType,
            'is_request' => null !== $row->request,
            'is_live_queue' => $isLiveQueue,
            'sent_to_autodj' => $row->sent_to_autodj,
            'top_of_hour_legal_id' => $row->top_of_hour_legal_id,
            'autodj_custom_uri' => $row->autodj_custom_uri,
            'clock_wheel_schedule_mode' => $row->clock_wheel_schedule_mode,
            'clock_wheel_enforce_cap' => $row->clock_wheel_enforce_cap,
            'clock_wheel_stretch_ratio' => $row->clock_wheel_stretch_ratio,
            'clock_wheel_legal_id_substitute' => $row->clock_wheel_legal_id_substitute,
            'hour_boundary_enforce_cap' => $row->hour_boundary_enforce_cap,
            'hour_boundary_max_play_seconds' => $row->hour_boundary_max_play_seconds,
            // Kept in the snapshot schema for backward-compatible frontend data.
            // The StationQueue entity no longer has this legacy property.
            'top_of_hour_pre_id_fade' => false,
        ];
    }
}
