<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Controller\Api\Stations\AbstractStationApiCrudController;
use App\Entity\Api\Error;
use App\Entity\StationClockWheelTemplate;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\ClockWheel\ClockWheelInheritanceService;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * CRUD for reusable clock layouts (PR10 templates).
 *
 * @extends AbstractStationApiCrudController<StationClockWheelTemplate>
 */
#[
    OA\Get(
        path: '/station/{station_id}/clock-wheel-templates',
        operationId: 'getClockWheelTemplates',
        summary: 'List clock wheel templates for a station.',
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: StationClockWheelTemplate::class)
                )
            ),
        ]
    ),
    OA\Put(
        path: '/station/{station_id}/clock-wheel-template/{id}/slots',
        operationId: 'putClockWheelTemplateSlots',
        summary: 'Replace all slots on a template and propagate to inheriting wheels.',
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OpenApi\Response\Success()],
    )
]
final class ClockWheelTemplatesController extends AbstractStationApiCrudController
{
    protected string $entityClass = StationClockWheelTemplate::class;

    protected string $resourceRouteName = 'api:stations:clock-wheel-template';

    public function __construct(
        Serializer $serializer,
        ValidatorInterface $validator,
        private readonly ClockWheelInheritanceService $inheritanceService,
    ) {
        parent::__construct($serializer, $validator);
    }

    protected function editRecord(?array $data, ?object $record = null, array $context = []): object
    {
        if (null === $data) {
            throw new InvalidArgumentException('Could not parse input data.');
        }

        $slotsData = isset($data['slots']) && is_array($data['slots']) ? $data['slots'] : null;
        unset($data['slots']);

        /** @var StationClockWheelTemplate $template */
        $template = $this->fromArray($data, $record, $context);

        $errors = $this->validator->validate($template);
        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        $this->em->persist($template);

        if ($slotsData !== null) {
            $this->inheritanceService->saveTemplateSlotsAndPropagate($template, $slotsData);
        }

        $this->em->flush();

        $template->syncReadOnlyForeignKeys();
        foreach ($template->slots as $slot) {
            $slot->syncReadOnlyForeignKeys();
        }

        return $template;
    }

    public function getSlotsAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $record = $this->getRecord($request, $params);
        if (null === $record) {
            return $response->withStatus(404)->withJson(Error::notFound());
        }

        $slots = [];
        foreach ($record->slots as $slot) {
            $slots[] = $this->toArray($slot);
        }

        return $response->withJson($slots);
    }

    public function putSlotsAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $record = $this->getRecord($request, $params);
        if (null === $record) {
            return $response->withStatus(404)->withJson(Error::notFound());
        }

        $body = (array)$request->getParsedBody();
        $slotsData = array_key_exists('slots', $body) ? (array)$body['slots'] : $body;

        $this->inheritanceService->saveTemplateSlotsAndPropagate($record, $slotsData);

        $errors = $this->validator->validate($record);
        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        $this->em->flush();

        $savedSlots = [];
        foreach ($record->slots as $slot) {
            $slot->syncReadOnlyForeignKeys();
            $savedSlots[] = $this->toArray(
                $slot,
                [
                    AbstractNormalizer::IGNORED_ATTRIBUTES => ['template'],
                ]
            );
        }

        return $response->withJson($savedSlots);
    }

    protected function viewRecord(object $record, ServerRequest $request): mixed
    {
        assert($record instanceof StationClockWheelTemplate);

        $return = $this->toArray($record);

        $slotsOut = [];
        foreach ($record->slots as $slot) {
            $slotsOut[] = $this->toArray(
                $slot,
                [
                    AbstractNormalizer::IGNORED_ATTRIBUTES => ['template'],
                ]
            );
        }
        $return['slots'] = $slotsOut;

        $router = $request->getRouter();
        $isInternal = $request->isInternal();

        $return['links'] = [
            'self' => $router->fromHere(
                routeName: $this->resourceRouteName,
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            ),
            'slots' => $router->fromHere(
                routeName: 'api:stations:clock-wheel-template',
                routeParams: ['id' => $record->id],
                absolute: !$isInternal
            ) . '/slots',
        ];

        return $return;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<mixed>
     */
    protected function toArray(object $record, array $context = []): array
    {
        return parent::toArray(
            $record,
            array_merge(
                $context,
                [
                    AbstractNormalizer::IGNORED_ATTRIBUTES => ['slots', 'dayparts', 'wheels'],
                ]
            )
        );
    }
}
