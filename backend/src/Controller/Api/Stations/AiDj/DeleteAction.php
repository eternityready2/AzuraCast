<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Entity\Repository\AiDjRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Delete(
    path: '/station/{station_id}/ai-dj/{id}',
    operationId: 'deleteStationAiDj',
    summary: 'Delete an AI DJ profile.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'id',
            description: 'AI DJ ID',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'integer')
        ),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
    ]
)]
final class DeleteAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly AiDjRepository $aiDjRepository,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $djId = (int)($params['id'] ?? 0);

        $dj = $this->aiDjRepository->find($djId);

        if (null === $dj || $dj->getStationId() !== $station->id) {
            return $response->withStatus(404)->withJson([
                'error' => 'AI DJ not found.',
            ]);
        }

        $this->em->remove($dj);
        $this->em->flush();

        return $response->withJson(Status::deleted());
    }
}
