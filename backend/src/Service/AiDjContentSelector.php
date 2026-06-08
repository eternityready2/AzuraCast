<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiDjContent;
use App\Entity\Repository\AiDjContentRepository;

final class AiDjContentSelector
{
    private array $recentlyUsed = [];

    public function __construct(
        private readonly AiDjContentRepository $contentRepo,
    ) {}

    public function selectContent(int $djId, string $contentType, int $stationId): ?AiDjContent
    {
        $stationContent = $this->contentRepo->findEnabledByType($stationId, $contentType);
        $globalContent = $this->contentRepo->findGlobalContent($contentType);

        $allContent = [];
        foreach (array_merge($stationContent, $globalContent) as $content) {
            $allContent[$content->id] = $content;
        }

        $recent = $this->recentlyUsed[$djId] ?? [];
        $available = array_filter($allContent, fn(AiDjContent $c): bool => !isset($recent[$c->id]));

        if (empty($available)) {
            $available = $allContent;
            $this->recentlyUsed[$djId] = [];
        }

        if (empty($available)) {
            return null;
        }

        $selected = $available[array_rand($available)];
        $this->recentlyUsed[$djId][$selected->id] = true;

        return $selected;
    }
}
