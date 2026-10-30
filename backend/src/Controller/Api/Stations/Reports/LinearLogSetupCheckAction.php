<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports;

use App\Container\EntityManagerAwareTrait;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\AutoDJ\LinearLog\LinearLogSetupCheck;
use App\Utilities\Types;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * The 24-hour log's setup check: GET lists the station's setup problems in
 * plain words; POST fixes one ({"id": ...}) or all of them ({"all": true}).
 */
final class LinearLogSetupCheckAction
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly LinearLogSetupCheck $setupCheck,
    ) {
    }

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $this->em->refetch($request->getStation());

        return $response->withJson([
            'issues' => $this->setupCheck->check($station),
        ]);
    }

    public function fixAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $this->em->refetch($request->getStation());
        $body = (array)$request->getParsedBody();

        try {
            $fixed = Types::bool($body['all'] ?? false)
                ? $this->setupCheck->fixAll($station)
                : (int)$this->setupCheck->fix($station, Types::string($body['id'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return $response->withStatus(400)->withJson(['success' => false, 'message' => $e->getMessage()]);
        }

        $station = $this->em->refetch($station);

        return $response->withJson([
            'success' => true,
            'fixed' => $fixed,
            'issues' => $this->setupCheck->check($station),
        ]);
    }
}
