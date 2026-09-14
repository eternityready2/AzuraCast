<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;

final class AirCheckHistoryRetention
{
    use EntityManagerAwareTrait;

    public const int MAX_INTERVENTIONS = 20;

    public function trim(Station $station): void
    {
        $config = $station->backend_config;
        $history = array_values($config->aircheck_interventions);

        if (count($history) <= self::MAX_INTERVENTIONS) {
            return;
        }

        $needsRestartBefore = $station->needs_restart;
        $config->aircheck_interventions = array_slice($history, 0, self::MAX_INTERVENTIONS);
        $station->backend_config = $config;
        $station->needs_restart = $needsRestartBefore;

        $this->em->persist($station);
        $this->em->flush();
    }
}
