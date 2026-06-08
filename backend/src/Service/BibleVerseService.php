<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Throwable;

final class BibleVerseService
{
    use LoggerAwareTrait;

    private const string BIBLE_API_URL = 'https://bible-api.com/';
    private const int CACHE_TTL = 86400;
    private const string CACHE_PREFIX = 'bible_verse_';

    private ?array $fallbackVerses = null;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $fallbackDbPath = __DIR__ . '/../../resources/bible_verses.json'
    ) {}

    /**
     * Fetch a Bible verse by reference with caching and fallback.
     */
    public function fetchBibleVerse(string $reference): ?string
    {
        $cacheKey = self::CACHE_PREFIX . preg_replace('/[^a-z0-9]/i', '_', strtolower($reference));
        
        $cachedItem = $this->cache->getItem($cacheKey);
        if ($cachedItem->isHit()) {
            return $cachedItem->get();
        }

        $verseText = $this->fetchFromApi($reference);

        if (null === $verseText) {
            $verseText = $this->fetchFromFallback($reference);
        }

        if (null !== $verseText) {
            $cachedItem->set($verseText);
            $cachedItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cachedItem);
        }

        return $verseText;
    }

    public function getRandomVerse(): ?array
    {
        $this->loadFallbackVerses();
        
        if (empty($this->fallbackVerses['verses'])) {
            return null;
        }

        $verse = $this->fallbackVerses['verses'][array_rand($this->fallbackVerses['verses'])];
        return [
            'reference' => $verse['reference'],
            'text' => $verse['text'],
        ];
    }

    private function fetchFromApi(string $reference): ?string
    {
        try {
            $url = self::BIBLE_API_URL . urlencode($reference);
            $request = $this->requestFactory->createRequest('GET', $url);
            $response = $this->httpClient->sendRequest($request);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['text']) || !is_string($data['text'])) {
                return null;
            }

            return trim($data['text']);
        } catch (ClientExceptionInterface | Throwable $e) {
            $this->logger->warning(sprintf('Bible API request failed: %s', $e->getMessage()));
            return null;
        }
    }

    private function fetchFromFallback(string $reference): ?string
    {
        $this->loadFallbackVerses();

        if (empty($this->fallbackVerses['verses'])) {
            return null;
        }

        $normalizedRef = strtolower(preg_replace('/\s+/', ' ', trim($reference)));

        foreach ($this->fallbackVerses['verses'] as $verse) {
            $verseRef = strtolower(preg_replace('/\s+/', ' ', trim($verse['reference'])));
            if ($verseRef === $normalizedRef) {
                return $verse['text'];
            }
        }

        return null;
    }

    private function loadFallbackVerses(): void
    {
        if (null !== $this->fallbackVerses) {
            return;
        }

        if (!file_exists($this->fallbackDbPath)) {
            $this->fallbackVerses = ['verses' => []];
            return;
        }

        $content = file_get_contents($this->fallbackDbPath);
        $this->fallbackVerses = json_decode($content, true) ?: ['verses' => []];
    }
}
