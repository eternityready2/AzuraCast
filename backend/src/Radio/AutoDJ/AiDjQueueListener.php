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
use App\Service\AiDjWeatherService;
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
        private readonly AiDjWeatherService $weatherService,
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

        // Cooldown: prevent rapid-fire TTS calls (min 60s between generations)
        $cooldownKey = 'ai_dj_cooldown_' . $station->id;
        $lastGenTime = $this->cache->get($cooldownKey);
        if ($lastGenTime && (time() - $lastGenTime) < 60) {
            $this->logger->debug('AI DJ: Skipped - cooldown active.', ['elapsed' => time() - $lastGenTime]);
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

        // Get next song info from station queue (direct query, since this listener
        // runs before the main queue builder populates BuildQueue::nextSongs)
        $nextMusicEntry = $this->findNextMusicEntry($station);
        $nextArtist = $nextMusicEntry?->artist;
        $nextTitle = $nextMusicEntry?->title;

        // Get previous song info from cache
        $prevCacheKey = 'ai_dj_prev_song_' . $station->id;
        $prevSong = $this->cache->get($prevCacheKey);
        $prevArtist = $prevSong['artist'] ?? null;
        $prevTitle = $prevSong['title'] ?? null;

        // Decide what to play:
        // pre-intro (25%), post-song (20%), content liner (25%), artist history (15%), weather (15%)
        $roll = mt_rand(1, 100);

        // Record cooldown BEFORE generation to prevent parallel attempts
        $this->cache->set($cooldownKey, time(), 120);

        if ($roll <= 25) {
            $this->pushIntroClip($dj, $nextArtist, $nextTitle, $station, $backend);
        } elseif ($roll <= 45 && $prevArtist) {
            $this->pushPostSongClip($dj, $prevArtist, $prevTitle, $nextArtist, $nextTitle, $station, $backend);
        } elseif ($roll <= 70) {
            $this->pushContentLiner($dj, $station, $backend);
        } elseif ($roll <= 85) {
            // Artist history segment - uses previous or next artist
            $historyArtist = $prevArtist ?? $nextArtist;
            $this->pushArtistHistoryClip($dj, $historyArtist, $station, $backend);
        } else {
            // Weather segment - falls back to content liner if no city configured
            $this->pushWeatherClip($dj, $station, $backend);
        }

        $this->trackCurrentSong($station);
    }

    private function trackCurrentSong(Station $station): void
    {
        $nextMusicEntry = $this->findNextMusicEntry($station);

        if ($nextMusicEntry !== null && $nextMusicEntry->artist !== null) {
            $this->cache->set('ai_dj_prev_song_' . $station->id, [
                'artist' => $nextMusicEntry->artist,
                'title' => $nextMusicEntry->title,
            ], 600);
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
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }

            $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
            $outputPath = $outputDir . '/artist_' . uniqid() . '.mp3';
            $clipPath = $this->generator->generateAudio($historyText, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $dj->useBackgroundAudio());

            if ($clipPath === null) {
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }

            $track = sprintf('annotate:title="Artist History",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, 'Artist History');

            $this->logger->info(sprintf(
                'AI DJ: Queued artist history clip for DJ "%s" (artist: %s)',
                $dj->getName(),
                $artist
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push artist history clip: %s', $e->getMessage()));
        }
    }

    private function pushWeatherClip(
        AiDj $dj,
        Station $station,
        Liquidsoap $backend
    ): void {
        $city = $dj->getWeatherCity();
        if ($city === null || $city === '') {
            // No weather city configured, fall back to content liner
            $this->pushContentLiner($dj, $station, $backend);
            return;
        }

        // Only do weather once per hour per station
        $weatherCooldownKey = 'ai_dj_weather_' . $station->id;
        if ($this->cache->get($weatherCooldownKey)) {
            $this->pushContentLiner($dj, $station, $backend);
            return;
        }

        try {
            $weatherText = $this->weatherService->getWeatherReport($city, $dj->getName(), $station->name);
            if ($weatherText === null) {
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }

            $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
            $outputPath = $outputDir . '/weather_' . uniqid() . '.mp3';
            $clipPath = $this->generator->generateAudio($weatherText, $dj->getVoiceModelPath(), $outputPath, $dj->getVoiceSpeed(), $dj->useBackgroundAudio());

            if ($clipPath === null) {
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }

            $track = sprintf('annotate:title="Weather Update",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, 'Weather Update');

            // Prevent weather more than once per hour
            $this->cache->set($weatherCooldownKey, true, 3600);

            $this->logger->info(sprintf(
                'AI DJ: Queued weather clip for DJ "%s" (city: %s)',
                $dj->getName(),
                $city
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push weather clip: %s', $e->getMessage()));
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
