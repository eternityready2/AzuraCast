<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\AiDj;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Service\AiDjScheduler;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Prevents configured AI DJs from developing abnormally long silent stretches.
 *
 * This subscriber only advances the existing cadence credit. AiDjQueueListener
 * remains authoritative for every playback safety check and for clip generation.
 */
final class AiDjCadenceWatchdogSubscriber implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    private const int STATE_TTL_SECONDS = 12 * 3600;

    /**
     * Recent production history shows the natural station sound lands around one
     * host break every 10-15 minutes. Scale from five minutes of base credit so a
     * normal 50% talk-frequency profile becomes eligible after about 10 minutes.
     */
    private const int MAX_SILENCE_BASE_SECONDS = 300;

    /** Never force a normal-frequency host more often than roughly every 10 minutes. */
    private const int NORMAL_MIN_SILENCE_SECONDS = 600;

    /**
     * Normal host profiles (25%+) should not silently drift beyond 15 minutes just
     * because earlier BuildQueue attempts were blocked by harmless transient state.
     */
    private const int NORMAL_MAX_SILENCE_SECONDS = 900;

    private const float NORMAL_FREQUENCY_FLOOR = 0.25;

    public function __construct(
        private readonly AiDjScheduler $scheduler,
        private readonly ReloadableEntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Run before AiDjQueueListener (priority 1). This only adjusts cache state;
        // it never queues audio or competes with top-of-hour scheduling.
        return [
            BuildQueue::class => ['onBuildQueue', 2],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        $station = $event->getStation();
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $dj = $this->scheduler->findActiveDj($station->id, $now);

        if (!$dj instanceof AiDj) {
            return;
        }

        $frequency = $dj->getTalkFrequency();
        if ($frequency <= 0.0) {
            return;
        }

        $lastBreakAt = $this->getLastBreakTimestamp($station->id, $dj->getName());
        $startedKey = 'ai_dj_cadence_watch_started_' . $station->id . '_' . $dj->getId();

        if ($lastBreakAt === null || (time() - $lastBreakAt) > self::STATE_TTL_SECONDS) {
            $startedAt = (int)($this->cache->get($startedKey) ?? 0);
            if ($startedAt === 0) {
                $this->cache->set($startedKey, time(), self::STATE_TTL_SECONDS);
                return;
            }
            $lastBreakAt = $startedAt;
        } else {
            $this->cache->set($startedKey, $lastBreakAt, self::STATE_TTL_SECONDS);
        }

        $scaledSilence = (int)ceil(self::MAX_SILENCE_BASE_SECONDS / $frequency);

        // Preserve intentionally sparse personalities below 25%. For normal DJ
        // profiles, bound the silence window at 10-15 minutes. The queue listener
        // still gets the final say and can defer the break for Top-of-Hour, news,
        // live DJ, request-queue, cooldown, or other protected conditions.
        if ($frequency >= self::NORMAL_FREQUENCY_FLOOR) {
            $maxSilence = min(
                self::NORMAL_MAX_SILENCE_SECONDS,
                max(self::NORMAL_MIN_SILENCE_SECONDS, $scaledSilence),
            );
        } else {
            $maxSilence = max(self::NORMAL_MAX_SILENCE_SECONDS, $scaledSilence);
        }

        $silenceSeconds = time() - $lastBreakAt;

        if ($silenceSeconds < $maxSilence) {
            return;
        }

        $cadenceKey = 'ai_dj_talk_cadence_' . $station->id . '_' . $dj->getId();
        $currentCredit = (float)($this->cache->get($cadenceKey) ?? 0.0);

        if ($currentCredit < 1.0) {
            $this->cache->set($cadenceKey, 1.0, self::STATE_TTL_SECONDS);
            $this->logger->info('AI DJ: Cadence watchdog advanced an overdue talk break.', [
                'dj_name' => $dj->getName(),
                'silence_seconds' => $silenceSeconds,
                'max_silence_seconds' => $maxSilence,
                'frequency' => $frequency,
            ]);
        }
    }

    private function getLastBreakTimestamp(int $stationId, string $djName): ?int
    {
        try {
            $normalizedDjName = strtolower(trim($djName));

            $lastBreak = $this->em->createQuery(
                <<<'DQL'
                    SELECT sq FROM App\Entity\StationQueue sq
                    WHERE sq.station_id = :station_id
                    AND sq.media IS NULL
                    AND (
                        LOWER(sq.artist) = :dj_name
                        OR LOWER(sq.artist) LIKE :dj_suffix
                    )
                    AND sq.autodj_custom_uri IS NOT NULL
                    AND sq.timestamp_played IS NOT NULL
                    ORDER BY sq.timestamp_played DESC
                DQL
            )->setParameter('station_id', $stationId)
                ->setParameter('dj_name', $normalizedDjName)
                ->setParameter('dj_suffix', '% - ' . $normalizedDjName)
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if (!$lastBreak instanceof StationQueue || $lastBreak->timestamp_played === null) {
                return null;
            }

            return $lastBreak->timestamp_played->getTimestamp();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Cadence watchdog lookup failed: %s', $e->getMessage()));
            return null;
        }
    }
}
