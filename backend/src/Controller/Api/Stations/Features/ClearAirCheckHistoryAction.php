<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Features;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Http\Response;
use App\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;

final class ClearAirCheckHistoryAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $needsRestartBefore = $station->needs_restart;
        $config = $station->backend_config;

        $config->aircheck_interventions = [];
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;

        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(Status::updated());
    }
}
