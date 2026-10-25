<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use Psr\SimpleCache\CacheInterface;

/**
 * Fetches artist history/trivia from Wikipedia and MusicBrainz (both free, no API
 * key) and builds a spoken "fun fact" line about an artist for DJ segments.
 *
 * Safe-by-design: short network timeout, long-lived cache, and fully fail-open.
 * This must never stall queue building, so any failure simply returns null and
 * the caller falls back to a content liner.
 */
final class AiDjArtistHistoryService
{
    use LoggerAwareTrait;

    private const string MUSICBRAINZ_URL = 'https://musicbrainz.org/ws/2/artist';
    private const string WIKIPEDIA_SUMMARY_URL = 'https://en.wikipedia.org/api/rest_v1/page/summary/%s?redirect=true';
    private const string USER_AGENT = 'AzuraCast-AiDj/1.0 (https://azuracast.com)';

    private const int CACHE_TTL = 604800; // 7 days
    private const int NEGATIVE_CACHE_TTL = 86400; // 1 day
    private const string NEGATIVE_CACHE_VALUE = 'none';

    /** Keep the request timeout short so a slow upstream can never stall the stream. */
    private const int HTTP_TIMEOUT = 4;

    private const int MUSICBRAINZ_MIN_SCORE = 85;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Get a spoken artist-history segment, or null if unavailable.
     */
    public function getArtistHistory(
        string $artist,
        string $djName,
        string $stationName,
        bool $identify = true,
    ): ?string {
        $artist = trim($artist);
        if ($artist === '' || $artist === 'this artist' || $artist === 'that artist') {
            return null;
        }

        // Prefer a richer Wikipedia summary; fall back to the thinner MusicBrainz
        // facts if Wikipedia has nothing usable.
        $wikiSummary = $this->fetchWikipediaSummary($artist);
        if ($wikiSummary !== null) {
            return $this->buildWikipediaScript($wikiSummary, $djName, $stationName, $identify);
        }

        $info = $this->fetchArtistInfo($artist);
        if ($info === null) {
            return null;
        }

        return $this->buildArtistScript($info, $djName, $stationName, $identify);
    }

    /**
     * Fetch a short, real artist bio from Wikipedia. Returns roughly two sentences,
     * only if the page clearly describes a musical act.
     */
    private function fetchWikipediaSummary(string $artist): ?string
    {
        $cacheKey = 'ai_dj_wiki_' . md5(strtolower($artist));
        $cached = $this->getCached($cacheKey);
        if ($cached !== false) {
            return is_string($cached) ? $cached : null;
        }

        $title = rawurlencode(str_replace(' ', '_', $artist));
        $url = sprintf(self::WIKIPEDIA_SUMMARY_URL, $title);

        $response = $this->fetchUrl($url);
        if ($response === null) {
            $this->cacheNegativeResult($cacheKey);
            return null;
        }

        $data = json_decode($response, true);
        $extract = (is_array($data) && is_string($data['extract'] ?? null)) ? $data['extract'] : '';
        $type = is_array($data) ? ($data['type'] ?? '') : '';

        // Guard against disambiguation pages and non-music matches.
        $looksLikeMusicAct = preg_match(
            '/\b(singer|songwriter|musician|band|music|worship|gospel|rapper|vocal|recording artist|group|duo|choir|hymn|Christian)\b/i',
            $extract
        );

        if ($type === 'disambiguation' || strlen($extract) < 40 || !$looksLikeMusicAct) {
            $this->cacheNegativeResult($cacheKey);
            return null;
        }

        $summary = $this->firstSentences($extract, 2);
        $this->cache->set($cacheKey, $summary, self::CACHE_TTL);

        return $summary;
    }

    private function firstSentences(string $text, int $count): string
    {
        $parts = preg_split('/(?<=[.!?])\s+/', trim($text));
        if (!is_array($parts) || count($parts) <= $count) {
            return trim($text);
        }

        return trim(implode(' ', array_slice($parts, 0, $count)));
    }

