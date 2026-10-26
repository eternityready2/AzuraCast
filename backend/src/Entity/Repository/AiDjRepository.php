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

    public function findForStation(int $djId, int $stationId): ?AiDj
    {
        return $this->em->createQueryBuilder()
            ->select('dj')
            ->from(AiDj::class, 'dj')
            ->andWhere('dj.id = :id')
            ->andWhere('IDENTITY(dj.station) = :stationId')
            ->setParameter('id', $djId)
            ->setParameter('stationId', $stationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

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
