<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Cache\QueueLogCache;
use App\Container\LoggerAwareTrait;
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
use App\Radio\Adapters;
use App\Radio\AutoDJ\AiNewsScheduleForecastService;
use App\Radio\AutoDJ\RigidScheduleForecastItem;
use App\Radio\AutoDJ\RigidScheduleForecastService;
use App\Radio\AutoDJ\RigidScheduleWindowResolver;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
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
    use LoggerAwareTrait;

    protected string $entityClass = StationQueue::class;
    protected string $resourceRouteName = 'api:stations:queue:record';

    public function __construct(
        private readonly StationQueueApiGenerator $queueApiGenerator,
        private readonly StationQueueRepository $queueRepo,
        private readonly QueueLogCache $queueLogCache,
        private readonly Adapters $adapters,
        private readonly RigidScheduleWindowResolver $rigidScheduleWindowResolver,
        private readonly RigidScheduleForecastService $rigidScheduleForecast,
        private readonly AiNewsScheduleForecastService $aiNewsScheduleForecast,
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

        // This endpoint remains a read-only view of what the existing playout
        // systems say is upcoming. Runtime-owned projections are deliberately
        // limited to the remainder of the current station-local clock hour so a
        // long Strict block never turns this page into a multi-hour programme log.
        $hourEnd = $this->getCurrentHourEnd($station, $now);
        // Extend the window-resolver horizon by the AutoDJ lookahead so that
        // strict windows starting in the NEXT clock hour appear in the queue
        // page before they start (Bug 1: Hymns & Favorites invisible before midnight).
        $lookaheadMinutes = $station->backend_config->autodj_queue_lookahead_minutes;
        $lookaheadEnd = $hourEnd->addMinutes($lookaheadMinutes);
        $rigidWindows = $this->rigidScheduleWindowResolver->getWindows($station, $now, $lookaheadEnd);
        $aiNewsTimes = $this->aiNewsScheduleForecast->getForecast($station, $now, $hourEnd);
        $pendingAiDjRow = $this->getPendingAiDjRuntimeRow($station);

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
        // top_of_hour_legal_id rows are pre-staged and share the same timestamp_cued
        // as songs planned around them. Sort them last within the same second so
        // the queue page shows the correct play order: songs play, then ID fires.
        $qb->orderBy('sq.timestamp_played', 'ASC')
            ->addOrderBy('sq.timestamp_cued', 'ASC')
            ->addOrderBy('sq.top_of_hour_legal_id', 'ASC')
            ->addOrderBy('sq.id', 'ASC');

        // With no runtime-owned content to add or reconcile, preserve the native
        // upstream queue endpoint behavior exactly.
        if ([] === $rigidWindows && [] === $aiNewsTimes && null === $pendingAiDjRow) {
            return $this->listPaginatedFromQuery(
                $request,
                $response,
                $qb->getQuery()
            );
        }

        /** @var list<StationQueue> $queueRows */
        $queueRows = $qb->getQuery()->getResult();
        $rows = [];

        foreach ($queueRows as $queueRow) {
            // Keep the page focused on this clock hour. A TOH Legal ID is the
            // intentional exception because its existing lookahead may queue the
            // next boundary during the latter half of the current hour.
            if (!$queueRow->top_of_hour_legal_id && $this->startsAtOrAfter($queueRow, $hourEnd)) {
                continue;
            }

            // Strict only suppresses ordinary playlist underlay. Real runtime rows
            // such as requests and TOH IDs remain visible; the queue page reports
            // those rows but does not create or reschedule them.
            if ($this->isSuppressedByRigidWindow($queueRow, $rigidWindows)) {
                continue;
            }

            $rows[] = $this->viewRecord($queueRow, $request);
        }

        $hasGroupFilter = null !== $filterGroup || $filterViaGroup;

        if (!$hasGroupFilter) {
            if (
                null !== $pendingAiDjRow
                && null === $filterPlaylistId
                && $this->aiDjMatchesSearch($pendingAiDjRow, $searchPhrase)
            ) {
                $rows[] = $this->viewPendingAiDjRecord($pendingAiDjRow);
            }

            foreach ($this->rigidScheduleForecast->getForecast($station, $now, $hourEnd, 500) as $forecastItem) {
                if (null !== $filterPlaylistId && $forecastItem->playlist->id !== $filterPlaylistId) {
                    continue;
                }
                if (!$this->upcomingMatchesSearch($forecastItem, $searchPhrase)) {
                    continue;
                }

                $rows[] = $this->viewRigidForecastRecord($station, $forecastItem);
            }

            if (null === $filterPlaylistId) {
                foreach ($aiNewsTimes as $newsTime) {
                    if (!$this->aiNewsMatchesSearch($searchPhrase)) {
                        continue;
                    }

                    // With a Top-of-Hour ID the bulletin airs after the :59:59 ID, at
                    // the hour boundary, so list it there rather than at :59:00.
                    if ($station->backend_config->top_of_hour_id_enabled) {
                        $newsTime = CarbonImmutable::instance($newsTime)
                            ->addMinute()
                            ->startOfMinute()
                            ->toDateTimeImmutable();
                    }

                    $rows[] = $this->viewAiNewsForecastRecord($station, $newsTime);
                }
            }
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => ((int)($a['played_at'] ?? 0)) <=> ((int)($b['played_at'] ?? 0)),
        );

        return Paginator::fromArray($rows, $request)->write($response);
    }

    private function getCurrentHourEnd(Station $station, DateTimeImmutable $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)
            ->setTimezone($station->getTimezoneObject())
            ->startOfHour()
            ->addHour()
            ->utc();
    }

    private function startsAtOrAfter(StationQueue $row, DateTimeImmutable $boundary): bool
    {
        $expectedAt = $row->timestamp_played ?? $row->timestamp_cued;
        return $expectedAt >= $boundary;
    }

    /**
     * A clip normally airs within seconds to a couple minutes of being queued —
     * the current song has to finish, at most. If Liquidsoap is still reporting
     * a clip pending well beyond that, something is genuinely stuck (a dead
     * fallback gate, a file that never resolved, a wedged request), not a
     * normal wait for a track boundary. Trusting the "pending" signal past
     * this point is exactly what pins a stale row to the top of the queue
     * page indefinitely.
     */
    private const int PENDING_AI_DJ_STALE_SECONDS = 150;

    private function getPendingAiDjRuntimeRow(Station $station): ?StationQueue
    {
        $backend = $this->adapters->getBackendAdapter($station);
        if (!$backend instanceof Liquidsoap) {
            return null;
        }

        try {
            if ($backend->isQueueEmpty($station, LiquidsoapQueues::AiDj)) {
                return null;
            }
        } catch (\Throwable) {
            // Reporting must never interfere with playout if runtime inspection fails.
            return null;
        }

        /** @var StationQueue|null $row */
        $row = $this->em->createQuery(
            <<<'DQL'
                SELECT sq FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.is_played = 1
                AND sq.timestamp_played IS NULL
                AND sq.autodj_custom_uri IS NOT NULL
                AND sq.autodj_custom_uri LIKE :aiDjPath
                ORDER BY sq.timestamp_cued DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('aiDjPath', '%/ai_dj/%')
            ->setMaxResults(1)
            ->getOneOrNullResult();

        if (null === $row) {
            return null;
        }

        $ageSeconds = Time::nowUtc()->getTimestamp() - $row->timestamp_cued->getTimestamp();
        if ($ageSeconds > self::PENDING_AI_DJ_STALE_SECONDS) {
            $this->logger->warning(
                'AI DJ: Pending clip has not aired within the expected window; ' .
                'hiding stale row from Upcoming Queue and requesting cleanup.',
                [
                    'station_id' => $station->id,
                    'queue_row_id' => $row->id,
                    'age_seconds' => $ageSeconds,
                ]
            );

            // The row will genuinely never resolve on its own if it has sat this
            // long — force-clear the dedicated lane so the runtime task's next
            // heartbeat can detect the empty lane and generate a fresh attempt
            // instead of the same wedged request sitting there forever.
            try {
                $backend->command($station, 'ai_dj_control.clear');
            } catch (\Throwable) {
                // Best-effort cleanup; the report itself must never fail because
                // of this, and the runtime task retries stuck state independently.
            }

            return null;
        }

        return $row;
    }

    /**
     * @param list<array{playlist: \App\Entity\StationPlaylist, schedule: \App\Entity\StationSchedule, start: CarbonImmutable, end: CarbonImmutable}> $rigidWindows
     */
    private function isSuppressedByRigidWindow(
        StationQueue $row,
        array $rigidWindows,
    ): bool {
        if (
            $row->top_of_hour_legal_id
            || null !== $row->autodj_custom_uri
            || null === $row->playlist
        ) {
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

    private function aiDjMatchesSearch(StationQueue $row, ?string $searchPhrase): bool
    {
        if (null === $searchPhrase) {
            return true;
        }

        return false !== mb_stripos(
            implode(' ', [
                'AI DJ',
                (string)$row->title,
                (string)$row->artist,
                (string)$row->text,
            ]),
            $searchPhrase,
        );
    }

    private function aiNewsMatchesSearch(?string $searchPhrase): bool
    {
        if (null === $searchPhrase) {
            return true;
        }

        return false !== mb_stripos(
            'News Hour Eternity Ready News Bulletin Hourly news bulletin AI News',
            $searchPhrase,
        );
    }

    /** @return array<string, mixed> */
    private function viewPendingAiDjRecord(StationQueue $record): array
    {
        $row = $this->queueApiGenerator->__invoke($record);
        // Synthetic AI DJ rows are marked consumed in the database so normal
        // AutoDJ can never replay them. For this read-only reporting view, expose
        // the row as upcoming only while Liquidsoap confirms AI DJ speech is pending.
        $row->played_at = $record->timestamp_cued->getTimestamp();

        $apiResponse = new StationQueueDetailed();
        $apiResponse->sent_to_autodj = true;
        $apiResponse->is_played = false;
        $apiResponse->autodj_custom_uri = null;
        $apiResponse->media_type = 'speech';
        $apiResponse->log = [];
        $apiResponse->links = [];

        return [
            ...get_object_vars($row),
            ...get_object_vars($apiResponse),
        ];
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

    /** @return array<string, mixed> */
    private function viewAiNewsForecastRecord(
        Station $station,
        DateTimeImmutable $playedAt,
    ): array {
        $record = $this->aiNewsScheduleForecast->toQueueRow($station, $playedAt);
        $row = $this->queueApiGenerator->__invoke($record);

        $apiResponse = new StationQueueDetailed();
        $apiResponse->sent_to_autodj = true;
        $apiResponse->is_played = false;
        $apiResponse->autodj_custom_uri = null;
        $apiResponse->media_type = 'news';
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

