<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Station;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Message\BuildLinearLogMessage;
use App\Radio\AutoDJ\LinearLog\LinearLogPlayout;
use App\Radio\AutoDJ\LinearLog\LinearLogRules;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Messenger\MessageBus;
use Throwable;

/**
 * Run the operator override rules against the saved log on demand, so the human
 * can correct the plan immediately instead of waiting for the next build.
 */
final class LinearLogRulesAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly LinearLogRules $rules,
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly MessageBus $messageBus,
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

        // Re-plan so the holes the rules made are filled and every later line is
        // re-timed against them.
        if ($result['dropped'] > 0 && $station->backend_config->linear_log_rule_refill_dropped) {
            $this->refresh($station);
        }

        return $response->withJson([
            'success' => true,
            'checked' => $result['checked'],
            'dropped' => $result['dropped'],
            'skipped_locked' => $result['skipped_locked'],
            'reasons' => $result['reasons'],
            'rebuilding' => $result['dropped'] > 0 && $station->backend_config->linear_log_rule_refill_dropped,
        ]);
    }

    private function refresh(Station $station): void
    {
        $hours = $station->backend_config->linear_log_hours;
        $this->snapshotStore->markQueued($station, $hours);
        try {
            $this->messageBus->dispatch(new BuildLinearLogMessage($station->id, $hours, true, false));
        } catch (Throwable $e) {
            $this->snapshotStore->markFailed($station, $hours, $e->getMessage());
        }
    }
}
