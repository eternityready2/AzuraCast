<?php

declare(strict_types=1);

namespace App\Radio\SmartBlock;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Api\StationPlaylistQueue;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Entity\StationSmartBlock;
use App\Entity\StationSmartBlockMedia;
use App\Radio\AutoDJ\BroadcastClockPlanner;
use App\Radio\AutoDJ\DuplicatePrevention;
use DateTimeImmutable;
use Throwable;

/**
 * Picks the next song for a smart block straight from its own rules (Dynamic)
 * or its saved song list (Static). No playlist is involved.
 *
 * Only songs that would not be taken back out (repeats, DMCA limits) are
 * offered, and a retry for the same moment never offers the same song. Of
 * those, the song this smart block played longest ago goes first; songs
 * played equally long ago keep the smart block's sort order (Random shuffles
 * them). Artist/title separation then has the final say.
 */
final class SmartBlockPicker
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    public function __construct(
        private readonly SmartBlockAvailability $availability,
        private readonly DuplicatePrevention $duplicatePrevention,
        private readonly BroadcastClockPlanner $broadcastClockPlanner,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $recentHistory Station queue history, newest first.
     */
    public function pick(
        StationSmartBlock $smartBlock,
        DateTimeImmutable $expectedPlayTime,
        array $recentHistory,
    ): ?StationQueue {
        $pool = $this->availability->getPlayablePool($smartBlock, $expectedPlayTime);
        if ([] === $pool) {
            $this->logger->info(
                sprintf(
                    'Smart block "%s" has no song it can play now; normal music fills in.',
                    $smartBlock->name
                ),
                ['smart_block_id' => $smartBlock->id]
            );
            return null;
        }

        $lastPlayed = $this->getLastPlayedMap($smartBlock);

        $mediaQueue = [];
        foreach ($pool as $song) {
            $q = new StationPlaylistQueue();
            $q->spm_id = 0;
            $q->media_id = $song['id'];
            $q->song_id = $song['song_id'];
            $q->artist = $song['artist'];
            $q->title = $song['title'];
            $q->last_played = $lastPlayed[$song['id']] ?? 0;
            $mediaQueue[] = $q;
        }

        // usort is stable: equally rested songs keep the sort order.
        usort(
            $mediaQueue,
            static fn(StationPlaylistQueue $a, StationPlaylistQueue $b): int =>
                ($a->last_played ?? 0) <=> ($b->last_played ?? 0)
        );

        $validTrack = $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, false)
            ?? $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, true);

        if (null === $validTrack) {
            return null;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            return null;
        }

        $this->availability->markOffered($smartBlock, $expectedPlayTime, $media->id);

        $this->markPlayed($smartBlock, $media, $expectedPlayTime);

        $queueEntry = StationQueue::fromMedia($smartBlock->station, $media);
        $queueEntry->smart_block = $smartBlock;

        // Same hour-boundary cap a playlist song gets.
        try {
            $maxDuration = $this->broadcastClockPlanner->maxContentDurationBeforeNextSoftAnchor(
                $smartBlock->station,
                $expectedPlayTime,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Broadcast clock boundary calculation failed; falling back to no schedule cap for this track.',
                ['exception' => $e->getMessage()]
            );
            $maxDuration = null;
        }

        if (null !== $maxDuration) {
            $maxPlaySeconds = (int)floor($maxDuration);
            $queueEntry->hour_boundary_max_play_seconds = $maxPlaySeconds;

            if ($media->getCalculatedLength() > $maxDuration) {
                $queueEntry->hour_boundary_enforce_cap = true;
                $queueEntry->duration = (float)$maxPlaySeconds;
            }
        }

        $this->em->persist($queueEntry);

        return $queueEntry;
    }

    /**
     * @return array<int, int> media id => last time this smart block played it
     */
    private function getLastPlayedMap(StationSmartBlock $smartBlock): array
    {
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(sbm.media) AS media_id, sbm.last_played
                FROM App\Entity\StationSmartBlockMedia sbm
                WHERE sbm.smart_block = :smartBlock
            DQL
        )->setParameter('smartBlock', $smartBlock)
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['media_id']] = (int)$row['last_played'];
        }

        return $map;
    }

    private function markPlayed(
        StationSmartBlock $smartBlock,
        StationMedia $media,
        DateTimeImmutable $expectedPlayTime,
    ): void {
        $record = $this->em->getRepository(StationSmartBlockMedia::class)->findOneBy([
            'smart_block' => $smartBlock,
            'media' => $media,
        ]);

        if (!$record instanceof StationSmartBlockMedia) {
            $record = new StationSmartBlockMedia($smartBlock, $media);
        }

        $record->last_played = $expectedPlayTime->getTimestamp();
        $this->em->persist($record);
    }
}
