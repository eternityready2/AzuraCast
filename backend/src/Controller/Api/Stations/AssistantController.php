<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Cache\NowPlayingCache;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Http\Response;
use App\Http\ServerRequest;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

final class AssistantController
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NowPlayingCache $nowPlayingCache,
        private readonly StationQueueRepository $queueRepo,
        private readonly HttpClient $httpClient,
    ) {
    }

    public function getSettingsAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $config = $station->backend_config;

        return $response->withJson([
            'provider'  => $config->ai_assistant_provider ?? 'groq',
            'has_key'   => !empty($config->ai_assistant_api_key),
            'model'     => $config->ai_assistant_model ?? '',
            'base_url'  => $config->ai_assistant_base_url ?? '',
        ]);
    }

    public function saveSettingsAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $config  = $station->backend_config;
        $body    = (array)$request->getParsedBody();

        $config->ai_assistant_provider = $body['provider'] ?? 'groq';
        $config->ai_assistant_model    = $body['model'] ?? '';
        $config->ai_assistant_base_url = $body['base_url'] ?? '';

        if (!empty($body['api_key'])) {
            $config->ai_assistant_api_key = $body['api_key'];
        }

        $station->backend_config = $config;
        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(['success' => true]);
    }

    public function chatAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $config  = $station->backend_config;
        $body    = (array)$request->getParsedBody();
        /** @var list<array{role: string, content: mixed}> $messages */
        $messages = (array)($body['messages'] ?? []);

        $provider = $config->ai_assistant_provider ?? 'groq';
        $apiKey   = $config->ai_assistant_api_key ?? '';
        $model    = $config->ai_assistant_model ?: $this->defaultModel($provider);
        $baseUrl  = $this->resolveBaseUrl($provider, $config->ai_assistant_base_url ?? '');

        if (empty($apiKey) && $provider !== 'ollama') {
            return $response->withJson(
                ['error' => 'API key not configured. Go to Settings to add one.'],
                400
            );
        }

        $systemPrompt = $this->buildSystemPrompt($station);
        $allMessages  = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages
        );
        $tools = $this->toolDefinitions();

        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => "Bearer {$apiKey}",
        ];

        $maxIterations = 8;
        for ($i = 0; $i < $maxIterations; $i++) {
            $payload = [
                'model'       => $model,
                'messages'    => $allMessages,
                'tools'       => $tools,
                'tool_choice' => 'auto',
                'max_tokens'  => 4096,
            ];

            try {
                $apiResponse = $this->httpClient->post($baseUrl . '/chat/completions', [
                    RequestOptions::HEADERS => $headers,
                    RequestOptions::JSON    => $payload,
                    RequestOptions::TIMEOUT => 60,
                ]);
                $data         = json_decode((string)$apiResponse->getBody(), true);
                $choice       = $data['choices'][0] ?? null;
                $msg          = $choice['message'] ?? [];
                $finishReason = $choice['finish_reason'] ?? 'stop';
            } catch (\Throwable $e) {
                return $response->withJson(['error' => $e->getMessage()], 500);
            }

            if ($finishReason === 'tool_calls' && !empty($msg['tool_calls'])) {
                $allMessages[] = $msg;

                foreach ($msg['tool_calls'] as $call) {
                    $name   = $call['function']['name'] ?? '';
                    $args   = json_decode($call['function']['arguments'] ?? '{}', true) ?? [];
                    $result = $this->dispatchTool($station, $name, $args);

                    $allMessages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $call['id'],
                        'content'      => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ];
                }

                continue;
            }

            return $response->withJson([
                'role'    => 'assistant',
                'content' => $msg['content'] ?? '',
            ]);
        }

        return $response->withJson(['error' => 'Max tool iterations reached.'], 500);
    }

    // -------------------------------------------------------------------------
    // System prompt
    // -------------------------------------------------------------------------

    private function buildSystemPrompt(Station $station): string
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone($station->getTimezone())))->format('l, F j Y g:i A T');

        return <<<PROMPT
You are an AI assistant for {$station->getName()}, an FM radio station running on AzuraCast.
Today is {$now}.

