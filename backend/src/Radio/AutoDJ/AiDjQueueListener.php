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
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Entity\AiDjContent;
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
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['onBuildQueue', 5],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        $station = $event->getStation();

        if ($event->isInterrupting()) {
            return;
        }

        $backend = $this->adapters->getBackendAdapter($station);

        if (!($backend instanceof Liquidsoap)) {
            return;
        }

        $queueEmpty = $backend->isQueueEmpty($station, LiquidsoapQueues::Requests);

        if (!$queueEmpty) {
            return;
        }

        // Cooldown: prevent rapid-fire TTS calls (min 60s between generations)
        $cooldownKey = 'ai_dj_cooldown_' . $station->id;
        $lastGenTime = $this->cache->get($cooldownKey);
        if ($lastGenTime && (time() - $lastGenTime) < 60) {
            return;
        }

        // Skip near top/bottom of hour to avoid competing with news/station ID
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $minute = (int) $now->format('i');
        if ($minute >= 53 || $minute <= 4 || ($minute >= 24 && $minute <= 34)) {
            return;
        }

        $expectedPlayTime = $event->getExpectedPlayTime();
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

        // Decide what to play: pre-intro (35%), post-song (30%), or content liner (35%)
        $roll = mt_rand(1, 100);

        // Record cooldown BEFORE generation to prevent parallel attempts
        $this->cache->set($cooldownKey, time(), 120);

        if ($roll <= 35) {
            $this->pushIntroClip($dj, $nextArtist, $nextTitle, $station, $backend);
        } elseif ($roll <= 65 && $prevArtist) {
            $this->pushPostSongClip($dj, $prevArtist, $prevTitle, $nextArtist, $nextTitle, $station, $backend);
        } else {
            $this->pushContentLiner($dj, $station, $backend);
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

            $track = sprintf('annotate:title="AI DJ Intro",artist="%s",liq_cross_duration="0",liq_fade_out="0",jingle_mode="true":%s', $dj->getName(), $clipPath);
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

            $track = sprintf('annotate:title="AI DJ",artist="%s",liq_cross_duration="0",liq_fade_out="0",jingle_mode="true":%s', $dj->getName(), $clipPath);
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

            $track = sprintf('annotate:title="%s",artist="%s",liq_cross_duration="0",liq_fade_out="0",jingle_mode="true":%s', $title, $dj->getName(), $clipPath);
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

            $track = sprintf('annotate:title="AI DJ Sign-off",artist="%s",liq_cross_duration="0",liq_fade_out="0",jingle_mode="true":%s', $dj->getName(), $clipPath);
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
