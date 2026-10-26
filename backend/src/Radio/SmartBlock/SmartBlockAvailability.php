<?php

declare(strict_types=1);

namespace App\Radio\SmartBlock;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\SmartBlockSortOrder;
use App\Entity\Enums\SmartBlockType;
use App\Entity\Repository\StationSmartBlockCriteriaRepository;
use App\Radio\AutoDJ\DmcaComplianceListener;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Entity\StationSmartBlock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Which songs a smart block can play, and which of them can play at a given
 * time without being taken back out (repeat window, DMCA limits).
 *
 * A smart block that has no such song left stops holding its calendar time,
 * so the station's normal music fills the rest instead of dead air.
 *
 * @phpstan-type PoolSong array{id: int, song_id: string, artist: string, title: string, length: float}
 */
final class SmartBlockAvailability
{
    use EntityManagerAwareTrait;

    /** How long a smart block's song list is reused before it is read again. */
    private const int POOL_TTL_SECONDS = 300;

    /** @var array<int, array{at: int, songs: list<PoolSong>}> */
    private array $poolCache = [];

    /** @var array<string, bool> "smart block id:unix time" => has a playable song */
    private array $playableCache = [];

    /** @var array<string, array<int, true>> "smart block id:unix time" => media ids already offered there */
    private array $offered = [];

    public function __construct(
        private readonly StationSmartBlockCriteriaRepository $criteriaRepo,
        private readonly DmcaComplianceListener $dmca,
    ) {
    }

    /**
     * The songs this smart block can play, in its sort order.
     *
     * @return list<PoolSong>
     */
    public function getPool(StationSmartBlock $smartBlock): array
    {
        $id = $smartBlock->id;
        $cached = $this->poolCache[$id] ?? null;
        if (null !== $cached && time() - $cached['at'] < self::POOL_TTL_SECONDS) {
            return $cached['songs'];
        }

        $songs = array_map(
            static fn(StationMedia $media): array => [
                'id' => $media->id,
                'song_id' => $media->song_id,
                'artist' => $media->artist ?? '',
                'title' => $media->title ?? '',
                'length' => $media->getCalculatedLength(),
            ],
            $this->getPoolMedia($smartBlock)
        );

        $this->poolCache[$id] = ['at' => time(), 'songs' => $songs];

        return $songs;
    }

    /**
     * Forget saved song lists, e.g. after a smart block's rules were saved.
     */
    public function forget(?StationSmartBlock $smartBlock = null): void
    {
        $this->playableCache = [];
        if (null === $smartBlock) {
            $this->poolCache = [];
        } else {
            unset($this->poolCache[$smartBlock->id]);
        }
    }

    /**
     * Songs from the pool that have not aired, and are not queued to air,
     * within the station's repeat window before $at.
     *
     * @return list<PoolSong>
     */
    public function getFreshPool(StationSmartBlock $smartBlock, DateTimeInterface $at): array
    {
        $pool = $this->getPool($smartBlock);
        if ([] === $pool) {
            return [];
        }

        $minutes = $smartBlock->station->backend_config->duplicate_prevention_time_range;
        if ($minutes <= 0) {
            return $pool;
        }

        $recent = $this->recentSongIds($smartBlock, CarbonImmutable::instance($at), $minutes);

        return array_values(array_filter(
            $pool,
            static fn(array $song): bool => !isset($recent[$song['song_id']])
        ));
    }

    /**
     * Songs the smart block can put on the air at $at, best first by pool
     * order: no repeat, inside the station's DMCA limits, and not already
     * offered for this same moment (a retry after something took it back out).
     *
     * @return list<PoolSong>
     */
    public function getPlayablePool(StationSmartBlock $smartBlock, DateTimeInterface $at, int $limit = PHP_INT_MAX): array
    {
        $station = $smartBlock->station;
        $atImmutable = DateTimeImmutable::createFromInterface($at);
        $offered = $this->offered[$this->key($smartBlock, $at)] ?? [];

        $playable = [];
        foreach ($this->getFreshPool($smartBlock, $at) as $song) {
            if (isset($offered[$song['id']])) {
                continue;
            }

            $media = $this->em->find(StationMedia::class, $song['id']);
            if (!$media instanceof StationMedia) {
                continue;
            }

            if (!$this->dmca->allowsPick(StationQueue::fromMedia($station, $media), $station, $atImmutable)) {
                continue;
            }

            $playable[] = $song;
            if (count($playable) >= $limit) {
                break;
            }
        }

        return $playable;
    }

