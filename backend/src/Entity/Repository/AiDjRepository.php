<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Entity\AiDj;
use App\Doctrine\Repository;

/**
 * @extends Repository<AiDj>
 */
final class AiDjRepository extends Repository
{
    protected string $entityClass = AiDj::class;

    /**
     * @return AiDj[]
     */
    public function findByStation(int $stationId): array
    {
        return $this->em->createQueryBuilder()
            ->select('dj')
            ->from(AiDj::class, 'dj')
            ->andWhere('IDENTITY(dj.station) = :stationId')
            ->setParameter('stationId', $stationId)
            ->orderBy('dj.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AiDj[]
     */
    public function findEnabledByStation(int $stationId): array
    {
        return $this->em->createQueryBuilder()
            ->select('dj')
            ->from(AiDj::class, 'dj')
            ->andWhere('IDENTITY(dj.station) = :stationId')
            ->andWhere('dj.is_enabled = :isEnabled')
            ->setParameter('stationId', $stationId)
            ->setParameter('isEnabled', true)
            ->orderBy('dj.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
