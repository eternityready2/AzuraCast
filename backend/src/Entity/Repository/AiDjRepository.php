<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Entity\AiDj;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiDj>
 */
final class AiDjRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiDj::class);
    }

    /**
     * @return AiDj[]
     */
    public function findByStation(int $stationId): array
    {
        return $this->createQueryBuilder('dj')
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
        return $this->createQueryBuilder('dj')
            ->andWhere('IDENTITY(dj.station) = :stationId')
            ->andWhere('dj.is_enabled = :isEnabled')
            ->setParameter('stationId', $stationId)
            ->setParameter('isEnabled', true)
            ->orderBy('dj.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
