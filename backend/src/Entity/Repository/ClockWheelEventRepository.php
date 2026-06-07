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

    public function findLatestUnplayedLegalIdQueued(
        StationClockWheel $wheel,
        int $queueId,
    ): ?ClockWheelEvent {
        return $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\ClockWheelEvent e
                WHERE e.clock_wheel = :wheel
                AND e.event_kind = :kind
                AND e.anchor_type = :anchor
                AND e.actual_play_at IS NULL
                AND e.station_queue_id = :queueId
                ORDER BY e.id DESC
            DQL
        )->setParameter('wheel', $wheel)
            ->setParameter('kind', ClockWheelEventKind::TrackQueued)
            ->setParameter('anchor', 'legal_id')
            ->setParameter('queueId', $queueId)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /**
     * @return array{
     *     tolerance_seconds: int,
     *     hours_with_legal_id: int,
     *     on_time_count: int,
     *     late_count: int,
     *     compliance_percent: float|null,
     *     late_events: array<int, array{
     *         expected_play_at: string,
     *         actual_play_at: string|null,
     *         drift_seconds: int|null,
     *         media_id: int|null
     *     }>
     * }
     */
    public function getLegalIdComplianceSummary(
        StationClockWheel $wheel,
        DateTimeImmutable $since,
        int $toleranceSeconds = 10,
    ): array {
        /** @var ClockWheelEvent[] $events */
        $events = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\ClockWheelEvent e
                WHERE e.clock_wheel = :wheel
                AND e.event_timestamp >= :since
                AND e.anchor_type = :anchor
                AND e.event_kind = :kind
                AND e.actual_play_at IS NOT NULL
                ORDER BY e.actual_play_at DESC
            DQL
        )->setParameter('wheel', $wheel)
            ->setParameter('since', $since)
            ->setParameter('anchor', 'legal_id')
            ->setParameter('kind', ClockWheelEventKind::TrackQueued)
            ->getResult();

        $onTime = 0;
        $late = 0;
        $lateEvents = [];

        foreach ($events as $event) {
            $drift = $event->drift_seconds ?? 0;
            if (abs($drift) <= $toleranceSeconds) {
                $onTime++;
            } else {
                $late++;
                if (count($lateEvents) < 50) {
                    $lateEvents[] = [
                        'expected_play_at' => $event->expected_play_at?->format(DateTimeImmutable::ATOM) ?? '',
                        'actual_play_at' => $event->actual_play_at?->format(DateTimeImmutable::ATOM),
                        'drift_seconds' => $event->drift_seconds,
                        'media_id' => $event->media_id,
                    ];
                }
            }
        }

        $total = count($events);

        return [
            'tolerance_seconds' => $toleranceSeconds,
            'hours_with_legal_id' => $total,
            'on_time_count' => $onTime,
            'late_count' => $late,
            'compliance_percent' => $total > 0
                ? round(($onTime / $total) * 100, 1)
                : null,
            'late_events' => $lateEvents,
        ];
    }
}
