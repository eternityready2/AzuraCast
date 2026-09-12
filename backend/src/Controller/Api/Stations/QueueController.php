<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Cache\QueueLogCache;
use App\Entity\Api\StationQueueDetailed;
use App\Entity\Api\Status;
use App\Entity\ApiGenerator\StationQueueApiGenerator;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Paginator;
use App\Radio\AutoDJ\RigidScheduleForecastItem;
use App\Radio\AutoDJ\RigidScheduleForecastService;
use App\Radio\AutoDJ\RigidScheduleWindowResolver;
use App\Utilities\Time;
use App\Utilities\Types;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** @extends AbstractStationApiCrudController<StationQueue> */
#[
    OA\Get(
        path: '/station/{station_id}/queue',
        operationId: 'getQueue',
        summary: 'Return information about the upcoming song playback queue.',
        tags: [OpenApi::TAG_STATIONS_QUEUE],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        allOf: [
                            new OA\Schema(ref: StationQueue::class),
                            new OA\Schema(ref: StationQueueDetailed::class),
                        ]
                    )
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Get(
        path: '/station/{station_id}/queue/{id}',
        operationId: 'getQueueItem',
        summary: 'Retrieve details of a single queued item.',
        tags: [OpenApi::TAG_STATIONS_QUEUE],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Queue Item ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: StationQueue::class),
                        new OA\Schema(ref: StationQueueDetailed::class),
                    ]
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Delete(
        path: '/station/{station_id}/queue/{id}',
        operationId: 'deleteQueueItem',
        summary: 'Delete a single queued item.',
        tags: [OpenApi::TAG_STATIONS_QUEUE],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Queue Item ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final class QueueController extends AbstractStationApiCrudController
{
    protected string $entityClass = StationQueue::class;
    protected string $resourceRouteName = 'api:stations:queue:record';

    public function __construct(
        private readonly StationQueueApiGenerator $queueApiGenerator,
        private readonly StationQueueRepository $queueRepo,
        private readonly QueueLogCache $queueLogCache,
        private readonly RigidScheduleWindowResolver $rigidScheduleWindowResolver,
        private readonly RigidScheduleForecastService $rigidScheduleForecast,
        Serializer $serializer,
        ValidatorInterface $validator
    ) {
        parent::__construct($serializer, $validator);
    }

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $now = Time::nowUtc();

        // Preserve the normal Upcoming Song Queue horizon. If the station uses a
        // wall-clock lookahead (for example 30-60 minutes), use that exact horizon.
        // If it uses the upstream track-count queue instead, derive the horizon from
        // the real rows that are already queued. Strict scheduled songs are spliced
        // into this SAME window; they do not replace it with a separate queue mode.
        $operationalQueue = $this->queueRepo->getUnplayedQueue($station);
        $horizonEnd = $this->getUpcomingHorizonEnd($station, $operationalQueue, $now);
        $rigidWindows = $this->rigidScheduleWindowResolver->getWindows($station, $now, $horizonEnd);

        $qb = $this->queueRepo->getUnplayedBaseQuery($station);

        $searchPhrase = Types::stringOrNull($request->getQueryParam('searchPhrase'), true);
        if (null !== $searchPhrase) {
            $qb->andWhere('(sm.title LIKE :query OR sm.artist LIKE :query OR sm.text LIKE :query)')
                ->setParameter('query', '%' . $searchPhrase . '%');
        }

        // Playlist Groups filtering: a specific playlist (by id), a specific group (by name,
        // matched against the recorded playlist_chain), or "any track queued via a group".
        $filterPlaylistId = Types::intOrNull($request->getQueryParam('filter_playlist_id'));
        if (null !== $filterPlaylistId) {
            $qb->andWhere('sp.id = :filterPlaylistId')
                ->setParameter('filterPlaylistId', $filterPlaylistId);
        }

        $filterGroup = Types::stringOrNull($request->getQueryParam('filter_group'), true);
        if (null !== $filterGroup) {
            $qb->andWhere('sq.playlist_chain LIKE :filterGroup')
                ->setParameter('filterGroup', '%"' . $filterGroup . '"%');
        }

        $filterViaGroup = Types::bool($request->getQueryParam('filter_via_group'));
        if ($filterViaGroup) {
            $qb->andWhere('sq.playlist_chain IS NOT NULL');
        }

        // The repository's operational queue order intentionally puts rows that
        // have already been handed to Liquidsoap before rows still waiting in PHP.
        // The user-facing queue is chronological instead.
        $qb->orderBy('sq.timestamp_played', 'ASC')
            ->addOrderBy('sq.timestamp_cued', 'ASC')
            ->addOrderBy('sq.id', 'ASC');

        // No Strict / Exact Time window intersects the normal live queue horizon,
        // so keep the upstream/native queue endpoint behavior completely unchanged.
        if ([] === $rigidWindows) {
            return $this->listPaginatedFromQuery(
                $request,
                $response,
                $qb->getQuery()
            );
        }

        /** @var list<StationQueue> $queueRows */
        $queueRows = $qb->getQuery()->getResult();
        $rows = [];

        // Keep all real queue rows that can actually reach air in this lookahead.
        // Rows whose expected start falls inside a Strict native window are the
        // AutoDJ underlay and will not air then, so hide those misleading rows.
        // TOH IDs remain because they retain higher wall-clock authority.
        foreach ($queueRows as $queueRow) {
            if ($this->isSuppressedByRigidWindow($queueRow, $rigidWindows)) {
                continue;
            }
            $rows[] = $this->viewRecord($queueRow, $request);
        }

        $hasGroupFilter = null !== $filterGroup || $filterViaGroup;

        // Fill the SAME normal lookahead with songs from every Strict local-song
        // schedule that intersects it, including a schedule that starts later in
        // the window. This is intentionally a short live queue forecast, not the
        // full 24-hour Linear Log.
        if (!$hasGroupFilter) {
            foreach ($this->rigidScheduleForecast->getForecast($station, $now, $horizonEnd, 500) as $forecastItem) {
                if (null !== $filterPlaylistId && $forecastItem->playlist->id !== $filterPlaylistId) {
                    continue;
                }
                if (!$this->upcomingMatchesSearch($forecastItem, $searchPhrase)) {
                    continue;
                }

                $rows[] = $this->viewRigidForecastRecord($station, $forecastItem);
            }
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => ((int)($a['played_at'] ?? 0)) <=> ((int)($b['played_at'] ?? 0)),
        );

        return Paginator::fromArray($rows, $request)->write($response);
    }

    /**
     * @param list<StationQueue> $queueRows
     */
    private function getUpcomingHorizonEnd(
        Station $station,
        array $queueRows,
        DateTimeImmutable $now,
    ): CarbonImmutable {
        $horizonEnd = CarbonImmutable::instance($now);
        $lookaheadMinutes = max(0, $station->backend_config->autodj_queue_lookahead_minutes);

        if ($lookaheadMinutes > 0) {
            $horizonEnd = $horizonEnd->addMinutes($lookaheadMinutes);
        }

        // Zero lookahead means upstream's normal track-count behavior. Preserve
        // that by extending through the rows already in the operational queue.
        // Even when a time lookahead is configured, never shorten an already-built
        // queue if its final row reaches slightly farther than the configured mark.
        foreach ($queueRows as $queueRow) {
            $rowStart = $queueRow->timestamp_played ?? $queueRow->timestamp_cued;
            $rowEnd = CarbonImmutable::instance($rowStart)->addSeconds(
                (int)max(1, ceil($queueRow->duration ?? 1.0))
            );

            if ($rowEnd > $horizonEnd) {
                $horizonEnd = $rowEnd;
            }
        }

        // If the ordinary queue is temporarily empty during an active Strict
        // program and no wall-clock lookahead is configured, still expose the
        // active source's near-term songs instead of returning an empty page.
        if ($horizonEnd <= $now) {
            $activeWindow = $this->rigidScheduleWindowResolver->getActiveWindow($station, $now);
            if (null !== $activeWindow) {
                $horizonEnd = CarbonImmutable::instance($now)->addMinutes(60);
                if ($horizonEnd > $activeWindow['end']) {
                    $horizonEnd = $activeWindow['end'];
                }
            }
        }

        return $horizonEnd;
    }

    /**
     * @param list<array{playlist: \App\Entity\StationPlaylist, schedule: \App\Entity\StationSchedule, start: CarbonImmutable, end: CarbonImmutable}> $rigidWindows
     */
    private function isSuppressedByRigidWindow(
        StationQueue $row,
        array $rigidWindows,
    ): bool {
        if ($row->top_of_hour_legal_id) {
            return false;
        }

        $expectedAt = $row->timestamp_played ?? $row->timestamp_cued;
        $timestamp = $expectedAt->getTimestamp();

        foreach ($rigidWindows as $window) {
            if ($timestamp >= $window['start']->getTimestamp() && $timestamp < $window['end']->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    private function upcomingMatchesSearch(
        RigidScheduleForecastItem $item,
        ?string $searchPhrase,
    ): bool {
        if (null === $searchPhrase) {
            return true;
        }

        $haystack = implode(' ', [
            $item->media->title,
            $item->media->artist,
            $item->media->text,
            $item->playlist->name,
        ]);

        return false !== mb_stripos($haystack, $searchPhrase);
    }

    /** @return array<string, mixed> */
    private function viewRigidForecastRecord(
        Station $station,
        RigidScheduleForecastItem $item,
    ): array {
        // This uses the same response shape as a normal queue row, but the song
        // comes from the strict native Liquidsoap source forecast and is read-only.
        // It is not inserted into or deletable from the PHP AutoDJ database queue.
        $record = $this->rigidScheduleForecast->toQueueRow($station, $item);
        $row = $this->queueApiGenerator->__invoke($record);

        $apiResponse = new StationQueueDetailed();
        $apiResponse->sent_to_autodj = true;
        $apiResponse->is_played = false;
        $apiResponse->autodj_custom_uri = null;
        $apiResponse->media_type = $item->media->type;
        $apiResponse->log = [];
        $apiResponse->links = [];

        return [
            ...get_object_vars($row),
            ...get_object_vars($apiResponse),
        ];
    }

    protected function viewRecord(object $record, ServerRequest $request): array
    {
        $isInternal = $request->isInternal();
        $router = $request->getRouter();

        $row = $this->queueApiGenerator->__invoke($record);

        $apiResponse = new StationQueueDetailed();
        $apiResponse->sent_to_autodj = $record->sent_to_autodj;
        $apiResponse->is_played = $record->is_played;
        $apiResponse->autodj_custom_uri = $record->autodj_custom_uri;
        $apiResponse->log = $this->queueLogCache->getLog($record);
        $apiResponse->media_type = match(true) {
            $record->autodj_custom_uri !== null => 'stream',
            $record->top_of_hour_legal_id => 'id',
            $record->media !== null => $record->media->type,
            default => 'music',
        };

        $apiResponse->links = [
            'self' => $router->fromHere(
                $this->resourceRouteName,
                ['id' => $record->id],
                [],
                !$isInternal
            ),
        ];

        return [
            ...get_object_vars($row),
            ...get_object_vars($apiResponse),
        ];
    }

    public function clearAction(
        ServerRequest $request,
        Response $response
    ): ResponseInterface {
        $station = $request->getStation();
        $this->queueRepo->clearUpcomingQueue($station);

        return $response->withJson(Status::deleted());
    }
}
