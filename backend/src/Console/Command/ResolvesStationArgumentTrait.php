<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Entity\Station;

/**
 * Shared station-argument resolution for the AI DJ and AI News console commands.
 */
trait ResolvesStationArgumentTrait
{
    /**
     * @return Station[]
     */
    private function resolveStations(?string $stationName): array
    {
        $repo = $this->em->getRepository(Station::class);

        if (null === $stationName) {
            return $repo->findAll();
        }

        $station = $repo->findOneBy(['short_name' => $stationName])
            ?? $repo->find($stationName);

        return (null !== $station) ? [$station] : [];
    }
}