    /**
     * True while the smart block still has a song it can play at $at. When it
     * has none, it stops holding its calendar time and normal music fills in.
     */
    public function hasPlayableSong(StationSmartBlock $smartBlock, DateTimeInterface $at): bool
    {
        $key = $this->key($smartBlock, $at);
        if (!isset($this->playableCache[$key])) {
            if (count($this->playableCache) > 2000) {
                $this->playableCache = [];
            }
            $this->playableCache[$key] = [] !== $this->getPlayablePool($smartBlock, $at, 1);
        }

        return $this->playableCache[$key];
    }

    /**
     * Remember that a song was offered for this moment, so a retry at the same
     * moment offers a different one instead of the same song again.
     */
    public function markOffered(StationSmartBlock $smartBlock, DateTimeInterface $at, int $mediaId): void
    {
        $key = $this->key($smartBlock, $at);
        if (count($this->offered) > 2000) {
            $this->offered = [];
        }
        $this->offered[$key][$mediaId] = true;
        unset($this->playableCache[$key]);
    }

    private function key(StationSmartBlock $smartBlock, DateTimeInterface $at): string
    {
        return $smartBlock->id . ':' . $at->getTimestamp();
    }

    /**
     * Same rule as QueuedRepeatGuard: aired since, or queued to air before $at.
     *
     * @return array<string, true>
     */
    private function recentSongIds(StationSmartBlock $smartBlock, CarbonImmutable $at, int $minutes): array
    {
        $stationId = $smartBlock->station->id;
        $since = $at->subMinutes($minutes)->utc()->format('Y-m-d H:i:s');
        $until = $at->utc()->format('Y-m-d H:i:s');

        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT song_id FROM song_history
            WHERE station_id = ? AND timestamp_start >= ?
            UNION
            SELECT song_id FROM station_queue
            WHERE station_id = ? AND is_played = 0
            AND timestamp_played >= ? AND timestamp_played < ?',
            [$stationId, $since, $stationId, $since, $until]
        );

        $ids = [];
        foreach ($rows as $songId) {
            $ids[(string)$songId] = true;
        }

        return $ids;
    }

    /**
     * @return StationMedia[]
     */
    private function getPoolMedia(StationSmartBlock $smartBlock): array
    {
        if (SmartBlockType::Static === $smartBlock->type) {
            /** @var StationMedia[] $members */
            $members = $this->em->createQuery(
                <<<'DQL'
                    SELECT sm
                    FROM App\Entity\StationSmartBlockMedia sbm
                    JOIN sbm.media sm
                    WHERE sbm.smart_block = :smartBlock
                    AND sbm.is_member = 1
                    AND sm.do_not_play_reason IS NULL
                    ORDER BY sbm.weight ASC, sbm.id ASC
                DQL
            )->setParameter('smartBlock', $smartBlock)
                ->getResult();

            return $members;
        }

        return self::applySortOrder(
            $this->criteriaRepo->getMatchingMedia($smartBlock),
            $smartBlock->sort_order
        );
    }

    /**
     * @param StationMedia[] $media
     * @return StationMedia[]
     */
    public static function applySortOrder(array $media, SmartBlockSortOrder $order): array
    {
        $compare = match ($order) {
            SmartBlockSortOrder::Random => null, // Already shuffled by the rule query.
            SmartBlockSortOrder::NewestFirst => static fn(StationMedia $a, StationMedia $b): int =>
                $b->uploaded_at <=> $a->uploaded_at,
            SmartBlockSortOrder::OldestFirst => static fn(StationMedia $a, StationMedia $b): int =>
                $a->uploaded_at <=> $b->uploaded_at,
            SmartBlockSortOrder::AlphaTitle => static fn(StationMedia $a, StationMedia $b): int =>
                strcasecmp($a->title ?? '', $b->title ?? ''),
            SmartBlockSortOrder::AlphaArtist => static fn(StationMedia $a, StationMedia $b): int =>
                strcasecmp($a->artist ?? '', $b->artist ?? ''),
        };

        if (null !== $compare) {
            usort($media, $compare);
        }

        return $media;
    }
}
