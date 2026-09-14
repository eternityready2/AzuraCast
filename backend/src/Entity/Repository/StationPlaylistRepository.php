<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroup;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;

/**
 * @extends AbstractStationBasedRepository<StationPlaylist>
 */
final class StationPlaylistRepository extends AbstractStationBasedRepository
{
    protected string $entityClass = StationPlaylist::class;

    public function __construct(
        private readonly StationPlaylistMediaRepository $spmRepo
    ) {
    }

    /**
     * @return StationPlaylist[]
     */
    public function getAllForStation(Station $station): array
    {
        return $this->repository->findBy([
            'station' => $station,
        ]);
    }

    public function stationHasActivePlaylists(Station $station): bool
    {
        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }

            if (PlaylistSources::RemoteUrl === $playlist->source) {
                return true;
            }

            if (PlaylistSources::Playlists === $playlist->source && $playlist->playlists->count() > 0) {
                return true;
            }

            $mediaCount = $this->em->createQuery(
                <<<DQL
                    SELECT COUNT(spm.id) FROM App\Entity\StationPlaylistMedia spm
                    JOIN spm.playlist sp
                    WHERE sp.station = :station
                DQL
            )->setParameter('station', $station)
                ->getSingleScalarResult();

            if ($mediaCount > 0) {
                return true;
            }
        }

        return false;
    }

    /** @return StationPlaylistGroup[] */
    public function getPlaylistGroupQueue(StationPlaylist $playlist): array
    {
        if (PlaylistSources::Playlists !== $playlist->source) {
            throw new InvalidArgumentException('Playlist must be a playlist group.');
        }

        $query = $this->em->createQueryBuilder()
            ->select('spg')
            ->from(StationPlaylistGroup::class, 'spg')
            ->join('spg.playlist', 'memberPlaylist')
            ->where('spg.playlist_group = :playlistGroup')
            ->andWhere('memberPlaylist.is_enabled = 1')
            ->setParameter('playlistGroup', $playlist);

        if (PlaylistOrders::Random === $playlist->order) {
            $query->orderBy('RAND()');
        } else {
            $query->andWhere('spg.is_queued = 1')
                ->orderBy('spg.weight', 'ASC');
        }

        return $query->getQuery()->execute();
    }

    public function isPlaylistGroupQueueCompletelyFilled(StationPlaylist $playlist): bool
    {
        if (PlaylistSources::Playlists !== $playlist->source) {
            throw new InvalidArgumentException('Playlist must be a playlist group.');
        }

        if (PlaylistOrders::Random === $playlist->order) {
            return true;
        }

        $notQueuedCount = $this->getCountPlaylistGroupBaseQuery($playlist)
            ->andWhere('spg.is_queued = 0')
            ->getQuery()
            ->getSingleScalarResult();

        return (int)$notQueuedCount === 0;
    }

    public function isPlaylistGroupQueueEmpty(StationPlaylist $playlist): bool
    {
        if (PlaylistSources::Playlists !== $playlist->source) {
            return false;
        }

        if (PlaylistOrders::Random === $playlist->order) {
            return false;
        }

        $totalCount = $this->getCountPlaylistGroupBaseQuery($playlist)
            ->getQuery()
            ->getSingleScalarResult();

        $notQueuedCount = $this->getCountPlaylistGroupBaseQuery($playlist)
            ->andWhere('spg.is_queued = 0')
            ->getQuery()
            ->getSingleScalarResult();

        return (int)$notQueuedCount === (int)$totalCount;
    }

    private function getCountPlaylistGroupBaseQuery(StationPlaylist $playlist): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('count(spg.id)')
            ->from(StationPlaylistGroup::class, 'spg')
            ->join('spg.playlist', 'sp')
            ->where('spg.playlist_group = :playlistGroup')
            ->andWhere('sp.is_enabled = 1')
            ->setParameter('playlistGroup', $playlist);
    }

    /**
     * Reset playlist-internal queues when station configuration is rewritten or the
     * station restarts, honoring the station-wide sequential policy and per-playlist
     * preserve option from upstream #8613. Playlist Groups are reset alongside song
     * playlists so their internal member rotation cannot be left in a stale state.
     */
    public function resetAllQueues(Station $station): void
    {
        $now = Time::nowUtc();
        $resetSequential = $station->backend_config->reset_sequential_queues_on_restart;

        foreach ($station->playlists as $playlist) {
            if (
                $playlist->preserve_queue_on_restart
                || (!$resetSequential && PlaylistOrders::Sequential === $playlist->order)
            ) {
                continue;
            }

            match ($playlist->source) {
                PlaylistSources::Songs => $this->spmRepo->resetQueue($playlist, $now),
                PlaylistSources::Playlists => $this->resetPlaylistGroupQueue($playlist, $now),
                default => null,
            };
        }
    }

    public function resetPlaylistGroupQueue(
        StationPlaylist $playlist,
        ?CarbonImmutable $now = null
    ): void {
        if (PlaylistSources::Playlists !== $playlist->source) {
            throw new InvalidArgumentException('Playlist must be a playlist group.');
        }

        if (PlaylistOrders::Sequential === $playlist->order) {
            $this->em->createQuery(
                <<<'DQL'
                    UPDATE App\Entity\StationPlaylistGroup spg
                    SET spg.is_queued = 1, spg.consecutive_plays_count = 0
                    WHERE spg.playlist_group = :playlistGroup
                DQL
            )->setParameter('playlistGroup', $playlist)
                ->execute();
        } elseif (PlaylistOrders::Shuffle === $playlist->order) {
            $this->em->wrapInTransaction(
                function () use ($playlist): void {
                    $allRecordsQuery = $this->em->createQuery(
                        <<<'DQL'
                            SELECT spg.id
                            FROM App\Entity\StationPlaylistGroup spg
                            WHERE spg.playlist_group = :playlistGroup
                            ORDER BY RAND()
                        DQL
                    )->setParameter('playlistGroup', $playlist);

                    $updateQuery = $this->em->createQuery(
                        <<<'DQL'
                            UPDATE App\Entity\StationPlaylistGroup spg
                            SET spg.weight = :weight, spg.is_queued = 1, spg.consecutive_plays_count = 0
                            WHERE spg.id = :id
                        DQL
                    );

                    $records = $allRecordsQuery->toIterable([], $allRecordsQuery::HYDRATE_SCALAR);
                    $weight = 1;

                    foreach ($records as $row) {
                        $updateQuery->setParameter('id', $row['id'])
                            ->setParameter('weight', $weight++)
                            ->execute();
                    }
                }
            );
        }

        // Bulk DQL updates bypass Doctrine's managed entity state. Refresh any group
        // members already in the identity map so repeated queue builds in one process
        // advance the rotation instead of replaying stale member state.
        $this->resyncManagedEntities(
            StationPlaylistGroup::class,
            static fn(StationPlaylistGroup $spg): bool => $spg->playlist_group === $playlist
        );

        $now ??= Time::nowUtc();
        $playlist->queue_reset_at = $now;
        $this->em->persist($playlist);
        $this->em->flush();
    }
}
