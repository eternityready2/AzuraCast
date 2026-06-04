<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Doctrine\Repository;
use App\Entity\ClockWheelEvent;
use App\Entity\Enums\ClockWheelEventKind;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use DateTimeImmutable;

/**
 * @extends Repository<ClockWheelEvent>
 */
final class ClockWheelEventRepository extends Repository
{
    public const int DEFAULT_RETENTION_DAYS = 30;

    protected string $entityClass = ClockWheelEvent::class;

    public function deleteOlderThan(Station $station, int $days = self::DEFAULT_RETENTION_DAYS): int
    {
        $cutoff = new DateTimeImmutable('-' . $days . ' days', $station->getTimezoneObject());

        return (int)$this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp < :cutoff
            DQL
        )->setParameter('station', $station)
            ->setParameter('cutoff', $cutoff)
            ->execute();
    }

    /**
     * @return array{
     *     tracks_queued: int,
     *     deferred: int,
     *     fallbacks: int,
     *     avg_drift: float|null,
     *     separation_relaxed: int,
     *     burn_rate_warning: int,
     *     fallback_reasons: array<string, int>
     * }
     */
    public function getAnalyticsSummary(StationClockWheel $wheel, DateTimeImmutable $since): array
    {
        $tracksQueued = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.clock_wheel = :wheel AND e.event_timestamp >= :since
                AND e.event_kind = :kind
            DQL
        )->setParameter('wheel', $wheel)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::TrackQueued)
            ->getSingleScalarResult();

        $deferred = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.clock_wheel = :wheel AND e.event_timestamp >= :since
                AND e.event_kind = :kind
            DQL
        )->setParameter('wheel', $wheel)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::Deferred)
            ->getSingleScalarResult();

        $fallbacks = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.clock_wheel = :wheel AND e.event_timestamp >= :since
                AND e.event_kind = :kind
            DQL
        )->setParameter('wheel', $wheel)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::Fallback)
            ->getSingleScalarResult();

        $avgDrift = $this->em->createQuery(
            <<<'DQL'
                SELECT AVG(ABS(e.drift_seconds)) FROM App\Entity\ClockWheelEvent e
                WHERE e.clock_wheel = :wheel AND e.event_timestamp >= :since
                AND e.event_kind = :kind AND e.drift_seconds IS NOT NULL
            DQL
        )->setParameter('wheel', $wheel)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::TrackQueued)
            ->getSingleScalarResult();

        $separationRelaxed = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.clock_wheel = :wheel AND e.event_timestamp >= :since
                AND e.separation_relaxed = 1
            DQL
        )->setParameter('wheel', $wheel)
            ->setParameter('since', $since)
            ->getSingleScalarResult();

        $burnRateWarning = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.clock_wheel = :wheel AND e.event_timestamp >= :since
                AND e.burn_rate_warning = 1
            DQL
        )->setParameter('wheel', $wheel)
            ->setParameter('since', $since)
            ->getSingleScalarResult();

        /** @var array<array{reason: string|null, cnt: string}> $reasonRows */
        $reasonRows = $this->em->createQuery(
            <<<'DQL'
                SELECT e.fallback_reason AS reason, COUNT(e.id) AS cnt
                FROM App\Entity\ClockWheelEvent e
                WHERE e.clock_wheel = :wheel AND e.event_timestamp >= :since
                AND e.event_kind = :kind AND e.fallback_reason IS NOT NULL
                GROUP BY e.fallback_reason
            DQL
        )->setParameter('wheel', $wheel)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::Fallback)
            ->getArrayResult();

        $fallbackReasons = [];
        foreach ($reasonRows as $row) {
            if ($row['reason'] !== null) {
                $fallbackReasons[$row['reason']] = (int)$row['cnt'];
            }
        }

        return [
            'tracks_queued' => $tracksQueued,
            'deferred' => $deferred,
            'fallbacks' => $fallbacks,
            'avg_drift' => is_numeric($avgDrift) ? round((float)$avgDrift, 1) : null,
            'separation_relaxed' => $separationRelaxed,
            'burn_rate_warning' => $burnRateWarning,
            'fallback_reasons' => $fallbackReasons,
        ];
    }
}
