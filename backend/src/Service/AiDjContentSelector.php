<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiDjContent;
use App\Entity\Repository\AiDjContentRepository;
use Psr\SimpleCache\CacheInterface;

/**
 * Picks a random enabled content item for a DJ, avoiding recent repeats.
 */
final class AiDjContentSelector
{
    /** How many recently-played items to remember per DJ + content type. */
    private const int RECENT_LIMIT = 25;

    /** How long to remember recently-played items, in seconds (6 hours). */
    private const int RECENT_TTL = 21600;

    private const string CACHE_KEY_PREFIX = 'ai_dj_recent_content_';

    public function __construct(
        private readonly AiDjContentRepository $contentRepo,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @param (callable(AiDjContent): bool)|null $accept when given, only content it accepts is picked
     */
    public function selectContent(
        int $djId,
        string $contentType,
        int $stationId,
        ?callable $accept = null,
    ): ?AiDjContent {
        $allContent = $this->getAvailableContent($stationId, $contentType);
        if (null !== $accept) {
            $allContent = array_filter($allContent, $accept);
        }
        if ($allContent === []) {
            return null;
        }

        // Recently-used tracking is stored in the shared cache, since each DJ clip is
        // generated in a separate process and an in-memory list would reset every time.
        $cacheKey = $this->getCacheKey($djId, $contentType);
        $recent = $this->getRecentIds($cacheKey);

        $available = $this->excludeRecent($allContent, $recent);

        $selected = $available[array_rand($available)];

        $this->recordAsUsed($cacheKey, $recent, $selected->id);

        return $selected;
    }

    /**
     * @return array<int, AiDjContent>
     */
    private function getAvailableContent(int $stationId, string $contentType): array
    {
        $stationContent = $this->contentRepo->findEnabledByType($stationId, $contentType);
        $globalContent = $this->contentRepo->findGlobalContent($contentType);

        $allContent = [];
        foreach ([...$stationContent, ...$globalContent] as $content) {
            $allContent[$content->id] = $content;
        }

        return $allContent;
    }

    /**
     * @param array<int, AiDjContent> $allContent
     * @param int[] $recent
     * @return array<int, AiDjContent>
     */
    private function excludeRecent(array $allContent, array $recent): array
    {
        $recentSet = array_flip($recent);

        $available = array_filter(
            $allContent,
            static fn(AiDjContent $content): bool => !isset($recentSet[$content->id])
        );

        if ($available !== []) {
            return $available;
        }

        // Everything has been used recently: fall back to the full set, but drop the
        // single most-recent item so the same content never plays twice in a row.
        $lastId = end($recent);
        if ($lastId !== false && count($allContent) > 1) {
            unset($allContent[$lastId]);
        }

        return $allContent;
    }

    /**
     * @return int[]
     */
    private function getRecentIds(string $cacheKey): array
    {
        $recent = $this->cache->get($cacheKey);

        return is_array($recent) ? $recent : [];
    }

    /**
     * @param int[] $recent
     */
    private function recordAsUsed(string $cacheKey, array $recent, int $selectedId): void
    {
        $recent[] = $selectedId;
        if (count($recent) > self::RECENT_LIMIT) {
            $recent = array_slice($recent, -self::RECENT_LIMIT);
        }

        $this->cache->set($cacheKey, $recent, self::RECENT_TTL);
    }

    private function getCacheKey(int $djId, string $contentType): string
    {
        return self::CACHE_KEY_PREFIX . $djId . '_' . $contentType;
    }
}
