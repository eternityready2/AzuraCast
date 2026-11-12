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
     * Enabled items of one type from a DJ's own library.
     *
     * @return AiDjContent[]
     */
    public function findEnabledForDj(int $djId, string $type): array
    {
        return $this->em->createQueryBuilder()
            ->select('content')
            ->from(AiDjContent::class, 'content')
            ->innerJoin('content.ai_djs', 'dj')
            ->andWhere('dj.id = :djId')
            ->andWhere('content.type = :type')
            ->andWhere('content.is_enabled = :isEnabled')
            ->setParameter('djId', $djId)
            ->setParameter('type', $type)
            ->setParameter('isEnabled', true)
            ->orderBy('content.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether the DJ has any items in their own library (then they use only that library).
     * The DJ's own break wording (dj_line) does not count.
     */
    public function djHasOwnLibrary(int $djId): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM ai_dj_has_content h JOIN ai_dj_content c ON c.id = h.ai_dj_content_id'
            . ' WHERE h.ai_dj_id = :djId AND c.type <> :lineType LIMIT 1',
            ['djId' => $djId, 'lineType' => AiDjContent::TYPE_DJ_LINE]
        );
    }

    /** Whether the song is in one of the station's playlists with "hymn" in its name. */
    public function isHymn(int $stationId, ?string $artist, string $title): bool
    {
        $sql = <<<'SQL'
            SELECT 1 FROM station_media m
            INNER JOIN station_playlist_media spm ON spm.media_id = m.id
            INNER JOIN station_playlists p ON p.id = spm.playlist_id
            WHERE p.station_id = :stationId AND p.name LIKE '%hymn%' AND m.title = :title
            SQL;
        $params = ['stationId' => $stationId, 'title' => $title];
        if (null !== $artist && '' !== $artist) {
            $sql .= ' AND m.artist = :artist';
            $params['artist'] = $artist;
        }

        return false !== $this->em->getConnection()->fetchOne($sql . ' LIMIT 1', $params);
    }

    /**
     * Which DJ libraries each of the station's items belongs to, in one query.
     *
     * @return array<int, int[]> content id => DJ ids
     */
    public function getDjIdsByContent(int $stationId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT h.ai_dj_content_id, h.ai_dj_id
                FROM ai_dj_has_content h
                INNER JOIN ai_dj d ON d.id = h.ai_dj_id
                WHERE d.station_id = :stationId
            SQL,
            ['stationId' => $stationId]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['ai_dj_content_id']][] = (int)$row['ai_dj_id'];
        }

        return $map;
    }

    /**
     * Enabled items of one type from the station's shared library (not in any DJ's own library).
     *
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
            ->andWhere('content.ai_djs IS EMPTY')
            ->setParameter('stationId', $stationId)
            ->setParameter('type', $type)
            ->setParameter('isEnabled', true)
            ->orderBy('content.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Global shared items (not in any DJ's own library).
     *
     * @return AiDjContent[]
     */
    public function findGlobalContent(string $type): array
    {
        return $this->em->createQueryBuilder()
            ->select('content')
            ->from(AiDjContent::class, 'content')
            ->andWhere('content.type = :type')
            ->andWhere('content.is_global = :isGlobal')
            ->andWhere('content.ai_djs IS EMPTY')
            ->setParameter('type', $type)
            ->setParameter('isGlobal', true)
            ->orderBy('content.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