    private function buildWikipediaScript(string $summary, string $djName, string $stationName, bool $identify): string
    {
        if (!$identify) {
            $templates = [
                "Here's a little something about that artist. %s. Let's keep the music going.",
                "A quick bit of history on the artist you just heard. %s. Stay with us.",
                "Let me tell you a bit about them. %s. More great music coming up.",
            ];

            return sprintf($templates[array_rand($templates)], $summary);
        }

        $templates = [
            "Here's a little something about that artist. %s. This is %s on %s, let's keep the music going.",
            "You know, I love sharing these. %s. That's your music moment with %s, here on %s. Stay with us.",
            "A quick bit of history on the artist you just heard. %s. I'm %s, and you're listening to %s.",
            "Let me tell you a bit about them. %s. This is %s on %s, more great music coming up.",
        ];

        return sprintf($templates[array_rand($templates)], $summary, $djName, $stationName);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchArtistInfo(string $artist): ?array
    {
        $cacheKey = 'ai_dj_artist_' . md5(strtolower($artist));
        $cached = $this->getCached($cacheKey);
        if ($cached !== false) {
            return is_array($cached) ? $cached : null;
        }

        $url = self::MUSICBRAINZ_URL . '?' . http_build_query([
            'query' => 'artist:' . $artist,
            'fmt' => 'json',
            'limit' => 1,
        ]);

        $response = $this->fetchUrl($url);
        if ($response === null) {
            $this->logger->debug('AI DJ Artist: MusicBrainz request failed for: ' . $artist);
            $this->cacheNegativeResult($cacheKey);
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['artists'][0])) {
            $this->cacheNegativeResult($cacheKey);
            return null;
        }

        $match = $data['artists'][0];

        // MusicBrainz search is fuzzy and ranks by score, so a query for one artist
        // can return a high-scoring but unrelated act with a similar name. Only trust
        // a result whose normalized name actually matches the artist playing, and
        // whose match score clears a reasonably strict threshold.
        $normalizedQuery = $this->normalizeArtistName($artist);
        $normalizedMatch = $this->normalizeArtistName((string) ($match['name'] ?? ''));

        if ($normalizedQuery === '' || $normalizedMatch !== $normalizedQuery) {
            $this->logger->debug(sprintf(
                'AI DJ Artist: MusicBrainz name mismatch for "%s" -> "%s"',
                $artist,
                $match['name'] ?? ''
            ));
            $this->cacheNegativeResult($cacheKey);
            return null;
        }

        if ((int) ($match['score'] ?? 0) < self::MUSICBRAINZ_MIN_SCORE) {
            $this->cacheNegativeResult($cacheKey);
            return null;
        }

        $result = [
            'name' => $match['name'] ?? $artist,
            'type' => $match['type'] ?? null,
            'country' => $match['area']['name'] ?? $match['country'] ?? null,
            'begin_year' => $this->extractYear($match['life-span']['begin'] ?? null),
            'end_year' => $this->extractYear($match['life-span']['end'] ?? null),
            'active' => (($match['life-span']['ended'] ?? false) === false),
            'tags' => $this->extractTopTags($match['tags'] ?? []),
        ];

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    private function normalizeArtistName(string $name): string
    {
        $name = (string) preg_replace('/^the\s+/', '', strtolower(trim($name)));

        return (string) preg_replace('/[^a-z0-9]+/', '', $name);
    }

    /**
     * @param array<string, mixed> $info
     */
    private function buildArtistScript(array $info, string $djName, string $stationName, bool $identify): string
    {
        $name = $info['name'];
        $parts = [];

        if ($info['type'] === 'Group' && $info['begin_year']) {
            if ($info['active']) {
                $years = (int) date('Y') - (int) $info['begin_year'];
                $parts[] = sprintf(
                    '%s have been making music since %d, that is %d years of incredible artistry',
                    $name,
                    $info['begin_year'],
                    $years
                );
            } else {
                $parts[] = sprintf(
                    '%s were active from %d to %d',
                    $name,
                    $info['begin_year'],
                    $info['end_year'] ?? (int) date('Y')
                );
            }
        } elseif ($info['begin_year']) {
            if ($info['active']) {
                $years = (int) date('Y') - (int) $info['begin_year'];
                $parts[] = sprintf(
                    '%s has been performing since %d, bringing us %d years of music',
                    $name,
                    $info['begin_year'],
                    $years
                );
            } else {
                $parts[] = sprintf(
                    '%s graced us with their talent from %d to %d',
                    $name,
                    $info['begin_year'],
                    $info['end_year'] ?? (int) date('Y')
                );
            }
        }

        if (!empty($info['country'])) {
            $parts[] = sprintf('hailing from %s', $info['country']);
        }

        if (!empty($info['tags'])) {
            $tagStr = implode(' and ', array_slice($info['tags'], 0, 2));
            $parts[] = sprintf('known for their %s sound', $tagStr);
        }

        if (!$identify) {
            return empty($parts)
                ? sprintf('You just heard %s, one of those artists who really know how to touch your soul. Stay with us.', $name)
                : sprintf("Here's a fun fact about the artist you just heard. %s. More music coming your way.", ucfirst(implode(', ', $parts)) . '.');
        }

        if (empty($parts)) {
            return sprintf(
                'This is %s on %s. You just heard %s, one of those artists who really know how '
                . 'to touch your soul with their music. Stay with us for more.',
                $djName,
                $stationName,
                $name
            );
        }

        $templates = [
            "Hey, it's %s on %s with a little music history for you. %s. What amazing talent. Let's keep the music going.",
            "This is %s on %s, and here's a fun fact about the artist you just heard. %s. Pretty incredible, right? More music coming your way.",
            "You're listening to %s on %s, and I love sharing these little nuggets with you. %s. Music has such a rich history. Stay tuned.",
        ];

        $facts = ucfirst(implode(', ', $parts)) . '.';

        return sprintf($templates[array_rand($templates)], $djName, $stationName, $facts);
    }

    private function extractYear(?string $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }

        $year = (int) substr($date, 0, 4);

        return $year > 1800 ? $year : null;
    }

    /**
     * @param array<int, array<string, mixed>> $tags
     * @return array<int, string>
     */
    private function extractTopTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        usort($tags, static fn($a, $b): int => ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0)));

        return array_values(array_filter(array_map(
            static fn($tag): string => (string) ($tag['name'] ?? ''),
            array_slice($tags, 0, 3)
        )));
    }

    /**
     * GET a URL with a short timeout and a descriptive user agent, tolerating any
     * network or HTTP-level failure by returning null instead of throwing.
     */
    private function fetchUrl(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\nAccept: application/json\r\n",
                'timeout' => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        return $response !== false ? $response : null;
    }

    /**
     * @return string|array<string, mixed>|null|false The cached value; null if a
     *     negative-cache marker is stored under this key; false on a cache miss.
     */
    private function getCached(string $cacheKey): string|array|null|false
    {
        $cached = $this->cache->get($cacheKey);
        if ($cached === null) {
            return false;
        }

        return $cached === self::NEGATIVE_CACHE_VALUE ? null : $cached;
    }

    /**
     * Remember a "not found" / "not usable" result for a day so repeated requests
     * for the same artist don't keep re-hitting the upstream API.
     */
    private function cacheNegativeResult(string $cacheKey): void
    {
        $this->cache->set($cacheKey, self::NEGATIVE_CACHE_VALUE, self::NEGATIVE_CACHE_TTL);
    }
}