You help station staff with operational tasks: checking what is on air, reviewing the 24-hour playout log,
managing playlists, searching the media library, troubleshooting issues, and giving programming suggestions.

Always be concise and actionable. When you take an action (like clearing the queue or creating a playlist),
confirm what you did. When something fails, explain what went wrong clearly.

Use the available tools to retrieve live station data before answering questions about the current state
of the station.
PROMPT;
    }

    // -------------------------------------------------------------------------
    // Tool definitions (OpenAI function-calling format)
    // -------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function toolDefinitions(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_now_playing',
                    'description' => 'Returns what is currently on air: song title, artist, playlist, and listener count.',
                    'parameters'  => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_upcoming_log',
                    'description' => 'Returns the next N entries from the 24-hour linear playout log.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'description' => 'Number of upcoming entries to return (default 10, max 50).'],
                        ],
                        'required'   => [],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_recent_history',
                    'description' => 'Returns recently played songs.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'description' => 'Number of recent songs to return (default 10, max 50).'],
                        ],
                        'required'   => [],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'list_playlists',
                    'description' => 'Returns all playlists for the station with their type, weight, and schedule.',
                    'parameters'  => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_media',
                    'description' => 'Searches the station media library by title, artist, or album.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Search term (title, artist, or album).'],
                            'limit' => ['type' => 'integer', 'description' => 'Max results (default 20).'],
                        ],
                        'required'   => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_station_status',
                    'description' => 'Returns the current station health: whether AutoDJ and the frontend stream are running.',
                    'parameters'  => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'clear_queue',
                    'description' => 'Clears the upcoming AutoDJ song queue. Use with caution.',
                    'parameters'  => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'create_playlist',
                    'description' => 'Creates a new playlist on the station.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'name'        => ['type' => 'string', 'description' => 'Playlist name.'],
                            'type'        => ['type' => 'string', 'enum' => ['default', 'scheduled', 'once_per_x_songs', 'once_per_x_minutes', 'once_per_hour'], 'description' => 'Playlist scheduling type.'],
                            'weight'      => ['type' => 'integer', 'description' => 'Playback weight 1-25 (default 3).'],
                            'order'       => ['type' => 'string', 'enum' => ['shuffle', 'random', 'sequential'], 'description' => 'Song order (default shuffle).'],
                            'is_enabled'  => ['type' => 'boolean', 'description' => 'Whether the playlist is active (default true).'],
                            'play_once_per_x_songs' => ['type' => 'integer', 'description' => 'For once_per_x_songs type: how many songs between plays.'],
                            'play_once_per_x_minutes' => ['type' => 'integer', 'description' => 'For once_per_x_minutes type: how many minutes between plays.'],
                        ],
                        'required'   => ['name'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'update_playlist',
                    'description' => 'Updates settings on an existing playlist.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'playlist_id' => ['type' => 'integer', 'description' => 'Playlist ID (from list_playlists).'],
                            'name'        => ['type' => 'string'],
                            'weight'      => ['type' => 'integer'],
                            'is_enabled'  => ['type' => 'boolean'],
                            'order'       => ['type' => 'string', 'enum' => ['shuffle', 'random', 'sequential']],
                        ],
                        'required'   => ['playlist_id'],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Tool dispatcher
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    private function dispatchTool(Station $station, string $name, array $args): mixed
    {
        return match ($name) {
            'get_now_playing'    => $this->toolGetNowPlaying($station),
            'get_upcoming_log'   => $this->toolGetUpcomingLog($station, (int)($args['limit'] ?? 10)),
            'get_recent_history' => $this->toolGetRecentHistory($station, (int)($args['limit'] ?? 10)),
            'list_playlists'     => $this->toolListPlaylists($station),
            'search_media'       => $this->toolSearchMedia($station, (string)($args['query'] ?? ''), (int)($args['limit'] ?? 20)),
            'get_station_status' => $this->toolGetStationStatus($station),
            'clear_queue'        => $this->toolClearQueue($station),
            'create_playlist'    => $this->toolCreatePlaylist($station, $args),
            'update_playlist'    => $this->toolUpdatePlaylist($station, $args),
            default              => ['error' => "Unknown tool: {$name}"],
        };
    }

    // -------------------------------------------------------------------------
    // Tool implementations
    // -------------------------------------------------------------------------

    private function toolGetNowPlaying(Station $station): mixed
    {
        $np = $this->nowPlayingCache->getForStation($station);
        if (null === $np) {
            return ['status' => 'no data available'];
        }

        $current = $np->now_playing;
        return [
            'is_live'        => $np->live->is_live ?? false,
            'song'           => $current?->song?->title ?? 'Unknown',
            'artist'         => $current?->song?->artist ?? '',
            'playlist'       => $current?->playlist ?? '',
            'elapsed'        => $current?->elapsed ?? 0,
            'duration'       => $current?->duration ?? 0,
            'listeners'      => $np->listeners?->current ?? 0,
        ];
    }

    private function toolGetUpcomingLog(Station $station, int $limit): mixed
    {
        $limit = min(50, max(1, $limit));
        $now   = time();

        $rows = $this->em->createQuery(
            'SELECT e FROM App\Entity\StationLogEntry e
             WHERE e.station = :station
             AND e.played_at IS NULL
             AND e.planned_at >= :now
             ORDER BY e.sequence ASC'
        )
            ->setParameter('station', $station)
            ->setParameter('now', $now)
            ->setMaxResults($limit)
            ->getResult();

        return array_map(static function ($entry) {
            return [
                'planned_at' => (new \DateTimeImmutable('@' . $entry->planned_at))->format('g:i A'),
                'title'      => $entry->text ?? 'Unknown',
                'duration'   => $entry->duration ?? 0,
                'status'     => $entry->status ?? '',
            ];
        }, $rows);
    }

    private function toolGetRecentHistory(Station $station, int $limit): mixed
    {
        $limit = min(50, max(1, $limit));

        $rows = $this->em->createQuery(
            'SELECT sh FROM App\Entity\SongHistory sh
             WHERE sh.station = :station
             AND sh.timestamp_end IS NOT NULL
             ORDER BY sh.timestamp_start DESC'
        )
            ->setParameter('station', $station)
            ->setMaxResults($limit)
            ->getResult();

        return array_map(static function ($row) {
            return [
                'played_at' => (new \DateTimeImmutable('@' . $row->timestamp_start))->format('g:i A'),
                'title'     => $row->song?->title ?? 'Unknown',
                'artist'    => $row->song?->artist ?? '',
                'playlist'  => $row->playlist?->name ?? '',
                'listeners' => $row->listeners_start ?? 0,
            ];
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function toolListPlaylists(Station $station): array
    {
        /** @var StationPlaylist[] $playlists */
        $playlists = $this->em->createQuery(
            'SELECT p FROM App\Entity\StationPlaylist p
             WHERE p.station = :station
             ORDER BY p.name ASC'
        )
            ->setParameter('station', $station)
            ->getResult();

        return array_map(static function (StationPlaylist $p) {
            return [
                'id'         => $p->id,
                'name'       => $p->name,
                'type'       => $p->type->value,
                'order'      => $p->order->value,
                'weight'     => $p->weight,
                'is_enabled' => $p->is_enabled,
                'num_songs'  => $p->num_songs ?? 0,
            ];
        }, $playlists);
    }

    private function toolSearchMedia(Station $station, string $query, int $limit): mixed
    {
        if (empty($query)) {
            return ['error' => 'Query cannot be empty.'];
        }

        $limit = min(50, max(1, $limit));
        $like  = '%' . $query . '%';

        $rows = $this->em->createQuery(
            'SELECT sm FROM App\Entity\StationMedia sm
             WHERE sm.station = :station
             AND (sm.title LIKE :q OR sm.artist LIKE :q OR sm.album LIKE :q)
             ORDER BY sm.artist ASC, sm.title ASC'
        )
            ->setParameter('station', $station)
            ->setParameter('q', $like)
            ->setMaxResults($limit)
            ->getResult();

        return array_map(static function ($sm) {
            return [
                'id'       => $sm->id,
                'title'    => $sm->title ?? '',
                'artist'   => $sm->artist ?? '',
                'album'    => $sm->album ?? '',
                'duration' => $sm->length ?? 0,
            ];
        }, $rows);
    }

    private function toolGetStationStatus(Station $station): mixed
    {
        $backendRunning  = false;
        $frontendRunning = false;

        try {
            $backendRunning  = $station->is_enabled && $station->backend_type->isEnabled();
            $frontendRunning = $station->is_enabled && $station->frontend_type->isEnabled();
        } catch (\Throwable) {
        }

        return [
            'station_enabled' => $station->is_enabled,
            'backend_running' => $backendRunning,
            'frontend_running' => $frontendRunning,
            'station_name'    => $station->getName(),
        ];
    }

    private function toolClearQueue(Station $station): mixed
    {
        try {
            $this->queueRepo->clearUpcomingQueue($station);
            return ['success' => true, 'message' => 'Queue cleared successfully.'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $args */
    private function toolCreatePlaylist(Station $station, array $args): mixed
    {
        $name = trim((string)($args['name'] ?? ''));
        if (empty($name)) {
            return ['error' => 'Playlist name is required.'];
        }

        $playlist = new StationPlaylist($station);
        $playlist->name       = $name;
        $playlist->is_enabled = (bool)($args['is_enabled'] ?? true);
        $playlist->weight     = min(25, max(1, (int)($args['weight'] ?? 3)));

        if (!empty($args['type'])) {
            try {
                $playlist->type = \App\Entity\Enums\PlaylistTypes::from($args['type']);
            } catch (\ValueError) {
            }
        }

        if (!empty($args['order'])) {
            try {
                $playlist->order = \App\Entity\Enums\PlaylistOrders::from($args['order']);
            } catch (\ValueError) {
            }
        }

        if (!empty($args['play_once_per_x_songs'])) {
            $playlist->play_once_per_x_songs = (int)$args['play_once_per_x_songs'];
        }

        if (!empty($args['play_once_per_x_minutes'])) {
            $playlist->play_once_per_x_minutes = (int)$args['play_once_per_x_minutes'];
        }

        try {
            $this->em->persist($playlist);
            $this->em->flush();
            return [
                'success'     => true,
                'playlist_id' => $playlist->id,
                'name'        => $playlist->name,
                'message'     => "Playlist \"{$playlist->name}\" created successfully.",
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $args */
    private function toolUpdatePlaylist(Station $station, array $args): mixed
    {
        $id = (int)($args['playlist_id'] ?? 0);
        if (!$id) {
            return ['error' => 'playlist_id is required.'];
        }

        /** @var StationPlaylist|null $playlist */
        $playlist = $this->em->find(StationPlaylist::class, $id);
        if (null === $playlist || $playlist->station->id !== $station->id) {
            return ['error' => "Playlist #{$id} not found."];
        }

        if (isset($args['name'])) {
            $playlist->name = (string)$args['name'];
        }
        if (isset($args['weight'])) {
            $playlist->weight = min(25, max(1, (int)$args['weight']));
        }
        if (isset($args['is_enabled'])) {
            $playlist->is_enabled = (bool)$args['is_enabled'];
        }
        if (!empty($args['order'])) {
            try {
                $playlist->order = \App\Entity\Enums\PlaylistOrders::from($args['order']);
            } catch (\ValueError) {
            }
        }

        try {
            $this->em->persist($playlist);
            $this->em->flush();
            return ['success' => true, 'message' => "Playlist \"{$playlist->name}\" updated."];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function defaultModel(string $provider): string
    {
        return match ($provider) {
            'groq'        => 'llama-3.3-70b-versatile',
            'openrouter'  => 'meta-llama/llama-3.3-70b-instruct:free',
            default       => 'llama3.2',
        };
    }

    private function resolveBaseUrl(string $provider, string $customBaseUrl): string
    {
        if ($provider === 'ollama') {
            return rtrim($customBaseUrl ?: 'http://localhost:11434/v1', '/');
        }
        if ($provider === 'openrouter') {
            return 'https://openrouter.ai/api/v1';
        }
        return 'https://api.groq.com/openai/v1';
    }
}
