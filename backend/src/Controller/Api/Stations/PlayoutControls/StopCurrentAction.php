<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\PlayoutControls;

use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Entity\Repository\StationQueueRepository;
use App\Exception\Supervisor\NotRunningException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\Adapters;
use Psr\Http\Message\ResponseInterface;

final class StopCurrentAction implements SingleActionInterface
{
    public function __construct(
        private readonly Adapters $adapters,
        private readonly StationQueueRepository $queueRepo,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $body = (array)$request->getParsedBody();
        $hardReset = (bool)($body['hard_reset'] ?? false);

        $backend = $this->adapters->requireBackendAdapter($station);

        // Discard unsent ordinary AutoDJ rows before changing what is on air.
        // Station-wide top-of-hour legal IDs are intentionally retained by this method.
        $this->queueRepo->clearUpcomingQueue($station);

        if (!$hardReset) {
            $backend->skip($station);

            return $response->withJson(
                new Status(true, __('Current item skipped. The unsent AutoDJ queue was rebuilt from current rules.'))
            );
        }

        // A backend restart also drops any already-buffered Liquidsoap request queues,
        // which is the reliable escape hatch when bad scheduled/manual content is stuck.
        try {
            $backend->stop($station);
        } catch (NotRunningException) {
        }

        $backend->write($station);
        $backend->start($station);

        return $response->withJson(
            new Status(true, __('Emergency playout reset complete. Liquidsoap restarted with a fresh queue.'))
        );
    }
}
