<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\AiDj;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Song;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\Adapters;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Entity\AiDjContent;
use App\Service\AiDjArtistHistoryService;
use App\Service\AiDjContentSelector;
use App\Service\AiDjGenerator;
use App\Service\AiDjScheduler;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener that injects AI DJ audio clips into the Liquidsoap requests queue.
 *
 * Fail-open behavior: all errors are caught and logged, never blocking normal playback.
 */
final class AiDjQueueListener implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    /** @var string[] Content types eligible for random liners */
    private const array LINER_TYPES = [
        AiDjContent::TYPE_BIBLE_VERSE,
        AiDjContent::TYPE_JOKE,
        AiDjContent::TYPE_ENCOURAGEMENT,
        AiDjContent::TYPE_INSPIRATION,
        AiDjContent::TYPE_TESTIMONY,
        AiDjContent::TYPE_STORY,
    ];

    public function __construct(
        private readonly AiDjScheduler $scheduler,
        private readonly AiDjGenerator $generator,
        private readonly AiDjContentSelector $contentSelector,
        private readonly Adapters $adapters,
        private readonly ReloadableEntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly StationQueueRepository $stationQueueRepo,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly AiDjArtistHistoryService $artistHistoryService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 1: run AFTER TopOfHourIdScheduler (priority 2) and QueueBuilder
        // so DJ clips never conflict with legal IDs or top-of-hour content.
        return [
            BuildQueue::class => ['onBuildQueue', 1],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        $station = $event->getStation();

        if ($event->isInterrupting()) {
            $this->logger->debug('AI DJ: Skipped - event is interrupting.');
            return;
        }

        // Skip if another listener (e.g. TopOfHourIdScheduler) already queued a song
        if (!empty($event->getNextSongs())) {
            $this->logger->debug('AI DJ: Skipped - another listener already queued songs.');
            return;
        }

        $backend = $this->adapters->getBackendAdapter($station);

        if (!($backend instanceof Liquidsoap)) {
            $this->logger->debug('AI DJ: Skipped - backend is not Liquidsoap.');
            return;
        }

        $queueEmpty = $backend->isQueueEmpty($station, LiquidsoapQueues::Requests);

        if (!$queueEmpty) {
            $this->logger->debug('AI DJ: Skipped - Liquidsoap requests queue is not empty.');
            return;
        }

        // Cooldown: minimum gap between DJ talk breaks so she does not talk on
        // consecutive songs (was 60s, which allowed back-to-back chatter).
        $cooldownKey = 'ai_dj_cooldown_' . $station->id;
        $lastGenTime = $this->cache->get($cooldownKey);
        if ($lastGenTime && (time() - $lastGenTime) < 180) {
            $this->logger->debug('AI DJ: Skipped - cooldown active.', ['elapsed' => time() - $lastGenTime]);
            return;
        }

        // One DJ break at a time. A DJ clip is queued AHEAD of airtime, so a
        // time-based cooldown alone can't stop two clips ending up adjacent: a
        // clip about an earlier song can still be waiting in the queue when a new
        // one is added, so both air back-to-back ("on air = DJ, up next = DJ").
        // If a DJ clip is already waiting to air, do not queue another.
        if ($this->hasUpcomingDjClip($station)) {
            $this->logger->debug('AI DJ: Skipped - a DJ clip is already queued ahead.');
            return;
        }

        $expectedPlayTime = $event->getExpectedPlayTime();

        // Skip if top-of-hour protection is active and we're in the lookahead zone.
        // This uses the station's configured lookahead window instead of hardcoded minutes,
        // so DJ clips never compete with legal IDs or news at the top of hour.
        if ($this->hourBoundaryPlanner->isInLookaheadZone($station, $expectedPlayTime)) {
            $this->logger->debug('AI DJ: Skipped - in top-of-hour lookahead zone.');
            return;
        }

        // Also skip the first 3 minutes after the hour to let legal IDs and news finish.
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $minute = (int) $now->format('i');
        if ($minute <= 3) {
            $this->logger->debug('AI DJ: Skipped - post-hour buffer (minute ' . $minute . ').');
            return;
        }

        // Coordinate with AI Newscaster: avoid minutes around news bulletin times.
        if ($this->isNearNewsBulletin($station, $minute)) {
            $this->logger->debug('AI DJ: Skipped - near AI Newscaster bulletin time.');
            return;
        }
        $dj = $this->scheduler->findActiveDj($station->id, $expectedPlayTime);

        // Track DJ shift transitions for outro firing
        $cacheKey = 'ai_dj_last_active_' . $station->id;
        $previousDjId = $this->cache->get($cacheKey);
        $currentDjId = $dj?->getId() ?? null;

        $this->cache->set($cacheKey, $currentDjId, 3600);

        if ($previousDjId !== null && $previousDjId !== $currentDjId) {
            $previousDj = $this->em->find(AiDj::class, $previousDjId);
            if ($previousDj instanceof AiDj) {
                $this->pushOutroClip($previousDj, $station, $backend);
            }
        }

        // Fire shift intro when a new DJ block begins. Only ONE clip per break:
        // push the welcome and stop here so we don't also queue a post-song clip
        // in the same cycle (that caused "talk ... pause ... talk again").
        if ($currentDjId !== null && $previousDjId !== $currentDjId && $dj instanceof AiDj) {
            $this->pushIntroShiftClip($dj, $station, $backend);
            $this->cache->set($cooldownKey, time(), 300);
            $this->trackCurrentSong($station);
            return;
        }

        if (null === $dj) {
            $this->logger->debug('AI DJ: No active DJ for this time slot.');
            return;
        }

        $this->logger->info('AI DJ: Active DJ found.', ['dj_name' => $dj->getName()]);

        // Check talk frequency
        $frequency = $dj->getTalkFrequency();
        if ($frequency < 1.0 && (mt_rand(1, 100) / 100) > $frequency) {
            $this->logger->debug('AI DJ: Skipped by talk frequency.', ['frequency' => $frequency]);
            // Still track current song for post-song use even when skipping
            $this->trackCurrentSong($station);
            return;
        }

        // The song currently on air, from play history. This is ALWAYS accurate,
        // and because this DJ clip only airs after the current song finishes, it is
        // correct to refer to it in the past tense ("that was X by Y").
        $currentSong = $this->getCurrentPlayingSong($station);
        $curArtist = $currentSong['artist'] ?? null;
        $curTitle = $currentSong['title'] ?? null;

        // The next music track is usually NOT queued yet when the DJ fires, so only
        // use it when it is genuinely known.
        $nextMusicEntry = $this->findNextMusicEntry($station);
        $nextArtist = $nextMusicEntry?->artist;
        $nextTitle = $nextMusicEntry?->title;

        // Record cooldown BEFORE generation to prevent parallel attempts
        $this->cache->set($cooldownKey, time(), 300);

        $roll = mt_rand(1, 100);

        if ($curArtist !== null && $curArtist !== '') {
            // Announce ONLY the song that just played — one song per break for a clean,
            // natural flow. Never pass a "next" song, so the DJ can't chain several
            // song names together in a single break (the "mentioned 3 songs" problem).
            if ($roll <= 45) {
                $this->pushPostSongClip($dj, $curArtist, $curTitle, null, null, $station, $backend);
            } elseif ($roll <= 65) {
                // A short fun fact about the artist that just played. Fetched
                // safely (short timeout + cache); falls back to a content liner
                // if nothing is found so it never delays or stalls playback.
                $this->pushArtistHistoryClip($dj, $curArtist, $station, $backend);
            } else {
                $this->pushContentLiner($dj, $station, $backend);
            }
        } else {
            // No reliably known song — play a content liner, never a generic filler.
            $this->pushContentLiner($dj, $station, $backend);
        }

        $this->trackCurrentSong($station);
    }

    private function trackCurrentSong(Station $station): void
    {
        // Record the song that is actually on air (reliable), not the upcoming
        // queue entry, which is usually empty when the DJ fires.
        $current = $this->getCurrentPlayingSong($station);

        if ($current !== null && ($current['artist'] ?? null) !== null) {
            $this->cache->set('ai_dj_prev_song_' . $station->id, [
                'artist' => $current['artist'],
                'title' => $current['title'],
            ], 600);
        }
    }

    /**
     * Get the song currently (most recently) on air from play history.
     * Always accurate, unlike the upcoming queue which is typically empty at the
     * moment the AI DJ decides to speak.
     *
     * @return array{artist: ?string, title: ?string}|null
     */
    private function getCurrentPlayingSong(Station $station): ?array
    {
        try {
            $last = $this->em->createQuery(
                <<<'DQL'
                    SELECT sh FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.is_visible = 1
                    AND sh.media IS NOT NULL
                    AND sh.artist IS NOT NULL
                    AND sh.artist != :empty
                    ORDER BY sh.timestamp_start DESC
                DQL
            )->setParameter('station', $station)
                ->setParameter('empty', '')
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if ($last === null) {
                return null;
            }

            return [
                'artist' => $last->artist,
                'title' => $last->title,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to load current song from history: %s', $e->getMessage()));
            return null;
        }
    }

    /**
     * Find the next queued music entry (not AI DJ clips) for the station.
     */
    private function findNextMusicEntry(Station $station): ?StationQueue
    {
        $upcomingQueue = $this->stationQueueRepo->getUnplayedQueue($station);

        foreach ($upcomingQueue as $entry) {
            if ($entry->media !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * True if an AI DJ clip is already queued (unplayed) and waiting to air.
     * DJ clips have no media and their custom URI points at the station's ai_dj dir.
     */
    private function hasUpcomingDjClip(Station $station): bool
    {
        foreach ($this->stationQueueRepo->getUnplayedQueue($station) as $entry) {
            $uri = $entry->autodj_custom_uri;
            if ($entry->media === null && $uri !== null && str_contains($uri, 'ai_dj')) {
                return true;
            }
        }

        return false;
    }

    private function pushIntroClip(
        AiDj $dj,
        ?string $artist,
        ?string $songTitle,
        Station $station,
        Liquidsoap $backend
    ): void {
        try {
            $clipPath = $this->generator->generateSongIntro($dj, $artist, $songTitle, $station);

            if (null === $clipPath) {
                $this->logger->warning('AI DJ: Failed to generate intro clip, continuing normal playback.');
                return;
            }

            $track = sprintf('annotate:title="AI DJ Intro",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath);

            $this->logger->info(sprintf(
                'AI DJ: Queued intro clip for DJ "%s" (clip: %s)',
                $dj->getName(),
                basename($clipPath)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push intro clip: %s', $e->getMessage()));
        }
    }

    private function pushPostSongClip(
        AiDj $dj,
        ?string $prevArtist,
        ?string $prevTitle,
        ?string $nextArtist,
        ?string $nextTitle,
        Station $station,
        Liquidsoap $backend
    ): void {
        try {
            $clipPath = $this->generator->generatePostSong(
                $dj,
                $prevArtist,
                $prevTitle,
                $nextArtist,
                $nextTitle,
                $station
            );

            if (null === $clipPath) {
                // Fallback to content liner if post-song generation fails
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }

            $track = sprintf('annotate:title="AI DJ",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, 'AI DJ');

            $this->logger->info(sprintf(
                'AI DJ: Queued post-song clip for DJ "%s" (clip: %s)',
                $dj->getName(),
                basename($clipPath)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push post-song clip: %s', $e->getMessage()));
        }
    }

    private function createQueueEntry(Station $station, string $djName, string $clipPath, string $title = 'AI DJ Intro'): void
    {
        try {
            $song = Song::createFromText(sprintf('%s - %s', $djName, $title));
            $song->title = $title;
            $song->artist = $djName;

            $queueEntry = new StationQueue($station, $song);
            $queueEntry->is_visible = true;
            $queueEntry->autodj_custom_uri = $clipPath;

            $this->em->persist($queueEntry);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to create StationQueue entry: %s', $e->getMessage()));
        }
    }

    private function pushArtistHistoryClip(
        AiDj $dj,
        ?string $artist,
        Station $station,
        Liquidsoap $backend
    ): void {
        if ($artist === null || $artist === '') {
            $this->pushContentLiner($dj, $station, $backend);
            return;
        }

        try {
            $historyText = $this->artistHistoryService->getArtistHistory($artist, $dj->getName(), $station->name);
            if ($historyText === null) {
                // Nothing found — fall back to a content liner so she still talks.
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }

            $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
            $outputPath = $outputDir . '/artist_' . uniqid() . '.mp3';
            $clipPath = $this->generator->generateAudio(
                $historyText,
                $dj->getVoiceModelPath(),
                $outputPath,
                $dj->getVoiceSpeed(),
                $dj->useBackgroundAudio()
            );

            if ($clipPath === null) {
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }

            $track = sprintf('annotate:title="Artist Spotlight",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, 'Artist Spotlight');

            $this->logger->info(sprintf(
                'AI DJ: Queued artist history clip for DJ "%s" (artist: %s)',
                $dj->getName(),
                $artist
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push artist history clip: %s', $e->getMessage()));
            $this->pushContentLiner($dj, $station, $backend);
        }
    }

    private function pushContentLiner(
        AiDj $dj,
        Station $station,
        Liquidsoap $backend
    ): void {
        try {
            $type = self::LINER_TYPES[array_rand(self::LINER_TYPES)];
            $content = $this->contentSelector->selectContent($dj->getId(), $type, $station->id);

            if (null === $content) {
                $this->logger->debug('AI DJ: No content available for liner.', ['type' => $type]);
                return;
            }

            $clipPath = $this->generator->generateContentLiner($dj, $content, $station);

            if (null === $clipPath) {
                return;
            }

            $title = match ($content->type) {
                AiDjContent::TYPE_BIBLE_VERSE => 'Bible Verse',
                AiDjContent::TYPE_JOKE => 'Joke',
                AiDjContent::TYPE_ENCOURAGEMENT => 'Encouragement',
                AiDjContent::TYPE_INSPIRATION => 'Inspiration',
                AiDjContent::TYPE_TESTIMONY => 'Testimony',
                AiDjContent::TYPE_STORY => 'Story',
                default => 'AI DJ Liner',
            };

            $track = sprintf('annotate:title="%s",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $title, $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, $title);

            $this->logger->info(sprintf(
                'AI DJ: Queued %s liner for DJ "%s" (clip: %s)',
                $content->type,
                $dj->getName(),
                basename($clipPath)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push content liner: %s', $e->getMessage()));
        }
    }

    /**
     * Check if current time is near a scheduled AI Newscaster bulletin.
     * Avoids DJ speech 3 minutes before and after news times (:00 and/or :30).
     */
    private function isNearNewsBulletin(Station $station, int $minute): bool
    {
        $backendConfig = $station->backend_config;

        if (!$backendConfig->ai_news_enabled) {
            return false;
        }

        // Bottom-of-hour news: skip minutes 27-33
        if ($backendConfig->ai_news_bottom_of_hour && $minute >= 27 && $minute <= 33) {
            return true;
        }

        return false;
    }

    private function pushIntroShiftClip(
        AiDj $dj,
        Station $station,
        Liquidsoap $backend
    ): void {
        try {
            $clipPath = $this->generator->generateShiftIntro($dj, $station);

            if (null === $clipPath) {
                return;
            }

            $track = sprintf('annotate:title="AI DJ Welcome",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, 'AI DJ Welcome');

            $this->logger->info(sprintf(
                'AI DJ: Queued shift intro clip for DJ "%s" (clip: %s)',
                $dj->getName(),
                basename($clipPath)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push shift intro clip: %s', $e->getMessage()));
        }
    }

    private function pushOutroClip(
        AiDj $dj,
        Station $station,
        Liquidsoap $backend
    ): void {
        try {
            $clipPath = $this->generator->generateShiftOutro($dj, $station);

            if (null === $clipPath) {
                return;
            }

            $track = sprintf('annotate:title="AI DJ Sign-off",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, 'AI DJ Sign-off');

            $this->logger->info(sprintf(
                'AI DJ: Queued outro clip for DJ "%s" (clip: %s)',
                $dj->getName(),
                basename($clipPath)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push outro clip: %s', $e->getMessage()));
        }
    }
}
