<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\AiDj;
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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener that injects AI DJ audio clips into the Liquidsoap interrupting queue.
 *
 * Fail-open behavior: all errors are caught and logged, never blocking normal playback.
 * - TTS timeout/generation failure → skip intro, continue playback
 * - Liquidsoap push fails → log error, continue playback
 * - No active DJ → skip, continue playback
 * - No clip available → skip, continue playback
 * - Station restart → clip lost, next song plays normally (fire-and-forget)
 */
final class AiDjQueueListener implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly AiDjScheduler $scheduler,
        private readonly AiDjGenerator $generator,
        private readonly Adapters $adapters,
        private readonly ReloadableEntityManagerInterface $em,
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
        $this->logger->debug('AI DJ: onBuildQueue fired.', ['station_id' => $station->id, 'is_interrupting' => $event->isInterrupting()]);

        if ($event->isInterrupting()) {
            return;
        }

        $backend = $this->adapters->getBackendAdapter($station);

        if (!($backend instanceof Liquidsoap)) {
            $this->logger->debug('AI DJ: Backend is not Liquidsoap, skipping.');
            return;
        }

        $queueEmpty = $backend->isQueueEmpty($station, LiquidsoapQueues::Interrupting);
        $this->logger->debug('AI DJ: Queue empty check.', ['is_empty' => $queueEmpty]);

        if (!$queueEmpty) {
            $this->logger->debug('AI DJ: Interrupting queue not empty, skipping.');
            return;
        }

        $expectedPlayTime = $event->getExpectedPlayTime();
        $this->logger->debug('AI DJ: Checking for active DJ.', ['station_id' => $station->id, 'time' => $expectedPlayTime?->format('c')]);
        $dj = $this->scheduler->findActiveDj($station->id, $expectedPlayTime);

        if (null === $dj) {
            $this->logger->debug('AI DJ: No active DJ for this time slot.');
            return;
        }

        $this->logger->info('AI DJ: Active DJ found.', ['dj_name' => $dj->getName()]);

        $nextSongs = $event->getNextSongs();
        $this->logger->debug('AI DJ: Next songs count.', ['count' => count((array)$nextSongs)]);

        // At priority 5 (before QueueBuilder), nextSongs may be empty.
        // We still push the clip - use null for artist/title and let template handle it.
        $nextSong = !empty($nextSongs) ? (is_array($nextSongs) ? $nextSongs[0] : $nextSongs) : null;
        $artist = $nextSong?->song?->artist ?? null;
        $songTitle = $nextSong?->song?->title ?? null;

        $this->pushIntroClip($dj, $artist, $songTitle, $station, $backend);
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

            $track = sprintf('annotate:title="AI DJ Intro",artist="%s":%s', $dj->getName(), $clipPath);

            $backend->enqueue($station, LiquidsoapQueues::Interrupting, $track);

            $this->createQueueEntry($station, $dj->getName(), $clipPath);

            $this->logger->info(sprintf(
                'AI DJ: Queued intro clip for DJ "%s" at %s (clip: %s)',
                $dj->getName(),
                (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                basename($clipPath)
            ), [
                'dj_id' => $dj->id,
                'dj_name' => $dj->getName(),
                'clip_path' => $clipPath,
                'artist' => $artist,
                'song' => $songTitle,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'AI DJ: Failed to push intro clip: %s. Continuing normal playback.',
                $e->getMessage()
            ), ['exception' => $e]);
        }
    }

    private function createQueueEntry(Station $station, string $djName, string $clipPath): void
    {
        try {
            $song = Song::createFromText(sprintf('%s - AI DJ Intro', $djName));
            $song->title = 'AI DJ Intro';
            $song->artist = $djName;

            $queueEntry = new StationQueue($station, $song);
            $queueEntry->is_visible = true;
            $queueEntry->autodj_custom_uri = $clipPath;

            $this->em->persist($queueEntry);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'AI DJ: Failed to create StationQueue entry: %s',
                $e->getMessage()
            ), ['exception' => $e]);
        }
    }
}
