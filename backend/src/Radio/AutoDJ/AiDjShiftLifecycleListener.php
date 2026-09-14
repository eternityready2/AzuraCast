<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\AiDj;
use App\Entity\AiDjSchedule;
use App\Entity\Song;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\Adapters;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Service\AiDjGenerator;
use App\Service\AiDjScheduler;
use DateTimeImmutable;
use DateTimeZone;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Keeps AI DJ welcomes, sign-offs and schedule boundaries tied to one concrete
 * scheduled shift instead of volatile process state.
 */
final class AiDjShiftLifecycleListener implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    private const int WELCOME_WINDOW_SECONDS = 1800;

    // Reserve a wider final window so normal songs, extended worship tracks and
    // bounded TTS cannot make a shift lose its required goodbye.
    private const int OUTRO_WINDOW_SECONDS = 900;

    private const int OUTRO_SCAN_SECONDS = 1800;

    private const int OUTRO_SCAN_STEP_SECONDS = 30;

    private const int OUTRO_TAIL_RESERVE_SECONDS = 60;

    // Keep the last 5m30s before the hour clear for TOH ID/news. Unlike the old
    // configurable lookahead guard, this is an actual speech exclusion window,
    // not a planning horizon that can unnecessarily silence the DJ for 10-30 min.
    private const int TOH_SPEECH_CUTOFF_SECONDS = 3270;

    private const int STATE_GRACE_SECONDS = 3600;

    public function __construct(
        private readonly AiDjScheduler $scheduler,
        private readonly AiDjGenerator $generator,
        private readonly Adapters $adapters,
        private readonly ReloadableEntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly LinearLogPreviewContext $linearLogPreviewContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Run before AiDjQueueListener (priority 1). The wall-clock runtime task
        // calls this method directly in production, but keeping the subscriber
        // contract preserves compatibility with direct event-driven callers.
        return [
            BuildQueue::class => ['onBuildQueue', 2],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        if ($this->linearLogPreviewContext->isActive() || $event->isInterrupting()) {
            return;
        }

        if (!empty($event->getNextSongs())) {
            return;
        }

        $station = $event->getStation();
        $backend = $this->adapters->getBackendAdapter($station);
        if (!$backend instanceof Liquidsoap) {
            return;
        }

        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $estimatedAirTime = $this->resolveDirectRequestAirTime($station, $now);

        $scheduleNow = $this->scheduler->findActiveSchedule($station->id, $now);
        if (!$scheduleNow instanceof AiDjSchedule) {
            return;
        }

        $scheduleAtAirTime = $this->scheduler->findActiveSchedule($station->id, $estimatedAirTime);
        $sameShiftOwnsAirTime = $scheduleAtAirTime instanceof AiDjSchedule
            && $scheduleNow->getId() === $scheduleAtAirTime->getId();

        $dj = $scheduleNow->getAiDj();
        $shift = $this->scheduler->getShiftWindow($station, $scheduleNow, $now);
        $startsAt = $shift['starts_at'];
        $endsAt = $shift['ends_at'];

        // Welcome is now owned by lifecycle itself instead of depending on the
        // ordinary talk listener's post-hour/request guards. Only stage an intro
        // when the next track boundary still belongs to this same concrete shift.
        if (
            $sameShiftOwnsAirTime
            && $this->ensureWelcome(
                $station,
                $backend,
                $scheduleNow,
                $dj,
                $startsAt,
                $endsAt,
                $now,
                $estimatedAirTime,
            )
        ) {
            return;
        }

        $outroWindow = $this->resolveOutroWindow($station, $startsAt, $endsAt);
        if (null === $outroWindow || $now < $outroWindow['starts_at']) {
            return;
        }

        $outroKey = $this->getOutroKey($station, $scheduleNow, $startsAt);
        $winddownKey = 'ai_dj_shift_winddown_until_' . $station->id;
        $ttl = $this->getStateTtl($endsAt, $now);
        $alreadySignedOff = $this->cache->get($outroKey)
            || $this->hasDurableShiftMarker($station, $dj, 'AI DJ Sign-off', $startsAt, $endsAt);

        if ($alreadySignedOff) {
            $this->cache->set($outroKey, true, $ttl);
            $this->cache->set($winddownKey, $endsAt->getTimestamp(), $ttl);
            return;
        }

        // Once the wall clock enters the sign-off window, reserve the remaining
        // shift for the goodbye. Do not let a future-schedule projection bypass
        // wind-down before we even evaluate whether the next real boundary is safe.
        $this->cache->set($winddownKey, $endsAt->getTimestamp(), $ttl);

        if (!$sameShiftOwnsAirTime || $estimatedAirTime > $outroWindow['ends_at']) {
            $this->logger->warning('AI DJ: Shift sign-off boundary is not currently safe; retrying next minute.', [
                'dj' => $dj->getName(),
                'shift_end' => $endsAt->format(DATE_ATOM),
                'estimated_air_time' => $estimatedAirTime->format(DATE_ATOM),
            ]);
            return;
        }

        if (!$this->isSafeOutroAirTime($station, $estimatedAirTime)) {
            return;
        }

        // Sign-off has its own speech lane. A listener request must not suppress a
        // required shift goodbye, but a previous AI DJ clip still has to finish.
        if (!$this->isSpeechQueueEmpty($station, $backend)) {
            return;
        }

        $this->cache->set($outroKey, true, $ttl);

        if (
            !$this->pushOutroClip(
                $dj,
                $station,
                $backend,
                $scheduleNow,
                $outroWindow['starts_at'],
                $outroWindow['ends_at'],
            )
        ) {
            // Keep wind-down reserved and retry the goodbye on the next minute.
            $this->cache->delete($outroKey);
        }
    }

    private function ensureWelcome(
        Station $station,
        Liquidsoap $backend,
        AiDjSchedule $schedule,
        AiDj $dj,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        DateTimeImmutable $now,
        DateTimeImmutable $estimatedAirTime,
    ): bool {
        $welcomeKey = 'ai_dj_welcomed_' . $station->id . '_' . $dj->getId();
        $identityKey = 'ai_dj_welcome_shift_' . $station->id . '_' . $dj->getId();
        $shiftIdentity = $schedule->getId() . ':' . $startsAt->getTimestamp();
        $cachedIdentity = $this->cache->get($identityKey);
        $ttl = $this->getStateTtl($endsAt, $now);

        if ($cachedIdentity === $shiftIdentity && null !== $this->cache->get($welcomeKey)) {
            return false;
        }

        $welcomeAlreadyExists = $this->hasDurableShiftMarker(
            $station,
            $dj,
            'AI DJ Welcome',
            $startsAt,
            $endsAt,
        );
        $welcomeWindowEndsAt = $startsAt->modify('+' . self::WELCOME_WINDOW_SECONDS . ' seconds');
        $welcomeWindowOpen = $now >= $startsAt
            && $now < $welcomeWindowEndsAt
            && $estimatedAirTime >= $startsAt
            && $estimatedAirTime < $welcomeWindowEndsAt;

        $this->cache->set($identityKey, $shiftIdentity, $ttl);

        if ($welcomeAlreadyExists) {
            $this->cache->set($welcomeKey, true, $ttl);
            $this->cache->set('ai_dj_last_active_' . $station->id, $dj->getId(), 3600);
            return false;
        }

        if (!$welcomeWindowOpen) {
            // Never produce a nonsensical mid-shift welcome after the recovery
            // window has closed.
            $this->cache->set($welcomeKey, true, $ttl);
            return false;
        }

        if (!$this->isSpeechQueueEmpty($station, $backend)) {
            // Another AI DJ clip is still pending. Leave welcome state unset so the
            // next minute heartbeat retries instead of consuming the opportunity.
            return false;
        }

        if (!$this->pushWelcomeClip($dj, $station, $backend)) {
            return false;
        }

        $this->cache->set($welcomeKey, true, $ttl);
        $this->cache->set('ai_dj_last_active_' . $station->id, $dj->getId(), 3600);
        $this->cache->set('ai_dj_talk_cooldown_' . $station->id, time(), 300);

        return true;
    }

    private function resolveDirectRequestAirTime(
        Station $station,
        DateTimeImmutable $now,
    ): DateTimeImmutable {
        $currentSongEnd = $this->getCurrentSongEndTime($station);

        if ($currentSongEnd instanceof DateTimeImmutable && $currentSongEnd > $now) {
            return $currentSongEnd;
        }

        return $now;
    }

    /**
     * Find the latest sign-off window that stays clear of actual TOH/news airtime.
     *
     * @return array{starts_at: DateTimeImmutable, ends_at: DateTimeImmutable}|null
     */
    private function resolveOutroWindow(
        Station $station,
        DateTimeImmutable $shiftStartsAt,
        DateTimeImmutable $shiftEndsAt,
    ): ?array {
        $latestSafe = null;

        for (
            $secondsBeforeEnd = self::OUTRO_TAIL_RESERVE_SECONDS;
            $secondsBeforeEnd <= self::OUTRO_SCAN_SECONDS;
            $secondsBeforeEnd += self::OUTRO_SCAN_STEP_SECONDS
        ) {
            $candidate = $shiftEndsAt->modify('-' . $secondsBeforeEnd . ' seconds');
            if ($candidate < $shiftStartsAt) {
                break;
            }

            if ($this->isSafeOutroAirTime($station, $candidate)) {
                $latestSafe = $candidate;
                break;
            }
        }

        if (null === $latestSafe) {
            return null;
        }

        $startsAt = $latestSafe->modify('-' . self::OUTRO_WINDOW_SECONDS . ' seconds');
        if ($startsAt < $shiftStartsAt) {
            $startsAt = $shiftStartsAt;
        }

        return [
            'starts_at' => $startsAt,
            'ends_at' => $latestSafe,
        ];
    }

    private function isSafeOutroAirTime(Station $station, DateTimeImmutable $candidate): bool
    {
        $minute = (int)$candidate->format('i');
        $second = (int)$candidate->format('s');
        $secondsIntoHour = ($minute * 60) + $second;

        if ($minute <= 3 || $secondsIntoHour >= self::TOH_SPEECH_CUTOFF_SECONDS) {
            return false;
        }

        return !$this->isNearNewsBulletin($station, $minute);
    }

    private function isNearNewsBulletin(Station $station, int $minute): bool
    {
        $backendConfig = $station->backend_config;

        if (!$backendConfig->ai_news_enabled) {
            return false;
        }

        if ($backendConfig->ai_news_top_of_hour && ($minute >= 57 || $minute <= 3)) {
            return true;
        }

        return $backendConfig->ai_news_bottom_of_hour && $minute >= 27 && $minute <= 33;
    }

    private function isSpeechQueueEmpty(Station $station, Liquidsoap $backend): bool
    {
        try {
            return $backend->isQueueEmpty($station, LiquidsoapQueues::AiDj);
        } catch (Throwable) {
            // Compatibility for a station that has not regenerated its Liquidsoap
            // configuration yet. The enqueue path also falls back to Requests.
            return $backend->isQueueEmpty($station, LiquidsoapQueues::Requests);
        }
    }

    private function getCurrentSongEndTime(Station $station): ?DateTimeImmutable
    {
        try {
            $last = $this->em->createQuery(
                <<<'DQL'
                    SELECT sh FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.is_visible = 1
                    AND sh.media IS NOT NULL
                    ORDER BY sh.timestamp_start DESC
                DQL
            )->setParameter('station', $station)
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if (null === $last || null === $last->duration || $last->duration <= 0.0) {
                return null;
            }

            $endTs = $last->timestamp_start->getTimestamp() + (int)ceil($last->duration);

            return (new DateTimeImmutable('@' . $endTs))
                ->setTimezone($station->getTimezoneObject());
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Shift lifecycle could not resolve current song end.', [
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function hasDurableShiftMarker(
        Station $station,
        AiDj $dj,
        string $title,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): bool {
        try {
            $utc = new DateTimeZone('UTC');
            $startsAtUtc = $startsAt->setTimezone($utc);
            $endsAtUtc = $endsAt->setTimezone($utc);

            // Synthetic AI DJ StationQueue rows are marked sent immediately so the
            // main AutoDJ transport cannot replay them. Only SongHistory proves the
            // clip actually reached air; a queued row must never suppress recovery.
            $historyCount = (int)$this->em->createQuery(
                <<<'DQL'
                    SELECT COUNT(sh.id) FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.artist = :artist
                    AND sh.title = :title
                    AND sh.timestamp_start >= :startsAt
                    AND sh.timestamp_start < :endsAt
                DQL
            )->setParameter('station', $station)
                ->setParameter('artist', $dj->getName())
                ->setParameter('title', $title)
                ->setParameter('startsAt', $startsAtUtc)
                ->setParameter('endsAt', $endsAtUtc)
                ->getSingleScalarResult();

            return $historyCount > 0;
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Shift marker lookup failed.', [
                'title' => $title,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function getOutroKey(
        Station $station,
        AiDjSchedule $schedule,
        DateTimeImmutable $startsAt,
    ): string {
        return sprintf(
            'ai_dj_outro_%d_%d_%d',
            $station->id,
            $schedule->getId(),
            $startsAt->getTimestamp(),
        );
    }

    private function getStateTtl(DateTimeImmutable $endsAt, DateTimeImmutable $now): int
    {
        return max(60, $endsAt->getTimestamp() - $now->getTimestamp() + self::STATE_GRACE_SECONDS);
    }

    private function pushWelcomeClip(
        AiDj $dj,
        Station $station,
        Liquidsoap $backend,
    ): bool {
        try {
            $clipPath = $this->generator->generateShiftIntro($dj, $station);
            if (null === $clipPath) {
                return false;
            }

            $title = 'AI DJ Welcome';
            $track = sprintf(
                'annotate:title="%s",artist="%s",liq_cross_duration="0",' .
                'liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",' .
                'jingle_mode="true",azuracast_autocue="false":%s',
                $title,
                $dj->getName(),
                $clipPath,
            );

            // Liquidsoap::enqueue routes generated /ai_dj/ files into the dedicated
            // speech lane, with legacy Requests fallback if the config has not yet
            // regenerated.
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, $title);
            $this->logger->info('AI DJ: Queued deterministic shift welcome.', [
                'dj' => $dj->getName(),
                'clip' => basename($clipPath),
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Failed to queue deterministic shift welcome.', [
                'dj' => $dj->getName(),
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function pushOutroClip(
        AiDj $dj,
        Station $station,
        Liquidsoap $backend,
        AiDjSchedule $schedule,
        DateTimeImmutable $outroWindowStartsAt,
        DateTimeImmutable $outroWindowEndsAt,
    ): bool {
        try {
            $clipPath = $this->generator->generateShiftOutro($dj, $station);
            if (null === $clipPath) {
                return false;
            }

            // TTS is synchronous. Recheck the actual next boundary after rendering,
            // but keep the widened window and dedicated lane so a slow render is
            // retried rather than silently losing the goodbye.
            $freshNow = new DateTimeImmutable('now', $station->getTimezoneObject());
            $freshAirTime = $this->resolveDirectRequestAirTime($station, $freshNow);
            $scheduleNow = $this->scheduler->findActiveSchedule($station->id, $freshNow);
            $scheduleAtAirTime = $this->scheduler->findActiveSchedule($station->id, $freshAirTime);

            if (
                !$scheduleNow instanceof AiDjSchedule
                || !$scheduleAtAirTime instanceof AiDjSchedule
                || $scheduleNow->getId() !== $schedule->getId()
                || $scheduleAtAirTime->getId() !== $schedule->getId()
                || $freshAirTime < $outroWindowStartsAt
                || $freshAirTime > $outroWindowEndsAt
                || !$this->isSafeOutroAirTime($station, $freshAirTime)
                || !$this->isSpeechQueueEmpty($station, $backend)
            ) {
                $this->logger->info('AI DJ: Deferred late or unsafe shift sign-off render for retry.', [
                    'dj' => $dj->getName(),
                    'fresh_air_time' => $freshAirTime->format(DATE_ATOM),
                ]);
                return false;
            }

            $title = 'AI DJ Sign-off';
            $track = sprintf(
                'annotate:title="%s",artist="%s",liq_cross_duration="0",' .
                'liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",' .
                'jingle_mode="true",azuracast_autocue="false":%s',
                $title,
                $dj->getName(),
                $clipPath,
            );

            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, $title);

            $this->logger->info('AI DJ: Queued scheduled shift sign-off.', [
                'dj' => $dj->getName(),
                'clip' => basename($clipPath),
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Failed to queue scheduled shift sign-off.', [
                'dj' => $dj->getName(),
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function createQueueEntry(
        Station $station,
        string $djName,
        string $clipPath,
        string $title,
    ): void {
        $song = Song::createFromText(sprintf('%s - %s', $djName, $title));
        $song->title = $title;
        $song->artist = $djName;

        $queueEntry = new StationQueue($station, $song);
        $queueEntry->is_visible = true;
        $queueEntry->autodj_custom_uri = $clipPath;
        // It has already been submitted directly to Liquidsoap, so keep it out of
        // normal AutoDJ selection without fabricating a playback timestamp.
        $queueEntry->sent_to_autodj = true;

        $this->em->persist($queueEntry);
        $this->em->flush();
    }
}
