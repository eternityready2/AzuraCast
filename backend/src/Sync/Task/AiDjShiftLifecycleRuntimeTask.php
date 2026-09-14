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
     * Production-main history lands at roughly a five-minute base interval scaled
     * by talk frequency: 50% ~= 10 minutes (Bella), 75% ~= 6m40s (Onyx).
     */
    private const int TALK_BASE_INTERVAL_SECONDS = 300;

    /**
     * Production main normally lands around 3-5 heard DJ breaks per hour. Keep the
     * deterministic wall-clock scheduler from exceeding that natural ceiling when
     * many short tracks produce unusually frequent safe boundaries. Mandatory
     * lifecycle sign-offs are intentionally not blocked by this normal-talk cap.
     */
    private const int MAX_TALK_BREAKS_PER_HOUR = 5;

    private const int STATE_TTL_SECONDS = 12 * 3600;

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
        } elseif ($previousShiftIdentity !== $shiftIdentity) {
            $this->purgePendingAiDjSpeech($station, $backend, 'AI DJ shift changed');
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

    private function purgePendingAiDjSpeech(
        Station $station,
        Liquidsoap $backend,
        string $reason,
    ): void {
        try {
            $queueResult = $backend->command(
                $station,
                LiquidsoapQueues::AiDj->value . '.queue',
            );
            $responseText = trim(implode(' ', $queueResult));
            $responseLower = strtolower($responseText);

            // Old generated station configurations do not have the dedicated lane.
            // Never flush legacy Requests here because that queue can contain real
            // listener requests unrelated to AI DJ speech.
            if (
                '' === $responseText
                || str_contains($responseLower, 'unknown command')
                || str_contains($responseLower, 'no such command')
                || str_contains($responseLower, 'invalid command')
            ) {
                return;
            }

            $requestIds = preg_split('/\s+/', $responseText) ?: [];
            $removed = 0;
            foreach ($requestIds as $requestId) {
                if (!ctype_digit($requestId)) {
                    continue;
                }

                $backend->command(
                    $station,
                    sprintf('%s.remove %d', LiquidsoapQueues::AiDj->value, (int)$requestId),
                );
                $removed++;
            }

            if ($removed > 0) {
                $this->logger->info('AI DJ: Purged stale dedicated speech requests.', [
                    'station_id' => $station->id,
                    'removed' => $removed,
                    'reason' => $reason,
                ]);
            }
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
