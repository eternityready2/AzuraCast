<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\ClockWheels;

use App\Controller\Api\Stations\AbstractStationApiCrudController;
use App\Entity\Api\Error;
use App\Entity\StationClockDaypart;
use App\Entity\StationClockWheelTemplate;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\ClockWheel\ClockWheelInheritanceService;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Daypart definitions that materialize hourly clock wheel instances (PR10).
 *
 * @extends AbstractStationApiCrudController<StationClockDaypart>
 */
#[
    OA\Post(
        path: '/station/{station_id}/clock-daypart/{id}/sync',
        operationId: 'syncClockDaypart',
        summary: 'Create or update hourly clock wheels for this daypart.',
        tags: [OpenApi::TAG_STATIONS_CLOCK_WHEELS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OpenApi\Response\Success()],
    )
]
final class ClockWheelDaypartsController extends AbstractStationApiCrudController
{
    protected string $entityClass = StationClockDaypart::class;

    protected string $resourceRouteName = 'api:stations:clock-daypart';

    public function __construct(
        Serializer $serializer,
        ValidatorInterface $validator,
        private readonly ClockWheelInheritanceService $inheritanceService,
    ) {
        parent::__construct($serializer, $validator);
    }

    protected function createRecord(ServerRequest $request, array $data): object
    {
        $station = $request->getStation();
        $template = $this->resolveTemplate($station, $data);
        unset($data['template_id']);

        return $this->editRecord(
            $data,
            new StationClockDaypart($station, $template),
        );
    }

    protected function editRecord(?array $data, ?object $record = null, array $context = []): object
    {
        if (null === $data) {
            throw new InvalidArgumentException('Could not parse input data.');
        }

        if ($record instanceof StationClockDaypart && isset($data['template_id'])) {
            $record->template = $this->resolveTemplate($record->station, $data);
            unset($data['template_id']);
        }

        /** @var StationClockDaypart $daypart */
        $daypart = $this->fromArray($data, $record, $context);

        $errors = $this->validator->validate($daypart);
        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        $this->em->persist($daypart);
        $this->em->flush();

        $this->inheritanceService->syncDaypart($daypart);

        return $daypart;
    }

    public function syncAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $daypart = $this->getRecord($request, $params);
        if (null === $daypart) {
            return $response->withStatus(404)->withJson(Error::notFound());
        }

        $wheels = $this->inheritanceService->syncDaypart($daypart);

        return $response->withJson([
            'success' => true,
            'wheels' => array_map(fn ($w) => $this->toArray($w), $wheels),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveTemplate(\App\Entity\Station $station, array $data): StationClockWheelTemplate
    {
        $templateId = isset($data['template_id']) ? (int)$data['template_id'] : 0;
        if ($templateId <= 0) {
            throw new InvalidArgumentException('template_id is required.');
        }

        $template = $this->em->getRepository(StationClockWheelTemplate::class)->findOneBy([
            'station' => $station,
            'id' => $templateId,
        ]);

        if (!$template instanceof StationClockWheelTemplate) {
            throw new InvalidArgumentException('Template not found for this station.');
        }

        return $template;
    }
}
