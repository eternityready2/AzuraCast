<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiDjContent;
use App\Entity\Repository\AiDjContentRepository;
use Psr\SimpleCache\CacheInterface;

final class AiDjContentSelector
{
    /** How many recently-played items to remember per DJ + content type. */
    private const int RECENT_LIMIT = 25;

    /** How long to remember recently-played items (seconds). */
    private const int RECENT_TTL = 21600; // 6 hours

    public function __construct(
        private readonly AiDjContentRepository $contentRepo,
        private readonly CacheInterface $cache,
    ) {}

    public function selectContent(int $djId, string $contentType, int $stationId): ?AiDjContent
    {
        $stationContent = $this->contentRepo->findEnabledByType($stationId, $contentType);
        $globalContent = $this->contentRepo->findGlobalContent($contentType);

        $allContent = [];
        foreach (array_merge($stationContent, $globalContent) as $content) {
            $allContent[$content->id] = $content;
        }

        if (empty($allContent)) {
            return null;
        }

        // Recently-used tracking persists in the cache so it works ACROSS the
        // separate processes that generate each DJ clip (an in-memory list reset
        // every time, which is why the same joke repeated back-to-back).
        $cacheKey = 'ai_dj_recent_content_' . $djId . '_' . $contentType;
        $recent = $this->cache->get($cacheKey);
        $recent = is_array($recent) ? $recent : [];
        $recentSet = array_flip($recent);

        $available = array_filter(
            $allContent,
            static fn(AiDjContent $c): bool => !isset($recentSet[$c->id])
        );

        // If everything's been used recently, fall back to the full set but drop
        // the single most-recent item so we never repeat twice in a row.
        if (empty($available)) {
            $available = $allContent;
            $lastId = end($recent);
            if ($lastId !== false && count($allContent) > 1) {
                unset($available[$lastId]);
            }
        }

        $selected = $available[array_rand($available)];

        // Record as recently used, keeping only the last RECENT_LIMIT ids.
        $recent[] = $selected->id;
        if (count($recent) > self::RECENT_LIMIT) {
            $recent = array_slice($recent, -self::RECENT_LIMIT);
        }
        $this->cache->set($cacheKey, $recent, self::RECENT_TTL);

        return $selected;
    }
}
