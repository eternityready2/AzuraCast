<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use Psr\SimpleCache\CacheInterface;

/**
 * Fetches artist history/trivia from MusicBrainz (free, no API key).
 * Generates spoken "fun fact" scripts about artists for DJ segments.
 */
final class AiDjArtistHistoryService
{
    use LoggerAwareTrait;

    private const string MUSICBRAINZ_URL = 'https://musicbrainz.org/ws/2/artist';
    private const string USER_AGENT = 'AzuraCast-AiDj/1.0 (https://azuracast.com)';
    private const int CACHE_TTL = 86400; // 24 hours

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Get a spoken artist history segment.
     *
     * @return string|null Spoken text or null if artist not found
     */
    public function getArtistHistory(string $artist, string $djName, string $stationName): ?string
    {
        if (empty(trim($artist)) || $artist === 'this artist' || $artist === 'that artist') {
            return null;
        }

        $info = $this->fetchArtistInfo($artist);
        if ($info === null) {
            return null;
        }

        return $this->buildArtistScript($info, $djName, $stationName);
    }

    private function fetchArtistInfo(string $artist): ?array
    {
        $cacheKey = 'ai_dj_artist_' . md5(strtolower($artist));
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = self::MUSICBRAINZ_URL . '?' . http_build_query([
            'query' => 'artist:' . $artist,
            'fmt' => 'json',
            'limit' => 1,
        ]);

        $context = stream_context_create([
            'http' => [
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\nAccept: application/json\r\n",
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->logger->warning('AI DJ Artist: MusicBrainz request failed for: ' . $artist);
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['artists'][0])) {
            return null;
        }

        $mb = $data['artists'][0];

        // Only use results with a reasonable score match
        if (($mb['score'] ?? 0) < 80) {
            return null;
        }

        $result = [
            'name' => $mb['name'] ?? $artist,
            'type' => $mb['type'] ?? null, // Person, Group, Orchestra, etc.
            'country' => $this->resolveCountryName($mb['area']['name'] ?? $mb['country'] ?? null),
            'begin_year' => $this->extractYear($mb['life-span']['begin'] ?? null),
            'end_year' => $this->extractYear($mb['life-span']['end'] ?? null),
            'active' => ($mb['life-span']['ended'] ?? false) === false,
            'disambiguation' => $mb['disambiguation'] ?? null,
            'tags' => $this->extractTopTags($mb['tags'] ?? []),
        ];

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    private function buildArtistScript(array $info, string $djName, string $stationName): string
    {
        $name = $info['name'];
        $parts = [];

        // Build facts
        if ($info['type'] === 'Group' && $info['begin_year']) {
            if ($info['active']) {
                $years = date('Y') - $info['begin_year'];
                $parts[] = sprintf('%s have been making music since %d, that is %d years of incredible artistry', $name, $info['begin_year'], $years);
            } else {
                $parts[] = sprintf('%s were active from %d to %d', $name, $info['begin_year'], $info['end_year'] ?? date('Y'));
            }
        } elseif ($info['begin_year']) {
            if ($info['active']) {
                $years = date('Y') - $info['begin_year'];
                $parts[] = sprintf('%s has been performing since %d, bringing us %d years of music', $name, $info['begin_year'], $years);
            } else {
                $parts[] = sprintf('%s graced us with their talent from %d to %d', $name, $info['begin_year'], $info['end_year'] ?? date('Y'));
            }
        }

        if ($info['country']) {
            $parts[] = sprintf('hailing from %s', $info['country']);
        }

        if (!empty($info['tags'])) {
            $tagStr = implode(' and ', array_slice($info['tags'], 0, 2));
            $parts[] = sprintf('known for their %s sound', $tagStr);
        }

        if (empty($parts)) {
            return sprintf(
                "This is %s on %s. You just heard %s, one of those artists who really know how to touch your soul with their music. Stay with us for more.",
                $djName, $stationName, $name
            );
        }

        $templates = [
            "Hey, it's %s on %s with a little music history for you. %s. What amazing talent. Let's keep the music going.",
            "This is %s on %s, and here's a fun fact about the artist you just heard. %s. Pretty incredible, right? More music coming your way.",
            "You're listening to %s on %s, and I love sharing these little nuggets with you. %s. Music has such a rich history. Stay tuned.",
        ];

        $template = $templates[array_rand($templates)];
        $facts = ucfirst(implode(', ', $parts)) . '.';

        return sprintf($template, $djName, $stationName, $facts);
    }

    private function extractYear(?string $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }
        $year = (int)substr($date, 0, 4);
        return $year > 1800 ? $year : null;
    }

    private function extractTopTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        usort($tags, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

        return array_map(
            fn($t) => $t['name'] ?? '',
            array_slice($tags, 0, 3)
        );
    }

    private function resolveCountryName(?string $area): ?string
    {
        if ($area === null || $area === '') {
            return null;
        }
        // MusicBrainz area names are already human-readable (e.g. "United States", "United Kingdom")
        return $area;
    }
}
