<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use Psr\SimpleCache\CacheInterface;

/**
 * Fetches current weather from Open-Meteo (free, no API key needed).
 * Uses geocoding to resolve city names to coordinates.
 */
final class AiDjWeatherService
{
    use LoggerAwareTrait;

    private const string GEOCODING_URL = 'https://geocoding-api.open-meteo.com/v1/search';
    private const string WEATHER_URL = 'https://api.open-meteo.com/v1/forecast';
    private const int CACHE_TTL = 1800; // 30 minutes
    private const int GEO_CACHE_TTL = 86400; // 24 hours

    /** WMO weather codes mapped to human-readable descriptions */
    private const array WMO_CODES = [
        0 => 'clear skies',
        1 => 'mainly clear skies',
        2 => 'partly cloudy skies',
        3 => 'overcast skies',
        45 => 'foggy conditions',
        48 => 'foggy conditions with rime',
        51 => 'light drizzle',
        53 => 'moderate drizzle',
        55 => 'heavy drizzle',
        61 => 'light rain',
        63 => 'moderate rain',
        65 => 'heavy rain',
        71 => 'light snow',
        73 => 'moderate snow',
        75 => 'heavy snow',
        77 => 'snow grains',
        80 => 'light rain showers',
        81 => 'moderate rain showers',
        82 => 'heavy rain showers',
        85 => 'light snow showers',
        86 => 'heavy snow showers',
        95 => 'thunderstorms',
        96 => 'thunderstorms with hail',
        99 => 'thunderstorms with heavy hail',
    ];

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Get a spoken weather report for a city.
     *
     * @return string|null Spoken text or null on failure
     */
    public function getWeatherReport(string $city, string $djName, string $stationName): ?string
    {
        $coords = $this->geocode($city);
        if ($coords === null) {
            return null;
        }

        $weather = $this->fetchWeather($coords['lat'], $coords['lon']);
        if ($weather === null) {
            return null;
        }

        return $this->buildWeatherScript($weather, $city, $djName, $stationName);
    }

    private function geocode(string $city): ?array
    {
        $cacheKey = 'ai_dj_geo_' . md5(strtolower($city));
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = self::GEOCODING_URL . '?' . http_build_query([
            'name' => $city,
            'count' => 1,
            'language' => 'en',
            'format' => 'json',
        ]);

        $response = @file_get_contents($url);
        if ($response === false) {
            $this->logger->warning('AI DJ Weather: Geocoding request failed for city: ' . $city);
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['results'][0])) {
            $this->logger->warning('AI DJ Weather: No geocoding results for city: ' . $city);
            return null;
        }

        $result = [
            'lat' => $data['results'][0]['latitude'],
            'lon' => $data['results'][0]['longitude'],
            'name' => $data['results'][0]['name'] ?? $city,
        ];

        $this->cache->set($cacheKey, $result, self::GEO_CACHE_TTL);
        return $result;
    }

    private function fetchWeather(float $lat, float $lon): ?array
    {
        $cacheKey = sprintf('ai_dj_wx_%s_%s', round($lat, 2), round($lon, 2));
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = self::WEATHER_URL . '?' . http_build_query([
            'latitude' => $lat,
            'longitude' => $lon,
            'current' => 'temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m',
            'temperature_unit' => 'fahrenheit',
            'wind_speed_unit' => 'mph',
        ]);

        $response = @file_get_contents($url);
        if ($response === false) {
            $this->logger->warning('AI DJ Weather: Weather request failed.');
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['current'])) {
            return null;
        }

        $current = $data['current'];
        $result = [
            'temp_f' => (int) round($current['temperature_2m']),
            'humidity' => (int) ($current['relative_humidity_2m'] ?? 0),
            'wind_mph' => (int) round($current['wind_speed_10m'] ?? 0),
            'condition' => self::WMO_CODES[$current['weather_code'] ?? 0] ?? 'mixed conditions',
        ];

        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    private function buildWeatherScript(array $weather, string $city, string $djName, string $stationName): string
    {
        $templates = [
            "Hey, it's %s here on %s with your weather update. Right now in %s, it's %d degrees with %s. Humidity is at %d percent and winds are blowing around %d miles per hour. Stay comfortable out there and keep listening.",
            "This is %s on %s checking in with the weather. Over in %s, we're looking at %d degrees and %s right now. The humidity is sitting at %d percent with winds around %d miles per hour. Beautiful day to have the radio on.",
            "Time for a quick weather check with %s on %s. In %s right now, the temperature is %d degrees. We've got %s outside with %d percent humidity and winds at about %d miles per hour. Now back to the music.",
        ];

        $template = $templates[array_rand($templates)];

        return sprintf(
            $template,
            $djName,
            $stationName,
            $city,
            $weather['temp_f'],
            $weather['condition'],
            $weather['humidity'],
            $weather['wind_mph']
        );
    }
}
