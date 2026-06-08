<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Entity\AiDjSchedule;
use App\Doctrine\Repository;

/**
 * @extends Repository<AiDjSchedule>
 */
final class AiDjScheduleRepository extends Repository
{
    protected string $entityClass = AiDjSchedule::class;

    /**
     * @return AiDjSchedule[]
     */
    public function findByStation(int $stationId): array
    {
        return $this->em->createQueryBuilder()
            ->select('schedule')
            ->from(AiDjSchedule::class, 'schedule')
            ->innerJoin('schedule.ai_dj', 'dj')
            ->andWhere('IDENTITY(dj.station) = :stationId')
            ->setParameter('stationId', $stationId)
            ->orderBy('schedule.start_time', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveForTimeSlot(int $stationId, int $dayOfWeek, string $time): ?AiDjSchedule
    {
        return $this->em->createQueryBuilder()
            ->select('schedule')
            ->from(AiDjSchedule::class, 'schedule')
            ->innerJoin('schedule.ai_dj', 'dj')
            ->andWhere('IDENTITY(dj.station) = :stationId')
            ->andWhere('schedule.is_enabled = :isEnabled')
            ->andWhere('dj.is_enabled = :isEnabled')
            ->andWhere('schedule.loop_days LIKE :dayOfWeek')
            ->andWhere('schedule.start_time <= :time')
            ->andWhere('schedule.end_time > :time')
            ->setParameter('stationId', $stationId)
            ->setParameter('isEnabled', true)
            ->setParameter('dayOfWeek', '%'.$dayOfWeek.'%')
            ->setParameter('time', $time)
            ->orderBy('schedule.start_time', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(AiDjSchedule $schedule): void
    {
        $this->getEntityManager()->persist($schedule);
        $this->getEntityManager()->flush();
    }

    public function delete(AiDjSchedule $schedule): void
    {
        $this->getEntityManager()->remove($schedule);
        $this->getEntityManager()->flush();
    }

    public function hasOverlap(AiDjSchedule $schedule, bool $excludeSelf = false): bool
    {
        $qb = $this->em->createQueryBuilder()
            ->select('s')
            ->from(AiDjSchedule::class, 's')
            ->where('s.ai_dj = :aiDj')
            ->setParameter('aiDj', $schedule->getAiDj())
            ->andWhere('s.start_time < :endTime')
            ->setParameter('endTime', $schedule->getEndTime())
            ->andWhere('s.end_time > :startTime')
            ->setParameter('startTime', $schedule->getStartTime());

        if ($excludeSelf && null !== $schedule->id) {
            $qb->andWhere('s.id != :id')
                ->setParameter('id', $schedule->id);
        }

        $existingSchedules = $qb->getQuery()->getResult();

        $newDays = $schedule->getLoopDays();
        foreach ($existingSchedules as $existing) {
            if (!empty(array_intersect($newDays, $existing->getLoopDays()))) {
                return true;
            }
        }

        return false;
    }
}
