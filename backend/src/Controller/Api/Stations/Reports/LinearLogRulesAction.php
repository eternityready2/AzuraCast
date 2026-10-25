<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\AutoDJ\LinearLog\LinearLogPlayout;
use App\Radio\AutoDJ\LinearLog\LinearLogRefill;
use App\Radio\AutoDJ\LinearLog\LinearLogRules;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * Run the operator override rules against the saved log on demand, so the human
 * can correct the plan immediately instead of waiting for the next build.
 */
final class LinearLogRulesAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly LinearLogRules $rules,
        private readonly LinearLogRefill $refill,
    ) {
    }

    /** @param array<string, string> $params */
    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $this->em->refetch($request->getStation());
        if (!LinearLogPlayout::isPlayoutEnabled($station)) {
            throw new InvalidArgumentException('Turn the playout log on before applying rules to it.');
        }

        $result = $this->rules->apply($station);

        // Refill the slots the rules emptied, each in place with a song of about
        // the same length. A full rebuild used to run here, re-planning every
        // unlocked hour past the lock window for a handful of dropped lines.
        $refilled = $result['dropped'] > 0 ? $this->refill->refill($station) : 0;

        return $response->withJson([
            'success' => true,
            'checked' => $result['checked'],
            'dropped' => $result['dropped'],
            'skipped_locked' => $result['skipped_locked'],
            'reasons' => $result['reasons'],
            'refilled' => $refilled,
            'rebuilding' => false,
        ]);
    }
}
