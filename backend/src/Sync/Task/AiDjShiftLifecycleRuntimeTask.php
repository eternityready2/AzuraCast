<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\AiDj;
use App\Entity\AiDjSchedule;
use App\Entity\SongHistory;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\Adapters;
use App\Radio\AutoDJ\AiDjQueueListener;
use App\Radio\AutoDJ\AiDjShiftLifecycleListener;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Service\AiDjScheduler;
use DateTimeImmutable;
use DateTimeZone;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Runs AI DJ speech from the wall clock instead of the AutoDJ music queue builder.
 *
 * Speech generation can involve local TTS and remote artist-history lookups. Running
 * that work from BuildQueue can hold music selection open long enough for Liquidsoap
 * to exhaust its next-song queue. This task therefore owns welcomes, sign-offs and
 * normal talk attempts independently of queue depth and Clock Wheel selection.
 */
final class AiDjShiftLifecycleRuntimeTask extends AbstractTask
{
    // Keep runtime recovery identical to the lifecycle listener's welcome window.
    // After 30 minutes a "welcome" is no longer a sensible mid-shift recovery.
    private const int WELCOME_RECOVERY_SECONDS = 1800;

    /**
     * Talk interval formula: TALK_BASE_INTERVAL_SECONDS / talk_frequency
     *
     * PRODUCTION-CONFIRMED TARGET (main branch, 13-day log, Sept 6-18):
     * Production does not use an interval formula at all — it uses a flat
     * 300s (5 min) cooldown plus a per-song-boundary probabilistic roll
     * gated by talk_frequency (skip when random() > frequency). Measured
     * results from that log, deduplicated by unique on-air timestamp:
     *
     *   Onyx  (100% / 1.0) -> averaged 5.67 breaks/hr (range 5.0-6.3/hr)
     *   Bella ( 75% / 0.75) -> averaged 5.00 breaks/hr (range 4.2-5.9/hr)
     *
     * This wall-clock task exists for a case production does not have to
     * solve: keeping talk alive through Strict scheduled playlists (Hymns &
     * Favorites), where no real BuildQueue song-boundary event fires at all.
     * 270s (4.5 min) was an untested guess that targeted far more talk than
     * production ever actually produces (up to 12/hr vs. production's
     * observed max of 7.67/hr). Recalibrated to reproduce production's
     * measured average: interval = 600s gives Onyx 600/1.0 = 600s (10 min,
     * -> 6.0/hr, matching the 5.67/hr average) and Bella 600/0.75 = 800s
     * (13.3 min -> 4.5/hr, matching the 5.0/hr average within normal
     * night-to-night variance seen in production).
     */
    private const int TALK_BASE_INTERVAL_SECONDS = 600;

    /**
     * Production's own observed maximum across 13 days was 7.67 breaks/hr
     * (Onyx, Sept 11) and 5.86 breaks/hr (Bella, Sept 14). This ceiling only
     * matters as a runaway backstop for the wall-clock catch-up path during
     * Strict playlists; normal cadence during ordinary BuildQueue-driven
     * hours is governed by AiDjQueueListener's own cadence credit, which
     * matches production's mechanism now that it is registered as a real
     * event subscriber again (see events.php). Mandatory lifecycle
     * sign-offs (welcome/sign-off) are not counted here.
     */
    private const int MAX_TALK_BREAKS_PER_HOUR = 8;

    private const int STATE_TTL_SECONDS = 12 * 3600;

    /**
     * Longest a single AI DJ clip should ever sit "pending" in Liquidsoap before
     * it is treated as wedged rather than normally waiting for a track boundary.
     * Even a long song (5-6 min) plus TTS render time is comfortably under this;
     * a fallback gate that never opens (or a request that never resolves) would
     * otherwise leave a station silent, and the Upcoming Queue page pinned to
     * the same stale row, until the next scheduled shift boundary purges it.
     */
    private const int STUCK_SPEECH_SECONDS = 900;

