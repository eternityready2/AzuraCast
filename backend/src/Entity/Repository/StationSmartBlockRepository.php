<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Entity\Station;
use App\Entity\StationSmartBlock;

/**
 * @extends AbstractStationBasedRepository<StationSmartBlock>
 */
final class StationSmartBlockRepository extends AbstractStationBasedRepository
{
    protected string $entityClass = StationSmartBlock::class;

    /** @return StationSmartBlock[] */
    public function getAllForStation(Station $station): array
    {
        return $this->em->createQuery(
            <<<'DQL'
                SELECT sb FROM App\Entity\StationSmartBlock sb
                WHERE sb.station = :station
                ORDER BY sb.name ASC
            DQL
        )->setParameter('station', $station)
            ->getResult();
    }
}
