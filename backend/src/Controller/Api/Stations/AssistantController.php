<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Cache\NowPlayingCache;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMount;
use App\Entity\StationPlaylist;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Nginx\Nginx;
use App\Radio\Adapters;
use App\Radio\Configuration;
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
        private readonly Configuration $configuration,
        private readonly Nginx $nginx,
        private readonly Adapters $adapters,
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
        $now = (new \DateTimeImmutable('now', $station->getTimezoneObject()))->format('l, F j Y g:i A T');

        return <<<PROMPT
You are an AI assistant for {$station->name}, an FM radio station running on AzuraCast.
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
                            'type'        => ['type' => 'string', 'enum' => ['default', 'once_per_x_songs', 'once_per_x_minutes', 'once_per_hour', 'custom'], 'description' => 'Playlist scheduling type. Use "default" for general rotation; scheduling to specific times/days requires a separate schedule step not available via this tool.'],
                            'weight'      => ['type' => 'integer', 'description' => 'Playback weight 1-25 (default 3).'],
                            'order'       => ['type' => 'string', 'enum' => ['shuffle', 'random', 'sequential'], 'description' => 'Song order (default shuffle).'],
                            'is_enabled'  => ['type' => 'boolean', 'description' => 'Whether the playlist is active (default true).'],
                            'play_per_songs' => ['type' => 'integer', 'description' => 'For once_per_x_songs type: how many songs between plays.'],
                            'play_per_minutes' => ['type' => 'integer', 'description' => 'For once_per_x_minutes type: how many minutes between plays.'],
                        ],
                        'required'   => ['name'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'restart_station',
                    'description' => 'Restarts the station backend (AutoDJ/Liquidsoap) and frontend (streaming server). Use this to fix a station that is stuck, not playing, or misconfigured after a settings change.',
                    'parameters'  => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_liquidsoap_log',
                    'description' => 'Returns the tail of the Liquidsoap (AutoDJ backend) error/status log — use this to troubleshoot why AutoDJ crashed, won\'t start, or is behaving unexpectedly.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'lines' => ['type' => 'integer', 'description' => 'Number of lines from the end of the log to return (default 60, max 300).'],
                        ],
                        'required'   => [],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'list_mount_points',
                    'description' => 'Lists the station\'s streaming mount points with their format, bitrate, and visibility settings.',
                    'parameters'  => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'update_mount_point',
                    'description' => 'Updates a streaming mount point\'s configuration (bitrate, format, visibility, default status).',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'mount_id'      => ['type' => 'integer', 'description' => 'Mount point ID from list_mount_points.'],
                            'is_default'    => ['type' => 'boolean'],
                            'is_public'     => ['type' => 'boolean'],
                            'enable_autodj' => ['type' => 'boolean'],
                            'autodj_format' => ['type' => 'string', 'enum' => ['mp3', 'ogg', 'aac', 'opus', 'flac']],
                            'autodj_bitrate' => ['type' => 'integer', 'description' => 'Bitrate in kbps, e.g. 128, 192, 320.'],
                            'max_listener_duration' => ['type' => 'integer', 'description' => 'Max seconds a listener can stay connected; 0 for unlimited.'],
                        ],
                        'required'   => ['mount_id'],
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
            'restart_station'    => $this->toolRestartStation($station),
            'get_liquidsoap_log' => $this->toolGetLiquidsoapLog($station, (int)($args['lines'] ?? 60)),
            'list_mount_points'  => $this->toolListMountPoints($station),
            'update_mount_point' => $this->toolUpdateMountPoint($station, $args),
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
             AND e.aired_at IS NULL
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
                'played_at' => $row->timestamp_start->format('g:i A'),
                'title'     => $row->title ?? 'Unknown',
                'artist'    => $row->artist ?? '',
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
                'num_songs'  => $p->media_items->count(),
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
            $backend = $this->adapters->getBackendAdapter($station);
            if (null !== $backend) {
                $backendRunning = $backend->isRunning($station);
            }
        } catch (\Throwable) {
        }

        try {
            $frontend = $this->adapters->getFrontendAdapter($station);
            if (null !== $frontend) {
                $frontendRunning = $frontend->isRunning($station);
            }
        } catch (\Throwable) {
        }

        return [
            'station_enabled'  => $station->is_enabled,
            'backend_running'  => $backendRunning,
            'frontend_running' => $frontendRunning,
            'station_name'     => $station->name,
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

        if (!empty($args['play_per_songs'])) {
            $playlist->play_per_songs = (int)$args['play_per_songs'];
        }

        if (!empty($args['play_per_minutes'])) {
            $playlist->play_per_minutes = (int)$args['play_per_minutes'];
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

    private function toolRestartStation(Station $station): mixed
    {
        try {
            $station->has_started = true;
            $this->em->persist($station);
            $this->em->flush();

            $this->configuration->writeConfiguration(
                station: $station,
                forceRestart: true,
                attemptReload: false
            );
            $this->nginx->writeConfiguration($station);

            return ['success' => true, 'message' => 'Station backend and frontend restarted.'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function toolGetLiquidsoapLog(Station $station, int $lines): mixed
    {
        $lines = min(300, max(1, $lines));
        $logPath = $station->getRadioConfigDir() . '/liquidsoap.log';

        if (!is_file($logPath) || !is_readable($logPath)) {
            return ['error' => 'Liquidsoap log is not available. The backend may not have started yet.'];
        }

        try {
            $contents = file_get_contents($logPath) ?: '';
            $filtered = str_replace($station->getFilteredPasswords(), '(PASSWORD)', $contents);
            $allLines = explode("\n", rtrim($filtered, "\n"));
            $tail = array_slice($allLines, -$lines);

            return ['log' => implode("\n", $tail)];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** @return list<array<string, mixed>> */
    private function toolListMountPoints(Station $station): array
    {
        /** @var StationMount[] $mounts */
        $mounts = $this->em->createQuery(
            'SELECT m FROM App\Entity\StationMount m
             WHERE m.station = :station
             ORDER BY m.name ASC'
        )
            ->setParameter('station', $station)
            ->getResult();

        return array_map(static function (StationMount $m) {
            return [
                'id'                    => $m->id,
                'name'                  => $m->name,
                'is_default'            => $m->is_default,
                'is_public'             => $m->is_public,
                'enable_autodj'         => $m->enable_autodj,
                'autodj_format'         => $m->autodj_format?->value,
                'autodj_bitrate'        => $m->autodj_bitrate,
                'max_listener_duration' => $m->max_listener_duration,
            ];
        }, $mounts);
    }

    /** @param array<string, mixed> $args */
    private function toolUpdateMountPoint(Station $station, array $args): mixed
    {
        $id = (int)($args['mount_id'] ?? 0);
        if (!$id) {
            return ['error' => 'mount_id is required.'];
        }

        /** @var StationMount|null $mount */
        $mount = $this->em->find(StationMount::class, $id);
        if (null === $mount || $mount->station->id !== $station->id) {
            return ['error' => "Mount point #{$id} not found."];
        }

        if (isset($args['is_default'])) {
            $mount->is_default = (bool)$args['is_default'];
        }
        if (isset($args['is_public'])) {
            $mount->is_public = (bool)$args['is_public'];
        }
        if (isset($args['enable_autodj'])) {
            $mount->enable_autodj = (bool)$args['enable_autodj'];
        }
        if (!empty($args['autodj_format'])) {
            try {
                $mount->autodj_format = \App\Radio\Enums\StreamFormats::from($args['autodj_format']);
            } catch (\ValueError) {
            }
        }
        if (isset($args['autodj_bitrate'])) {
            $mount->autodj_bitrate = (int)$args['autodj_bitrate'];
        }
        if (isset($args['max_listener_duration'])) {
            $mount->max_listener_duration = max(0, (int)$args['max_listener_duration']);
        }

        try {
            $this->em->persist($mount);
            $this->em->flush();
            return ['success' => true, 'message' => "Mount point \"{$mount->name}\" updated."];
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
