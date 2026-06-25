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
     * @param array<string, mixed> $criteria
     */
    public function findOneBy(array $criteria): ?AiDjSchedule
    {
        return $this->repository->findOneBy($criteria);
    }

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
        // Handle both normal (e.g. 06:00-17:00) and cross-midnight (e.g. 22:00-06:00) schedules.
        // For cross-midnight: start_time > end_time, so match when time >= start OR time < end.
        // For cross-midnight schedules the day check applies to the START day, so if it's past
        // midnight we also check the previous day.
        $previousDay = $dayOfWeek === 1 ? 7 : $dayOfWeek - 1;

        return $this->em->createQueryBuilder()
            ->select('schedule')
            ->from(AiDjSchedule::class, 'schedule')
            ->innerJoin('schedule.ai_dj', 'dj')
            ->andWhere('IDENTITY(dj.station) = :stationId')
            ->andWhere('schedule.is_enabled = :isEnabled')
            ->andWhere('dj.is_enabled = :isEnabled')
            ->andWhere(
                '(' .
                    // Normal schedule (same day): start <= time < end, day matches today
                    '(schedule.start_time <= schedule.end_time AND schedule.start_time <= :time AND schedule.end_time > :time AND schedule.loop_days LIKE :dayOfWeek)' .
                    ' OR ' .
                    // Cross-midnight: time >= start on the start day
                    '(schedule.start_time > schedule.end_time AND schedule.start_time <= :time AND schedule.loop_days LIKE :dayOfWeek)' .
                    ' OR ' .
                    // Cross-midnight: time < end (we are past midnight, started previous day)
                    '(schedule.start_time > schedule.end_time AND schedule.end_time > :time AND schedule.loop_days LIKE :previousDay)' .
                ')'
            )
            ->setParameter('stationId', $stationId)
            ->setParameter('isEnabled', true)
            ->setParameter('dayOfWeek', '%' . $dayOfWeek . '%')
            ->setParameter('previousDay', '%' . $previousDay . '%')
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

    /**
     * @return AiDjSchedule[]
     */
    public function findByDj(int $djId): array
    {
        return $this->em->createQueryBuilder()
            ->select('schedule')
            ->from(AiDjSchedule::class, 'schedule')
            ->andWhere('IDENTITY(schedule.ai_dj) = :djId')
            ->setParameter('djId', $djId)
            ->orderBy('schedule.start_time', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
