<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\AiDj;
use App\Entity\AiDjSchedule;
use App\Entity\SongHistory;
use App\Entity\Station;
use App\Event\Radio\BuildQueue;
use App\Radio\Adapters;
use App\Radio\AutoDJ\AiDjQueueListener;
use App\Radio\AutoDJ\AiDjShiftLifecycleListener;
use App\Radio\Backend\Liquidsoap;
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
        if ($backend instanceof Liquidsoap && !$backend->isRunning($station)) {
            $this->logger->debug('AI DJ: Runtime scheduling skipped because Liquidsoap is not running.', [
                'station_id' => $station->id,
            ]);
            return;
        }

        // Human live presenters own their broadcast. Do not render or queue new
        // automated speech while a streamer is connected; the generated Liquidsoap
        // lane has the same guard as a second line of defense at playout time.
        if (null !== $station->current_streamer) {
            $this->logger->debug('AI DJ: Runtime scheduling skipped while a live streamer is active.', [
                'station_id' => $station->id,
            ]);
            return;
        }

        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $event = new BuildQueue($station, $now, $now);

        // Lifecycle owns the deterministic shift sign-off and welcome guard. It runs
        // here, not in BuildQueue, so TTS latency can never hold up music selection.
        $this->lifecycleListener->onBuildQueue($event);

        $schedule = $this->scheduler->findActiveSchedule($station->id, $now);
        if (!$schedule instanceof AiDjSchedule) {
            return;
        }

        $dj = $schedule->getAiDj();

        $shift = $this->scheduler->getShiftWindow($station, $schedule, $now);
        $startsAt = $shift['starts_at'];
        $endsAt = $shift['ends_at'];

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

        $beforeId = $lastBreak?->id;
        $this->queueListener->onBuildQueue($event);
        $afterBreak = $this->findLatestDjBreak($station, $dj, $startsAt, $endsAt);

        if ($afterBreak?->id === $beforeId) {
            // Nothing new has actually reached air yet. This can be a protected
            // TOH/news window, a pending AI DJ request, or a TTS/API failure. Keep
            // the cadence opportunity live; the queue guards prevent stacking while
            // a successfully rendered clip waits for its track boundary.
            $this->cache->set($cadenceKey, 1.0, self::STATE_TTL_SECONDS);
            $this->cache->delete('ai_dj_talk_cooldown_' . $station->id);
        }
    }

    private function getTalkIntervalSeconds(float $frequency): int
    {
        $frequency = max(0.01, min(1.0, $frequency));

        return (int)ceil(self::TALK_BASE_INTERVAL_SECONDS / $frequency);
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