    public function __construct(
        private readonly AiDjShiftLifecycleListener $lifecycleListener,
        private readonly AiDjQueueListener $queueListener,
        private readonly AiDjScheduler $scheduler,
        private readonly CacheInterface $cache,
        private readonly Adapters $adapters,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public function run(bool $force = false): void
    {
        // These listeners persist their own queue/history rows and may flush while
        // rendering speech. Avoid iterateStations()' explicit transaction wrapper.
        /** @var Station[] $stations */
        $stations = $this->em->createQuery(
            <<<'DQL'
                SELECT s FROM App\Entity\Station s
            DQL
        )->getResult();

        foreach ($stations as $station) {
            try {
                $this->runForStation($station);
            } catch (Throwable $e) {
                // AI speech must always fail open. A broken TTS/API/backend must not
                // stop the sync runner or prevent other stations from being checked.
                $this->logger->error('AI DJ: Runtime scheduling pass failed.', [
                    'station_id' => $station->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function runForStation(Station $station): void
    {
        $backend = $this->adapters->getBackendAdapter($station);
        if (!$backend instanceof Liquidsoap) {
            return;
        }

        if (!$backend->isRunning($station)) {
            $this->logger->debug('AI DJ: Runtime scheduling skipped because Liquidsoap is not running.', [
                'station_id' => $station->id,
            ]);
            return;
        }

        // Human live presenters own their broadcast. A request that was queued a few
        // seconds before live takeover must not sit parked in source.available() and
        // suddenly speak when the presenter disconnects.
        if (null !== $station->current_streamer) {
            $this->purgePendingAiDjSpeech($station, $backend, 'live streamer takeover');
            $this->logger->debug('AI DJ: Runtime scheduling skipped while a live streamer is active.', [
                'station_id' => $station->id,
            ]);
            return;
        }

        // Self-healing safety net: a clip normally reaches air within seconds to a
        // couple minutes (it waits, at most, for the current track to finish). If
        // Liquidsoap is STILL reporting a clip pending well beyond that, something
        // is genuinely wedged -- a fallback gate that never opens, a request that
        // never resolves, or stale runtime state left over from before a deploy.
        // Leaving it alone means total silence until the next shift boundary
        // purges it. Force-clear it here so the very next heartbeat sees an empty
        // lane and generates a fresh attempt instead.
        $this->clearStuckAiDjSpeech($station, $backend);

        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $schedule = $this->scheduler->findActiveSchedule($station->id, $now);
        $runtimeShiftKey = 'ai_dj_runtime_shift_' . $station->id;

        if (!$schedule instanceof AiDjSchedule) {
            // Speech has no owner once the scheduled shift ends. The dedicated AI DJ
            // queue can safely be cleared without touching listener Requests.
            $this->purgePendingAiDjSpeech($station, $backend, 'no active AI DJ shift');
            $this->cache->delete($runtimeShiftKey);
            return;
        }

        $dj = $schedule->getAiDj();
        $shift = $this->scheduler->getShiftWindow($station, $schedule, $now);
        $startsAt = $shift['starts_at'];
        $endsAt = $shift['ends_at'];

        // A queued request belongs to the concrete schedule window that created it.
        // If ownership state is absent (for example after Redis/runtime state loss),
        // do not trust any request still parked in Liquidsoap: it may belong to the
        // previous shift. Purging a legitimate current-shift pending clip is safe
        // because SongHistory/cadence recovery can regenerate it; airing stale speech
        // after state loss is not safe.
        $shiftIdentity = $schedule->getId() . ':' . $startsAt->getTimestamp();
        $previousShiftIdentity = $this->cache->get($runtimeShiftKey);
        if (null === $previousShiftIdentity) {
            $this->purgePendingAiDjSpeech($station, $backend, 'AI DJ shift ownership state initialized');
            // Also clear the welcome marker so the first heartbeat on a fresh
            // Redis/runtime state always tries to welcome, even if a stale key
            // from a previous deployment survived in cache.
            $this->cache->delete('ai_dj_welcomed_' . $station->id . '_' . $dj->getId());
        } elseif ($previousShiftIdentity !== $shiftIdentity) {
            $this->purgePendingAiDjSpeech($station, $backend, 'AI DJ shift changed');
            // A different shift started: the old welcomed marker belongs to the
            // previous occurrence. Delete it so the new shift gets its own welcome,
            // even if the same DJ recurs (e.g. Onyx every night 12am-6am).
            $this->cache->delete('ai_dj_welcomed_' . $station->id . '_' . $dj->getId());
            $this->cache->delete('ai_dj_last_active_' . $station->id);
            $this->cache->delete('ai_dj_talk_cooldown_' . $station->id);
        }
        $this->cache->set($runtimeShiftKey, $shiftIdentity, self::STATE_TTL_SECONDS);

        $event = new BuildQueue($station, $now, $now);

        // Lifecycle owns the deterministic shift sign-off and welcome guard. It runs
        // here, not in BuildQueue, so TTS latency can never hold up music selection.
        $this->lifecycleListener->onBuildQueue($event);

        $welcomeRecoveryEndsAt = min(
            $endsAt->getTimestamp(),
            $startsAt->getTimestamp() + self::WELCOME_RECOVERY_SECONDS,
        );
        $welcomeRecoveryOpen = $now >= $startsAt
            && $now->getTimestamp() < $welcomeRecoveryEndsAt;

        if (
            $welcomeRecoveryOpen
            && !$this->hasDurableWelcome($station, $dj, $startsAt, $endsAt)
        ) {
            // A welcome becomes durable only after Liquidsoap reports it on air and
            // SongHistory records the metadata. If rendering/queueing was lost before
            // playout, clear volatile guards so a later heartbeat can recover it.
            //
            // IMPORTANT: During a strict scheduled playlist (e.g. Hymns & Favorites),
            // songs are played by a native Liquidsoap source that does NOT write
            // media-linked SongHistory rows. hasDurableWelcome() therefore stays false
            // until the welcome clip itself airs — which can only happen at the next
            // track boundary (end of the current hymn). If a welcome clip is already
            // sitting in the dedicated AI DJ speech lane waiting for that boundary,
            // thrashing the cache and retrying every minute is pointless: the queue
            // listener immediately sees the lane as non-empty and skips, so we never
            // actually queue a second clip — we just silently churn. Detect that case
            // and wait instead of clearing volatile guards unnecessarily.
            if (!$backend->isQueueEmpty($station, LiquidsoapQueues::AiDj)) {
                // A welcome clip is already queued and waiting for the current track
                // to finish. Leave all cache state intact so it fires cleanly when
                // the hymn ends, then bail — normal talk is deferred to the next
                // heartbeat that finds the lane empty and the welcome durable.
                $this->logger->debug('AI DJ: Welcome pending in speech lane; waiting for track boundary.', [
                    'station_id' => $station->id,
                    'dj' => $dj->getName(),
                ]);
                return;
            }

            $this->cache->delete('ai_dj_welcomed_' . $station->id . '_' . $dj->getId());
            $this->cache->delete('ai_dj_last_active_' . $station->id);
            $this->cache->delete('ai_dj_talk_cooldown_' . $station->id);

            $this->queueListener->onBuildQueue($event);

            // Never stack ordinary chatter onto a welcome attempt in the same pass.
            return;
        }

        $frequency = $dj->getTalkFrequency();
        if ($frequency <= 0.0) {
            return;
        }

        if ($this->countRecentDjBreaks($station, $dj, $now) >= self::MAX_TALK_BREAKS_PER_HOUR) {
            $this->logger->debug('AI DJ: Hourly on-air talk ceiling reached.', [
                'station_id' => $station->id,
                'dj' => $dj->getName(),
                'limit' => self::MAX_TALK_BREAKS_PER_HOUR,
            ]);
            return;
        }

        $lastBreak = $this->findLatestDjBreak($station, $dj, $startsAt, $endsAt);
        $lastBreakAt = $lastBreak?->timestamp_start->getTimestamp()
            ?? $startsAt->getTimestamp();
        $targetInterval = $this->getTalkIntervalSeconds($frequency);

        if (($now->getTimestamp() - $lastBreakAt) < $targetInterval) {
            return;
        }

        // AiDjQueueListener still owns every playback safety guard and content path.
        // Give it one unit of its existing cadence credit so this wall-clock decision
        // is authoritative instead of depending on how often BuildQueue happened to
        // run while a queue was being projected.
        $cadenceKey = 'ai_dj_talk_cadence_' . $station->id . '_' . $dj->getId();
        $this->cache->set($cadenceKey, 1.0, self::STATE_TTL_SECONDS);

        // Keep two facts separate:
        // 1. StationQueue tells us whether this heartbeat successfully submitted a
        //    fresh direct request to Liquidsoap.
        // 2. SongHistory tells us whether speech actually reached air.
        // A queued clip normally cannot be in SongHistory before this synchronous
        // method returns, so using only airtime history here would falsely classify
        // every successful enqueue as a generation failure and clear its cooldown.
        $beforeSubmission = $this->findLatestSubmittedDjClip($station, $dj, $startsAt, $endsAt);
        $beforeAirId = $lastBreak?->id;

        $this->queueListener->onBuildQueue($event);

        $afterSubmission = $this->findLatestSubmittedDjClip($station, $dj, $startsAt, $endsAt);
        $afterBreak = $this->findLatestDjBreak($station, $dj, $startsAt, $endsAt);
        $submittedNewClip = $afterSubmission?->id !== $beforeSubmission?->id;
        $airedNewClip = $afterBreak?->id !== $beforeAirId;

        if (!$submittedNewClip && !$airedNewClip) {
            // No new request was submitted and nothing new reached air. This is a
            // genuine skipped/failed opportunity (protected TOH/news window, busy
            // request queue, unavailable content or TTS/API failure), so preserve
            // the opportunity and remove the cooldown for the next safe heartbeat.
            $this->cache->set($cadenceKey, 1.0, self::STATE_TTL_SECONDS);
            $this->cache->delete('ai_dj_talk_cooldown_' . $station->id);
        }
    }

    private function getTalkIntervalSeconds(float $frequency): int
    {
        $frequency = max(0.01, min(1.0, $frequency));

        return (int)ceil(self::TALK_BASE_INTERVAL_SECONDS / $frequency);
    }

    private function countRecentDjBreaks(
        Station $station,
        AiDj $dj,
        DateTimeImmutable $now,
    ): int {
        try {
            $utc = new DateTimeZone('UTC');
            $normalizedDjName = strtolower(trim($dj->getName()));
            $windowEnd = $now->setTimezone($utc);
            $windowStart = $windowEnd->modify('-1 hour');

            return (int)$this->em->createQuery(
                <<<'DQL'
                    SELECT COUNT(sh.id) FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.media IS NULL
                    AND (
                        LOWER(sh.artist) = :dj_name
                        OR LOWER(sh.artist) LIKE :dj_suffix
                    )
                    AND sh.timestamp_start > :windowStart
                    AND sh.timestamp_start <= :windowEnd
                DQL
            )->setParameter('station', $station)
                ->setParameter('dj_name', $normalizedDjName)
                ->setParameter('dj_suffix', '% - ' . $normalizedDjName)
                ->setParameter('windowStart', $windowStart)
                ->setParameter('windowEnd', $windowEnd)
                ->getSingleScalarResult();
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Hourly cadence history lookup failed.', [
                'station_id' => $station->id,
                'dj' => $dj->getName(),
                'exception' => $e->getMessage(),
            ]);

            // Fail closed so a database/history outage cannot create chatter bursts.
            return self::MAX_TALK_BREAKS_PER_HOUR;
        }
    }

    /**
     * Detect a clip that Liquidsoap still reports as pending in the dedicated AI
     * DJ lane long after it should have aired, and force-clear it. A clip only
     * ever needs to wait for the current track to finish, plus TTS render time --
     * both comfortably under STUCK_SPEECH_SECONDS. If the lane is still occupied
     * past that, either the fallback gate that selects ai_dj_queue is not opening
     * (e.g. a stale Liquidsoap config still running the pre-fix predicate) or the
     * request itself never resolved. Either way, waiting for the next scheduled
     * shift boundary means total silence and a queue page permanently pinned to
     * the same stale row in the meantime. This method costs one cheap Liquidsoap
     * query per minute per station and only acts when something is genuinely
     * wedged, so it is always safe to run.
     */
    private function clearStuckAiDjSpeech(Station $station, Liquidsoap $backend): void
    {
        try {
            if ($backend->isQueueEmpty($station, LiquidsoapQueues::AiDj)) {
                return;
            }
        } catch (Throwable) {
            // Reporting must never block normal playback on an inspection failure.
            return;
        }

        $stuckWhere = <<<'DQL'
            sq.station = :station
            AND sq.is_played = 1
            AND sq.timestamp_played IS NULL
            AND sq.autodj_custom_uri LIKE :aiDjPath
        DQL;

        try {
            /** @var StationQueue|null $newest */
            $newest = $this->em->createQuery(
                'SELECT sq FROM App\Entity\StationQueue sq WHERE ' . $stuckWhere . ' ORDER BY sq.timestamp_cued DESC'
            )->setParameter('station', $station)
                ->setParameter('aiDjPath', '%/ai_dj/%')
                ->setMaxResults(1)
                ->getOneOrNullResult();
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Stuck-speech lookup failed.', [
                'station_id' => $station->id,
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        if (null === $newest) {
            $this->purgePendingAiDjSpeech($station, $backend, 'pending speech has no matching queue row');
            return;
        }

        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $cutoff = $now->modify('-' . self::STUCK_SPEECH_SECONDS . ' seconds');
        if ($newest->timestamp_cued > $cutoff) {
            return;
        }

        $this->purgePendingAiDjSpeech($station, $backend, 'clip stuck pending past expected air time');

        // Close out every stale row at once; one-per-tick left hundreds blocking for hours.
        $cleared = $this->em->createQuery(
            'UPDATE App\Entity\StationQueue sq SET sq.timestamp_played = :now WHERE '
            . $stuckWhere . ' AND sq.timestamp_cued <= :cutoff'
        )->setParameter('station', $station)
            ->setParameter('aiDjPath', '%/ai_dj/%')
            ->setParameter('now', $now)
            ->setParameter('cutoff', $cutoff)
            ->execute();

        $this->logger->warning('AI DJ: Cleared stuck speech rows.', [
            'station_id' => $station->id,
            'count' => $cleared,
        ]);

        $schedule = $this->scheduler->findActiveSchedule($station->id, $now);
        if ($schedule instanceof AiDjSchedule) {
            $dj = $schedule->getAiDj();
            $this->cache->delete('ai_dj_welcomed_' . $station->id . '_' . $dj->getId());
            $this->cache->delete('ai_dj_last_active_' . $station->id);
            $this->cache->delete('ai_dj_talk_cooldown_' . $station->id);
        }
    }

    private function purgePendingAiDjSpeech(
        Station $station,
        Liquidsoap $backend,
        string $reason,
    ): void {
        try {
            // New generated configs expose an atomic clear command that removes the
            // waiting queue and also skips a request that request.queue has already
            // resolved/prefetched internally. The interactive `.queue` listing alone
            // is not sufficient because a ready request can disappear from that list
            // before it actually reaches air.
            $clearResult = $backend->command($station, 'ai_dj_control.clear');
            $clearText = strtolower(implode(' ', $clearResult));
            $clearUnavailable = str_contains($clearText, 'unknown command')
                || str_contains($clearText, 'no such command')
                || str_contains($clearText, 'invalid command');

            if (!$clearUnavailable) {
                $this->logger->info('AI DJ: Purged stale dedicated speech requests.', [
                    'station_id' => $station->id,
                    'reason' => $reason,
                ]);
                return;
            }

            // Compatibility for a running config generated by an earlier #200 head:
            // it has the dedicated queue but not ai_dj_control.clear yet. Skip any
            // current/prefetched request first, then remove IDs still visible in the
            // waiting queue. Never touch legacy listener Requests.
            $skipResult = $backend->command(
                $station,
                LiquidsoapQueues::AiDj->value . '.skip',
            );
            $skipText = strtolower(implode(' ', $skipResult));
            if (
                str_contains($skipText, 'unknown command')
                || str_contains($skipText, 'no such command')
                || str_contains($skipText, 'invalid command')
            ) {
                return;
            }

            $queueResult = $backend->command(
                $station,
                LiquidsoapQueues::AiDj->value . '.queue',
            );
            $responseText = trim(implode(' ', $queueResult));
            $responseLower = strtolower($responseText);

            if (
                '' === $responseText
                || str_contains($responseLower, 'unknown command')
                || str_contains($responseLower, 'no such command')
                || str_contains($responseLower, 'invalid command')
            ) {
                return;
            }

            $requestIds = preg_split('/\s+/', $responseText) ?: [];
            foreach ($requestIds as $requestId) {
                if (!ctype_digit($requestId)) {
                    continue;
                }

                $backend->command(
                    $station,
                    sprintf('%s.remove %d', LiquidsoapQueues::AiDj->value, (int)$requestId),
                );
            }

            $this->logger->info('AI DJ: Purged stale dedicated speech requests.', [
                'station_id' => $station->id,
                'reason' => $reason,
                'compatibility_mode' => true,
            ]);
        } catch (Throwable $e) {
            // Compatibility/deployment races must never interrupt normal radio. The
            // regenerated dedicated queue will be cleaned on the next minute pass.
            $this->logger->debug('AI DJ: Dedicated speech purge unavailable.', [
                'station_id' => $station->id,
                'reason' => $reason,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function findLatestSubmittedDjClip(
        Station $station,
        AiDj $dj,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): ?StationQueue {
        try {
            $utc = new DateTimeZone('UTC');
            $normalizedDjName = strtolower(trim($dj->getName()));

            return $this->em->createQuery(
                <<<'DQL'
                    SELECT q FROM App\Entity\StationQueue q
                    WHERE q.station = :station
                    AND q.media IS NULL
                    AND q.autodj_custom_uri IS NOT NULL
                    AND (
                        LOWER(q.artist) = :dj_name
                        OR LOWER(q.artist) LIKE :dj_suffix
                    )
                    AND q.timestamp_cued >= :startsAt
                    AND q.timestamp_cued < :endsAt
                    ORDER BY q.id DESC
                DQL
            )->setParameter('station', $station)
                ->setParameter('dj_name', $normalizedDjName)
                ->setParameter('dj_suffix', '% - ' . $normalizedDjName)
                ->setParameter('startsAt', $startsAt->setTimezone($utc))
                ->setParameter('endsAt', $endsAt->setTimezone($utc))
                ->setMaxResults(1)
                ->getOneOrNullResult();
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Runtime submission marker lookup failed.', [
                'station_id' => $station->id,
                'dj' => $dj->getName(),
                'exception' => $e->getMessage(),
            ]);

            // Fail closed: an uncertain submission state must not encourage a
            // duplicate talk break on the next heartbeat.
            throw $e;
        }
    }

    private function findLatestDjBreak(
        Station $station,
        AiDj $dj,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): ?SongHistory {
        try {
            $utc = new DateTimeZone('UTC');
            $normalizedDjName = strtolower(trim($dj->getName()));

            // StationQueue is intentionally not used as the cadence clock. AI DJ
            // speech is submitted directly to Liquidsoap and synthetic queue rows
            // may be marked sent before airtime. SongHistory is written only when
            // Liquidsoap reports the speech metadata on air.
            return $this->em->createQuery(
                <<<'DQL'
                    SELECT sh FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.media IS NULL
                    AND (
                        LOWER(sh.artist) = :dj_name
                        OR LOWER(sh.artist) LIKE :dj_suffix
                    )
                    AND sh.timestamp_start >= :startsAt
                    AND sh.timestamp_start < :endsAt
                    ORDER BY sh.timestamp_start DESC, sh.id DESC
                DQL
            )->setParameter('station', $station)
                ->setParameter('dj_name', $normalizedDjName)
                ->setParameter('dj_suffix', '% - ' . $normalizedDjName)
                ->setParameter('startsAt', $startsAt->setTimezone($utc))
                ->setParameter('endsAt', $endsAt->setTimezone($utc))
                ->setMaxResults(1)
                ->getOneOrNullResult();
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Runtime cadence history lookup failed.', [
                'station_id' => $station->id,
                'dj' => $dj->getName(),
                'exception' => $e->getMessage(),
            ]);

            // Fail closed on history uncertainty so a database/query failure can
            // never create a duplicate talk break.
            throw $e;
        }
    }

    private function hasDurableWelcome(
        Station $station,
        AiDj $dj,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): bool {
        try {
            $utc = new DateTimeZone('UTC');
            $startsAtUtc = $startsAt->setTimezone($utc);
            $endsAtUtc = $endsAt->setTimezone($utc);

            // Only SongHistory is an on-air marker for direct AI DJ speech. A
            // StationQueue row merely proves the request was submitted.
            $historyCount = (int)$this->em->createQuery(
                <<<'DQL'
                    SELECT COUNT(sh.id) FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.media IS NULL
                    AND sh.artist = :artist
                    AND sh.title = :title
                    AND sh.timestamp_start >= :startsAt
                    AND sh.timestamp_start < :endsAt
                DQL
            )->setParameter('station', $station)
                ->setParameter('artist', $dj->getName())
                ->setParameter('title', 'AI DJ Welcome')
                ->setParameter('startsAt', $startsAtUtc)
                ->setParameter('endsAt', $endsAtUtc)
                ->getSingleScalarResult();

            return $historyCount > 0;
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Runtime welcome marker lookup failed.', [
                'station_id' => $station->id,
                'dj' => $dj->getName(),
                'exception' => $e->getMessage(),
            ]);

            // Fail closed here: uncertainty must never create duplicate welcomes.
            return true;
        }
    }
}
