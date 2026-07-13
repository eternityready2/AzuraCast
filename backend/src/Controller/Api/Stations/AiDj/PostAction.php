<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDj;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[OA\Post(
    path: '/station/{station_id}/ai-dj',
    operationId: 'createStationAiDj',
    summary: 'Create a new AI DJ profile.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class PostAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $body = (array)$request->getParsedBody();

        $dj = new \App\Entity\AiDj();
        $dj->setStation($station);
        $dj->setName((string)($body['name'] ?? ''));
        $dj->setIsEnabled((bool)($body['is_enabled'] ?? true));
        $dj->setVoiceModelPath(isset($body['voice_model_path']) ? (string)$body['voice_model_path'] : null);
        $dj->setShiftIntroTemplate(isset($body['shift_intro_template']) ? (string)$body['shift_intro_template'] : null);
        $dj->setShiftOutroTemplate(isset($body['shift_outro_template']) ? (string)$body['shift_outro_template'] : null);
        if (isset($body['talk_frequency'])) {
            $dj->setTalkFrequency((float)$body['talk_frequency']);
        }
        if (isset($body['voice_speed'])) {
            $dj->setVoiceSpeed((float)$body['voice_speed']);
        }
        if (array_key_exists('use_background_audio', $body)) {
            $dj->setUseBackgroundAudio((bool)$body['use_background_audio']);
        }

        $errors = $this->validator->validate($dj);
        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        $this->em->persist($dj);
        $this->em->flush();

        return $response->withJson(['id' => $dj->id]);
    }
}
