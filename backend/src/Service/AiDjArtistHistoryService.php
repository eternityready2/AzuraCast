<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use Psr\SimpleCache\CacheInterface;

/**
 * Fetches artist history/trivia from MusicBrainz (free, no API key) and builds a
 * spoken "fun fact" line about an artist for DJ segments.
 *
 * Safe-by-design: short network timeout + long cache + fully fail-open. It must
 * NEVER stall queue building (that was the cause of the earlier "up next" error),
 * so any failure just returns null and the caller falls back to a content liner.
 */
final class AiDjArtistHistoryService
{
    use LoggerAwareTrait;

    private const string MUSICBRAINZ_URL = 'https://musicbrainz.org/ws/2/artist';
    private const string USER_AGENT = 'AzuraCast-AiDj/1.0 (https://azuracast.com)';
    private const int CACHE_TTL = 604800;   // 7 days
    private const int HTTP_TIMEOUT = 4;      // keep short so it can't stall the stream

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Get a spoken artist-history segment, or null if unavailable.
     */
    public function getArtistHistory(string $artist, string $djName, string $stationName): ?string
    {
        $artist = trim($artist);
        if ($artist === '' || $artist === 'this artist' || $artist === 'that artist') {
            return null;
        }

        // Prefer a richer Wikipedia summary (free, no key). Fall back to the
        // thinner MusicBrainz facts if Wikipedia has nothing usable.
        $wiki = $this->fetchWikipediaSummary($artist);
        if ($wiki !== null) {
            return $this->buildWikipediaScript($wiki, $djName, $stationName);
        }

        $info = $this->fetchArtistInfo($artist);
        if ($info === null) {
            return null;
        }

        return $this->buildArtistScript($info, $djName, $stationName);
    }

    /**
     * Fetch a short, real artist bio from Wikipedia (free, no API key). Returns
     * ~2 sentences, only if the page clearly describes a musical act. Cached +
     * fail-open + short timeout so it can never stall the stream.
     */
    private function fetchWikipediaSummary(string $artist): ?string
    {
        $cacheKey = 'ai_dj_wiki_' . md5(strtolower($artist));
        $cached = $this->cache->get($cacheKey);
        if (is_string($cached)) {
            return $cached === 'none' ? null : $cached;
        }

        $title = rawurlencode(str_replace(' ', '_', $artist));
        $url = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . $title . '?redirect=true';

        $context = stream_context_create([
            'http' => [
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\nAccept: application/json\r\n",
                'timeout' => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->cache->set($cacheKey, 'none', 86400);
            return null;
        }

        $data = json_decode($response, true);
        $extract = (is_array($data) && isset($data['extract']) && is_string($data['extract'])) ? $data['extract'] : '';

        // Guard against disambiguation pages and wrong (non-music) matches.
        $type = (is_array($data) ? ($data['type'] ?? '') : '');
        if ($type === 'disambiguation' || strlen($extract) < 40) {
            $this->cache->set($cacheKey, 'none', 86400);
            return null;
        }
        if (!preg_match('/\b(singer|songwriter|musician|band|music|worship|gospel|rapper|vocal|recording artist|group|duo|choir|hymn|Christian)\b/i', $extract)) {
            $this->cache->set($cacheKey, 'none', 86400);
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

    private function buildWikipediaScript(string $summary, string $djName, string $stationName): string
    {
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
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        // Negative cache: remember "not found" for a day so we don't re-hit the API.
        if ($cached === 'none') {
            return null;
        }

        $url = self::MUSICBRAINZ_URL . '?' . http_build_query([
            'query' => 'artist:' . $artist,
            'fmt' => 'json',
            'limit' => 1,
        ]);

        $context = stream_context_create([
            'http' => [
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\nAccept: application/json\r\n",
                'timeout' => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->logger->debug('AI DJ Artist: MusicBrainz request failed for: ' . $artist);
            $this->cache->set($cacheKey, 'none', 86400);
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['artists'][0])) {
            $this->cache->set($cacheKey, 'none', 86400);
            return null;
        }

        $mb = $data['artists'][0];

        // MusicBrainz search is fuzzy and ranks by score, so a query for a Christian
        // artist can return a high-scoring but WRONG act (e.g. "Steve Green" -> "Green
        // Day", score 100). Only trust a result whose name matches the artist playing.
        $normName = static function (string $s): string {
            $s = (string) preg_replace('/^the\\s+/', '', strtolower(trim($s)));
            return (string) preg_replace('/[^a-z0-9]+/', '', $s);
        };
        if ($normName($artist) === '' || $normName((string)($mb['name'] ?? '')) !== $normName($artist)) {
            $this->logger->debug('AI DJ Artist: MusicBrainz name mismatch for "' . $artist . '" -> "' . ($mb['name'] ?? '') . '"');
            $this->cache->set($cacheKey, 'none', 86400);
            return null;
        }

        // Only trust a strong match.
        if ((int)($mb['score'] ?? 0) < 85) {
            $this->cache->set($cacheKey, 'none', 86400);
            return null;
        }

        $result = [
            'name' => $mb['name'] ?? $artist,
            'type' => $mb['type'] ?? null,
            'country' => $this->resolveCountryName($mb['area']['name'] ?? $mb['country'] ?? null),
            'begin_year' => $this->extractYear($mb['life-span']['begin'] ?? null),
            'end_year' => $this->extractYear($mb['life-span']['end'] ?? null),
            'active' => (($mb['life-span']['ended'] ?? false) === false),
            'tags' => $this->extractTopTags($mb['tags'] ?? []),
        ];

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function buildArtistScript(array $info, string $djName, string $stationName): string
    {
        $name = $info['name'];
        $parts = [];

        if ($info['type'] === 'Group' && $info['begin_year']) {
            if ($info['active']) {
                $years = (int)date('Y') - (int)$info['begin_year'];
                $parts[] = sprintf('%s have been making music since %d, that is %d years of incredible artistry', $name, $info['begin_year'], $years);
            } else {
                $parts[] = sprintf('%s were active from %d to %d', $name, $info['begin_year'], $info['end_year'] ?? (int)date('Y'));
            }
        } elseif ($info['begin_year']) {
            if ($info['active']) {
                $years = (int)date('Y') - (int)$info['begin_year'];
                $parts[] = sprintf('%s has been performing since %d, bringing us %d years of music', $name, $info['begin_year'], $years);
            } else {
                $parts[] = sprintf('%s graced us with their talent from %d to %d', $name, $info['begin_year'], $info['end_year'] ?? (int)date('Y'));
            }
        }

        if (!empty($info['country'])) {
            $parts[] = sprintf('hailing from %s', $info['country']);
        }

        if (!empty($info['tags'])) {
            $tagStr = implode(' and ', array_slice($info['tags'], 0, 2));
            $parts[] = sprintf('known for their %s sound', $tagStr);
        }

        if (empty($parts)) {
            return sprintf(
                "This is %s on %s. You just heard %s, one of those artists who really know how to touch your soul with their music. Stay with us for more.",
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
        $year = (int)substr($date, 0, 4);
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

        usort($tags, static fn($a, $b): int => ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0)));

        return array_values(array_filter(array_map(
            static fn($t): string => (string)($t['name'] ?? ''),
            array_slice($tags, 0, 3)
        )));
    }

    private function resolveCountryName(?string $area): ?string
    {
        if ($area === null || $area === '') {
            return null;
        }
        return $area;
    }
}
