<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Cache\QueueLogCache;
use App\Entity\Api\StationQueueDetailed;
use App\Entity\Api\Status;
use App\Entity\ApiGenerator\StationQueueApiGenerator;
use App\Entity\Repository\StationQueueRepository;
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

        if (null !== $this->rigidScheduleWindowResolver->getActiveWindow($station, $now)) {
            return $this->listRigidScheduleQueue($request, $response);
        }

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

        if (Types::bool($request->getQueryParam('filter_via_group'))) {
            $qb->andWhere('sq.playlist_chain IS NOT NULL');
        }

        // The repository's operational queue order intentionally puts rows that
        // have already been handed to Liquidsoap before rows still waiting in PHP.
        // The user-facing queue is chronological instead.
        $qb->orderBy('sq.timestamp_played', 'ASC')
            ->addOrderBy('sq.timestamp_cued', 'ASC')
            ->addOrderBy('sq.id', 'ASC');

        return $this->listPaginatedFromQuery(
            $request,
            $response,
            $qb->getQuery()
        );
    }

    private function listRigidScheduleQueue(
        ServerRequest $request,
        Response $response,
    ): ResponseInterface {
        $station = $request->getStation();
        $now = Time::nowUtc();
        $searchPhrase = Types::stringOrNull($request->getQueryParam('searchPhrase'), true);
        $filterPlaylistId = Types::intOrNull($request->getQueryParam('filter_playlist_id'));
        $hasGroupFilter = null !== Types::stringOrNull($request->getQueryParam('filter_group'), true)
            || Types::bool($request->getQueryParam('filter_via_group'));

        $rows = [];

        // During Strict / Exact Time playback the dedicated native Liquidsoap
        // source is the operational queue. Read only that source's CURRENT
        // remaining cursor here. Do not expand later schedule windows like the
        // 24-hour Linear Log planner does.
        if (!$hasGroupFilter) {
            foreach ($this->rigidScheduleForecast->getActiveUpcoming($station, $now, 250) as $upcomingItem) {
                if (null !== $filterPlaylistId && $upcomingItem->playlist->id !== $filterPlaylistId) {
                    continue;
                }
                if (!$this->upcomingMatchesSearch($upcomingItem, $searchPhrase)) {
                    continue;
                }

                $rows[] = $this->viewActiveUpcomingRecord($station, $upcomingItem);
            }
        }

        // TOH legal IDs retain higher wall-clock authority than the strict lane,
        // so keep real pre-staged IDs visible in chronological position.
        if (null === $filterPlaylistId && !$hasGroupFilter) {
            $tohRows = $this->queueRepo->getUnplayedBaseQuery($station)
                ->andWhere('sq.top_of_hour_legal_id = 1')
                ->orderBy('sq.timestamp_played', 'ASC')
                ->addOrderBy('sq.timestamp_cued', 'ASC')
                ->getQuery()
                ->getResult();

            foreach ($tohRows as $tohRow) {
                if (!$tohRow instanceof StationQueue || !$this->queueRecordMatchesSearch($tohRow, $searchPhrase)) {
                    continue;
                }
                $rows[] = $this->viewRecord($tohRow, $request);
            }
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => ((int)($a['played_at'] ?? 0)) <=> ((int)($b['played_at'] ?? 0)),
        );

        return Paginator::fromArray($rows, $request)->write($response);
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

    private function queueRecordMatchesSearch(
        StationQueue $row,
        ?string $searchPhrase,
    ): bool {
        if (null === $searchPhrase) {
            return true;
        }

        return false !== mb_stripos(
            implode(' ', [$row->title, $row->artist, $row->text]),
            $searchPhrase,
        );
    }

    /** @return array<string, mixed> */
    private function viewActiveUpcomingRecord(
        \App\Entity\Station $station,
        RigidScheduleForecastItem $item,
    ): array {
        // The row shape is reused by the queue UI, but this record comes from the
        // actual native source cursor and is intentionally read-only. It is not a
        // fabricated PHP AutoDJ database row and cannot be deleted from the page.
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
