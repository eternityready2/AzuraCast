<?php

declare(strict_types=1);

namespace App\Radio;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Web streams are written into liquidsoap.liq, so Liquidsoap keeps playing the
 * old schedule until it restarts (a stream deleted on Monday still aired at its
 * old time on Tuesday, 2026-10-27). Saving a web stream or one of its schedule
 * times marks the station here; ApplyWebStreamChangesTask restarts it.
 */
final class WebStreamAutoApply
{
    private const string CACHE_KEY = 'webstream_auto_apply_pending';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param int[] $stationIds
     */
    public function markPending(array $stationIds): void
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        $pending = $item->isHit() ? (array)$item->get() : [];

        foreach ($stationIds as $stationId) {
            $pending[$stationId] = $stationId;
        }

        $item->set($pending);
        $this->cache->save($item);
    }

    /**
     * @return int[]
     */
    public function getPending(): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        return $item->isHit() ? array_values((array)$item->get()) : [];
    }

    public function clear(int $stationId): void
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if (!$item->isHit()) {
            return;
        }

        $pending = (array)$item->get();
        unset($pending[$stationId]);

        $item->set($pending);
        $this->cache->save($item);
    }
}
