<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\SmartBlocks;

use App\Controller\Api\Stations\AbstractScheduledEntityController;
use App\Entity\Api\Error;
use App\Entity\CustomField;
use App\Entity\Enums\SmartBlockCriteriaComparison;
use App\Entity\Enums\SmartBlockCriteriaField;
use App\Entity\Enums\SmartBlockLimitType;
use App\Entity\Enums\SmartBlockMatchType;
use App\Entity\Enums\SmartBlockSortOrder;
use App\Entity\Enums\SmartBlockType;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Repository\StationSmartBlockCriteriaRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationSchedule;
use App\Entity\StationSmartBlock;
use App\Entity\StationSmartBlockCriteria;
use App\Entity\StationSmartBlockMedia;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\SmartBlock\SmartBlockAvailability;
use App\Utilities\DateRange;
use App\Utilities\Types;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Smart blocks: rule-based song sources of their own, scheduled on the calendar.
 *
 * @extends AbstractScheduledEntityController<StationSmartBlock>
 */
#[
    OA\Get(
        path: '/station/{station_id}/smart-blocks',
        operationId: 'getSmartBlocks',
        summary: 'List all smart blocks for a station.',
        tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Post(
        path: '/station/{station_id}/smart-blocks',
        operationId: 'addSmartBlock',
        summary: 'Create a new smart block.',
        tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Get(
        path: '/station/{station_id}/smart-block/{id}',
        operationId: 'getSmartBlock',
        summary: 'Get a smart block, its rules, its calendar times and a preview of matching songs.',
        tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Smart Block ID',
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
    ),
    OA\Put(
        path: '/station/{station_id}/smart-block/{id}',
        operationId: 'editSmartBlock',
        summary: 'Update a smart block (rules and/or calendar times).',
        tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Smart Block ID',
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
    ),
    OA\Delete(
        path: '/station/{station_id}/smart-block/{id}',
        operationId: 'deleteSmartBlock',
        summary: 'Delete a smart block.',
        tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Smart Block ID',
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
final class SmartBlocksController extends AbstractScheduledEntityController
{
    /** Number of sample matching songs returned for the live preview panel. */
    private const int PREVIEW_SAMPLE_SIZE = 25;

    protected string $entityClass = StationSmartBlock::class;
    protected string $resourceRouteName = 'api:stations:smart-block';

    /** @var array{added: int, removed: int, total: int} */
    private array $lastSync = ['added' => 0, 'removed' => 0, 'total' => 0];

    public function __construct(
        StationScheduleRepository $scheduleRepo,
        Scheduler $scheduler,
        Serializer $serializer,
        ValidatorInterface $validator,
        private readonly SmartBlockAvailability $availability,
        private readonly StationSmartBlockCriteriaRepository $criteriaRepo,
    ) {
        parent::__construct($scheduleRepo, $scheduler, $serializer, $validator);
    }

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $router = $request->getRouter();
        $isInternal = $request->isInternal();

        /** @var StationSmartBlock[] $smartBlocks */
        $smartBlocks = $this->em->createQuery(
            <<<'DQL'
                SELECT ssb FROM App\Entity\StationSmartBlock ssb
                WHERE ssb.station = :station
                ORDER BY ssb.name ASC
            DQL
        )->setParameter('station', $station)
            ->getResult();

        $rows = [];
        foreach ($smartBlocks as $smartBlock) {
            $rows[] = [
                'id' => $smartBlock->id,
                'name' => $smartBlock->name,
                'is_enabled' => $smartBlock->is_enabled,
                'type' => $smartBlock->type->value,
                'num_songs' => count($this->availability->getPool($smartBlock)),
                'num_schedules' => $smartBlock->schedule_items->count(),
                'links' => [
                    'self' => $router->fromHere(
                        routeName: $this->resourceRouteName,
                        routeParams: ['id' => $smartBlock->id],
                        absolute: !$isInternal
                    ),
                ],
            ];
        }

        return $response->withJson($rows);
    }

    public function createAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();

        $record = new StationSmartBlock($station);
        $record->name = Types::stringOrNull($request->getParam('name'), true) ?? __('New Smart Block');

        $this->em->persist($record);
        $this->em->flush();

        return $response->withJson($this->viewRecord($record, $request));
    }

    public function editAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $record = $this->getRecord($request, $params);

        if (null === $record) {
            return $response->withStatus(404)
                ->withJson(Error::notFound());
        }

        $this->editRecord((array)$request->getParsedBody(), $record);

        return $response->withJson([
            'success' => true,
            'added' => $this->lastSync['added'],
            'removed' => $this->lastSync['removed'],
            'total_members' => $this->lastSync['total'],
        ]);
    }

    /**
     * Rules and calendar times are saved separately: the Smart Blocks page sends
     * the rules, the calendar sends only `schedule_items`.
     */
    protected function editRecord(?array $data, ?object $record = null, array $context = []): object
    {
        if (null === $data || !$record instanceof StationSmartBlock) {
            throw new InvalidArgumentException('Could not parse input data.');
        }

        if (array_key_exists('name', $data)) {
            $name = Types::stringOrNull($data['name'], true);
            if (null !== $name) {
                $record->name = $name;
            }
        }
        if (array_key_exists('is_enabled', $data)) {
            $record->is_enabled = Types::bool($data['is_enabled']);
        }
        if (array_key_exists('weight', $data)) {
            $record->weight = Types::int($data['weight'], $record->weight);
        }
        if (array_key_exists('smart_block_type', $data)) {
            $record->type = SmartBlockType::tryFrom(Types::string($data['smart_block_type']))
                ?? SmartBlockType::Dynamic;
        }
        if (array_key_exists('smart_block_match_type', $data)) {
            $record->match_type = SmartBlockMatchType::tryFrom(Types::string($data['smart_block_match_type']))
                ?? SmartBlockMatchType::All;
        }
        if (array_key_exists('smart_block_limit', $data)) {
            $record->limit = Types::intOrNull($data['smart_block_limit']);
        }
        if (array_key_exists('smart_block_limit_type', $data)) {
            $record->limit_type = SmartBlockLimitType::tryFrom(Types::string($data['smart_block_limit_type']))
                ?? SmartBlockLimitType::Tracks;
        }
        if (array_key_exists('smart_block_sort_order', $data)) {
            $record->sort_order = SmartBlockSortOrder::tryFrom(Types::string($data['smart_block_sort_order']))
                ?? SmartBlockSortOrder::Random;
        }
        if (array_key_exists('smart_block_avoid_duplicates', $data)) {
            $record->avoid_duplicates = Types::bool($data['smart_block_avoid_duplicates']);
        }

        $this->em->persist($record);

        $rulesSaved = array_key_exists('criteria', $data);
        if ($rulesSaved) {
            $this->replaceCriteria($record, Types::array($data['criteria']));
        }

        $this->em->flush();

        if ($rulesSaved) {
            $this->em->refresh($record);
            $this->availability->forget($record);
            $this->lastSync = (SmartBlockType::Static === $record->type)
                ? $this->rebuildStaticList($record)
                : ['added' => 0, 'removed' => 0, 'total' => count($this->availability->getPool($record))];
        }

        if (array_key_exists('schedule_items', $data) && is_array($data['schedule_items'])) {
            $this->scheduleRepo->setScheduleItems($record, $data['schedule_items']);
        }

        return $record;
    }

    /**
     * @param array<array-key, mixed> $criteria
     */
    private function replaceCriteria(StationSmartBlock $record, array $criteria): void
    {
        // Bulk delete: never trust the in-memory collection to know what is saved.
        $this->em->createQuery(
            'DELETE FROM ' . StationSmartBlockCriteria::class . ' c WHERE c.smart_block = :smartBlock'
        )->setParameter('smartBlock', $record)
            ->execute();

        $record->criteria->clear();

        $weight = 0;
        $seenFingerprints = [];

        foreach ($criteria as $criterion) {
            if (!is_array($criterion)) {
                continue;
            }

            $field = SmartBlockCriteriaField::tryFrom(
                Types::stringOrNull($criterion['field'] ?? null) ?? ''
            ) ?? SmartBlockCriteriaField::default();

            $comparison = SmartBlockCriteriaComparison::tryFrom(
                Types::stringOrNull($criterion['comparison'] ?? null) ?? ''
            ) ?? SmartBlockCriteriaComparison::default();

            $value = Types::stringOrNull($criterion['value'] ?? null);
            $value2 = Types::stringOrNull($criterion['value2'] ?? null);
            $customFieldId = Types::intOrNull($criterion['custom_field_id'] ?? null);

            $fingerprint = implode('|', [
                $field->value,
                $comparison->value,
                $value ?? '',
                $value2 ?? '',
                $customFieldId ?? '',
            ]);
            if (isset($seenFingerprints[$fingerprint])) {
                continue;
            }
            $seenFingerprints[$fingerprint] = true;

            $row = new StationSmartBlockCriteria($record);
            $row->field = $field;
            $row->comparison = $comparison;
            $row->value = $value;
            $row->value2 = $value2;
            $row->weight = $weight;

            if (SmartBlockCriteriaField::CustomField === $field && null !== $customFieldId) {
                $customField = $this->em->find(CustomField::class, $customFieldId);
                if ($customField instanceof CustomField) {
                    $row->custom_field = $customField;
                }
            }

            $this->em->persist($row);
            $weight++;
        }
    }

    /**
     * A Static smart block's song list is made when its rules are saved and
     * stays the same until they are saved again. Rotation memory is kept.
     *
     * @return array{added: int, removed: int, total: int}
     */
    private function rebuildStaticList(StationSmartBlock $record): array
    {
        $matching = SmartBlockAvailability::applySortOrder(
            $this->criteriaRepo->getMatchingMedia($record),
            $record->sort_order
        );

        /** @var StationSmartBlockMedia[] $existing */
        $existing = $this->em->getRepository(StationSmartBlockMedia::class)->findBy(['smart_block' => $record]);

        $byMedia = [];
        foreach ($existing as $row) {
            $byMedia[$row->media->id] = $row;
        }

        $wanted = [];
        $weight = 0;
        $added = 0;
        foreach ($matching as $media) {
            if (isset($wanted[$media->id])) {
                continue;
            }
            $wanted[$media->id] = true;

            $row = $byMedia[$media->id] ?? new StationSmartBlockMedia($record, $media);
            if (!$row->is_member) {
                $added++;
            }
            $row->is_member = true;
            $row->weight = ++$weight;
            $this->em->persist($row);
        }

        $removed = 0;
        foreach ($byMedia as $mediaId => $row) {
            if (!isset($wanted[$mediaId]) && $row->is_member) {
                $row->is_member = false;
                $this->em->persist($row);
                $removed++;
            }
        }

        $this->em->flush();
        $this->availability->forget($record);

        return ['added' => $added, 'removed' => $removed, 'total' => count($wanted)];
    }

    public function scheduleAction(
        ServerRequest $request,
        Response $response
    ): ResponseInterface {
        $station = $request->getStation();

        $scheduleItems = $this->em->createQuery(
            <<<'DQL'
                SELECT ssc, ssb
                FROM App\Entity\StationSchedule ssc
                JOIN ssc.smart_block ssb
                WHERE ssb.station = :station AND ssb.is_enabled = 1
            DQL
        )->setParameter('station', $station)
            ->execute();

        return $this->renderEvents(
            $request,
            $response,
            $scheduleItems,
            function (
                Station $station,
                StationSchedule $scheduleItem,
                DateRange $dateRange
            ) use ($request) {
                /** @var StationSmartBlock $smartBlock */
                $smartBlock = $scheduleItem->smart_block;

                return [
                    'id' => $scheduleItem->id . '_' . $dateRange->start->getTimestamp(),
                    'schedule_id' => $scheduleItem->id,
                    'smart_block_id' => $smartBlock->id,
                    'is_smart_block' => true,
                    'title' => $smartBlock->name,
                    'backgroundColor' => '#8b5cf6',
                    'start' => $dateRange->start->toIso8601String(),
                    'end' => $dateRange->end->toIso8601String(),
                    'edit_url' => $request->getRouter()->named(
                        'api:stations:smart-block',
                        ['station_id' => $station->id, 'id' => $smartBlock->id]
                    ),
                ];
            }
        );
    }

    protected function viewRecord(object $record, ServerRequest $request): mixed
    {
        assert($record instanceof StationSmartBlock);

        $matching = SmartBlockAvailability::applySortOrder(
            $this->criteriaRepo->getMatchingMedia($record),
            $record->sort_order
        );
        $memberCount = (SmartBlockType::Static === $record->type)
            ? count($this->availability->getPool($record))
            : count($matching);

        $preview = array_map(
            static fn(StationMedia $media) => [
                'id' => $media->id,
                'title' => $media->title,
                'artist' => $media->artist,
                'album' => $media->album,
                'genre' => $media->genre,
                'length' => $media->length,
            ],
            array_slice($matching, 0, self::PREVIEW_SAMPLE_SIZE)
        );

        $customFields = $this->em->createQueryBuilder()
            ->select(['cf.id', 'cf.name', 'cf.short_name'])
            ->from(CustomField::class, 'cf')
            ->orderBy('cf.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $scheduleOut = [];
        foreach ($this->scheduleRepo->findByRelation($record) as $scheduleItem) {
            $scheduleOut[] = $this->toArray(
                $scheduleItem,
                [
                    AbstractNormalizer::IGNORED_ATTRIBUTES => [
                        'clock_wheel',
                        'playlist',
                        'streamer',
                        'smart_block',
                    ],
                ]
            );
        }

        $router = $request->getRouter();
        $isInternal = $request->isInternal();

        return [
            'id' => $record->id,
            'name' => $record->name,
            'is_enabled' => $record->is_enabled,
            'weight' => $record->weight,
            'is_smart_block' => true,
            'smart_block_type' => $record->type->value,
            'smart_block_match_type' => $record->match_type->value,
            'smart_block_limit' => $record->limit,
            'smart_block_limit_type' => $record->limit_type->value,
            'smart_block_sort_order' => $record->sort_order->value,
            'smart_block_avoid_duplicates' => $record->avoid_duplicates,
            'criteria' => $record->criteria->toArray(),
            'current_member_count' => $memberCount,
            'matching_count' => count($matching),
            'matching_duration_seconds' => array_sum(
                array_map(static fn(StationMedia $media) => $media->length, $matching)
            ),
            'preview' => $preview,
            'available_custom_fields' => $customFields,
            'schedule_items' => $scheduleOut,
            'links' => [
                'self' => $router->fromHere(
                    routeName: $this->resourceRouteName,
                    routeParams: ['id' => $record->id],
                    absolute: !$isInternal
                ),
            ],
        ];
    }
}
