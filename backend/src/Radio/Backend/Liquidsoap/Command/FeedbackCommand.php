<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap\Command;

use App\Cache\NowPlayingCache;
use App\Container\EntityManagerAwareTrait;
use App\Entity\Repository\SongHistoryRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Radio\AutoDJ\ClockWheel\ClockWheelLegalIdPlaybackService;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Song;
use App\Entity\SongHistory;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Utilities\Types;
use RuntimeException;

final class FeedbackCommand extends AbstractCommand
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly SongHistoryRepository $historyRepo,
        private readonly NowPlayingCache $nowPlayingCache,
        private readonly ClockWheelLegalIdPlaybackService $legalIdPlaybackService,
    ) {
    }

    protected function doRun(
        Station $station,
        bool $asAutoDj = false,
        array $payload = []
    ): bool {
        if (!$asAutoDj) {
            return false;
        }

        // Process Liquidsoap list.assoc to JSON mapping
        $payload = array_map(
            fn($dataVal) => match (true) {
                'true' === $dataVal || 'false' === $dataVal => Types::bool(
                    $dataVal,
                    false,
                    true
                ),
                is_numeric($dataVal) => Types::float($dataVal),
                default => $dataVal
            },
            $payload
        );

        // Process extra metadata sent by Liquidsoap (if it exists).
        $historyRow = $this->getSongHistory($station, $payload);
        $this->em->persist($historyRow);

        $this->historyRepo->changeCurrentSong($station, $historyRow);
        $this->em->flush();

        $this->nowPlayingCache->forceUpdate($station);
        return true;
    }

    /**
     * True when a queue row that already aired is playing on after a station
     * ID: the overview names an ID now, and the row started recently enough
     * that it can still be playing. A replay after a player restart names the
     * song already shown, and stays refused.
     */
    private function isResumedAfterId(Station $station, StationQueue $sq): bool
    {
        $current = $station->current_song;
        if (null === $current || !StationMediaTypes::isStationId($current->media?->type)) {
            return false;
        }

        $startedAt = $sq->timestamp_played?->getTimestamp();
        $length = (float)($sq->duration ?? $sq->media?->getCalculatedLength() ?? 0.0);
        if (null === $startedAt || $length <= 0.0) {
            return false;
        }

        // Its own length, plus the ID and any news it waited through.
        return time() < $startedAt + $length + 300;
    }

    private function getSongHistory(
        Station $station,
        array $payload
    ): SongHistory {
        if (empty($payload['media_id'])) {
            if (empty($payload['artist']) && empty($payload['title'])) {
                throw new RuntimeException('No payload provided.');
            }

            $newSong = Song::createFromArray([
                'artist' => $payload['artist'] ?? '',
                'title' => $payload['title'] ?? '',
            ]);

            if (!$this->historyRepo->isDifferentFromCurrentSong($station, $newSong)) {
                throw new RuntimeException('Song is not different from current song.');
            }

            return new SongHistory(
                $station,
                $newSong
            );
        }

        $media = $this->em->find(StationMedia::class, $payload['media_id']);
        if (!$media instanceof StationMedia) {
            throw new RuntimeException('Media ID does not exist for station.');
        }

        $sq = null;

        // Whether the queue row itself proves this is a genuinely fresh instance
        // (not yet played), which settles the "is this really a new airing"
        // question on its own -- the same-song guard below only needs to run
        // when there is no such row to ask.
        $isFreshQueueRow = false;

        if (!empty($payload['sq_id'])) {
            $sq = $this->em->find(StationQueue::class, $payload['sq_id']);

            if ($sq instanceof StationQueue && $sq->is_played && $this->isResumedAfterId($station, $sq)) {
                // The song the Top-of-Hour ID interrupted plays on after it: Now
                // Playing names it again instead of the ID it showed meanwhile.
                // Refused, the overview read "Station ID" for the 4 minutes
                // Skillet played on after the 9pm ID (Wed 2026-11-11).
                return SongHistory::fromQueue($sq);
            }

            if ($sq instanceof StationQueue && $sq->is_played) {
                throw new RuntimeException(
                    sprintf('Queue row #%s already aired; ignoring replayed metadata.', $sq->id)
                );
            } elseif ($sq instanceof StationQueue) {
                // Fresh unplayed row: real new airing (e.g. same song starting
                // over after the TOH ID). Skip the same-song guard so a
                // back-to-back repeat is not swallowed.
                $isFreshQueueRow = true;

                // The player names what it is really playing. A row changed after
                // it was handed over -- two re-checks of the hour's last song in
                // the same second -- kept the later song while the player aired
                // the one it was sent, and Now Playing, the history and the log
                // named a song that never aired ("Build My Love" aired at 1:54pm,
                // "God Is Able" was recorded; Mon 2026-10-12).
                if ($sq->media?->id !== $media->id) {
                    $sq->setSong($media);
                    $sq->media = $media;
                    $sq->duration = $media->getCalculatedLength();
                    if (!empty($payload['playlist_id'])) {
                        $playlist = $this->em->find(StationPlaylist::class, $payload['playlist_id']);
                        if ($playlist instanceof StationPlaylist) {
                            $sq->playlist = $playlist;
                        }
                    }
                    $this->em->persist($sq);
                }
            }
        } else {
            $sq = $this->queueRepo->findRecentlyCuedSong($station, $media);

            if (null !== $sq) {
                // If there's an existing record, ensure it has all the proper metadata.
                if (null === $sq->media) {
                    $sq->media = $media;
                }

                if (!empty($payload['playlist_id']) && null === $sq->playlist) {
                    $playlist = $this->em->find(StationPlaylist::class, $payload['playlist_id']);
                    if ($playlist instanceof StationPlaylist) {
                        $sq->playlist = $playlist;
                    }
                }

                $this->em->persist($sq);
                $this->em->flush();
            }
        }

        if (!$isFreshQueueRow && !$this->historyRepo->isDifferentFromCurrentSong($station, $media)) {
            throw new RuntimeException('Song is not different from current song.');
        }

        if (null !== $sq) {
            $this->legalIdPlaybackService->recordPlaybackIfLegalId($station, $sq, $media);
            $this->queueRepo->trackPlayed($station, $sq);
            return SongHistory::fromQueue($sq);
        }

        $history = new SongHistory($station, $media);
        $history->media = $media;

        if (!empty($payload['playlist_id'])) {
            $playlist = $this->em->find(StationPlaylist::class, $payload['playlist_id']);
            if ($playlist instanceof StationPlaylist) {
                $history->playlist = $playlist;
            }
        }

        return $history;
    }
}
