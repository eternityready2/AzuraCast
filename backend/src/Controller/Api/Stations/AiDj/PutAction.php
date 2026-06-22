<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Entity\Repository\AiDjRepository;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[OA\Put(
    path: '/station/{station_id}/ai-dj/{id}',
    operationId: 'updateStationAiDj',
    summary: 'Update an AI DJ profile.',
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
        new OpenApi\Response\GenericError(),
    ]
)]
final class PutAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly AiDjRepository $aiDjRepository,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $djId = (int)($params['id'] ?? 0);
        $body = (array)$request->getParsedBody();

        $dj = $this->aiDjRepository->find($djId);

        if (null === $dj || $dj->getStationId() !== $station->id) {
            return $response->withStatus(404)->withJson([
                'error' => 'AI DJ not found.',
            ]);
        }

        if (isset($body['name'])) {
            $dj->setName((string)$body['name']);
        }
        if (array_key_exists('is_enabled', $body)) {
            $dj->setIsEnabled((bool)$body['is_enabled']);
        }
        if (array_key_exists('voice_model_path', $body)) {
            $dj->setVoiceModelPath($body['voice_model_path'] !== null ? (string)$body['voice_model_path'] : null);
        }
        if (array_key_exists('shift_intro_template', $body)) {
            $dj->setShiftIntroTemplate($body['shift_intro_template'] !== null ? (string)$body['shift_intro_template'] : null);
        }
        if (array_key_exists('shift_outro_template', $body)) {
            $dj->setShiftOutroTemplate($body['shift_outro_template'] !== null ? (string)$body['shift_outro_template'] : null);
        }
        if (isset($body['talk_frequency'])) {
            $dj->setTalkFrequency((float)$body['talk_frequency']);
        }

        $errors = $this->validator->validate($dj);
        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        $this->em->persist($dj);
        $this->em->flush();

        return $response->withJson(Status::updated());
    }
}
