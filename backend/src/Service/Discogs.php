<?php

declare(strict_types=1);

namespace App\Service;

use App\Lock\LockFactory;
use App\Version;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Lock\Exception\LockConflictedException;

final class Discogs
{
    public const string API_BASE_URL = 'https://api.discogs.com/';

    public function __construct(
        private readonly Client $httpClient,
        private readonly LockFactory $lockFactory
    ) {
    }

    /**
     * @param string $apiMethod API path to call.
     * @param string $token The personal access token of a Discogs account.
     * @param mixed[] $query Query string parameters.
     *
     * @return mixed[] The decoded JSON response.
     */
    public function makeRequest(
        string $apiMethod,
        string $token,
        array $query = []
    ): array {
        if ('' === trim($token)) {
            throw new InvalidArgumentException('No Discogs token provided.');
        }

        // Discogs allows 60 requests a minute with a token.
        $rateLimitLock = $this->lockFactory->createLock(
            'api_discogs',
            1,
            false
        );

        try {
            $rateLimitLock->acquire(true);
        } catch (LockConflictedException) {
            throw new RuntimeException('Could not acquire rate limiting lock.');
        }

        $response = $this->httpClient->request(
            'GET',
            self::API_BASE_URL . ltrim($apiMethod, '/'),
            [
                RequestOptions::TIMEOUT => 7,
                RequestOptions::HTTP_ERRORS => true,
                RequestOptions::HEADERS => [
                    'User-Agent' => 'AzuraCast ' . Version::STABLE_VERSION,
                    'Accept' => 'application/json',
                    'Authorization' => 'Discogs token=' . trim($token),
                ],
                RequestOptions::QUERY => $query,
            ]
        );

        $responseBody = (string)$response->getBody();
        return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Releases carrying a track by an artist, best match first.
     *
     * @return mixed[]
     */
    public function findReleasesForTrack(
        string $token,
        string $artist,
        string $track
    ): array {
        $response = $this->makeRequest(
            'database/search',
            $token,
            [
                'type' => 'release',
                'artist' => $artist,
                'track' => $track,
                'per_page' => 5,
            ]
        );

        return $response['results'] ?? [];
    }
}
