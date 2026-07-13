<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Entity\AiDjContent;
use App\Doctrine\Repository;

/**
 * @extends Repository<AiDjContent>
 */
final class AiDjContentRepository extends Repository
{
    protected string $entityClass = AiDjContent::class;

    public function findForStation(string|int $id, \App\Entity\Station $station): ?AiDjContent
    {
        return $this->em->createQueryBuilder()
            ->select('content')
            ->from(AiDjContent::class, 'content')
            ->andWhere('content.id = :id')
            ->andWhere('IDENTITY(content.station) = :stationId')
            ->setParameter('id', $id)
            ->setParameter('stationId', $station->id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return AiDjContent[]
     */
    public function findByStation(int $stationId): array
    {
        return $this->em->createQueryBuilder()
            ->select('content')
            ->from(AiDjContent::class, 'content')
            ->andWhere('IDENTITY(content.station) = :stationId')
            ->setParameter('stationId', $stationId)
            ->orderBy('content.type', 'ASC')
            ->addOrderBy('content.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AiDjContent[]
     */
    public function findEnabledByType(int $stationId, string $type): array
    {
        return $this->em->createQueryBuilder()
            ->select('content')
            ->from(AiDjContent::class, 'content')
            ->andWhere('IDENTITY(content.station) = :stationId')
            ->andWhere('content.type = :type')
            ->andWhere('content.is_enabled = :isEnabled')
            ->setParameter('stationId', $stationId)
            ->setParameter('type', $type)
            ->setParameter('isEnabled', true)
            ->orderBy('content.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AiDjContent[]
     */
    public function findGlobalContent(string $type): array
    {
        return $this->em->createQueryBuilder()
            ->select('content')
            ->from(AiDjContent::class, 'content')
            ->andWhere('content.type = :type')
            ->andWhere('content.is_global = :isGlobal')
            ->setParameter('type', $type)
            ->setParameter('isGlobal', true)
            ->orderBy('content.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
