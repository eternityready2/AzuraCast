<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Doctrine\Repository;
use App\Entity\ClockWheelEvent;
use App\Entity\Enums\ClockWheelEventKind;
use App\Entity\Enums\ClockWheelFallbackReason;
use BackedEnum;
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

        /** @var array<array{reason: ClockWheelFallbackReason|string|null, cnt: string}> $reasonRows */
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
            $reason = $row['reason'] ?? null;
            if ($reason === null) {
                continue;
            }

            $key = $reason instanceof BackedEnum ? $reason->value : (string)$reason;
            $fallbackReasons[$key] = (int)$row['cnt'];
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
    public function getStationAnalyticsSummary(Station $station, DateTimeImmutable $since): array
    {
        $tracksQueued = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp >= :since
                AND e.event_kind = :kind
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::TrackQueued)
            ->getSingleScalarResult();

        $deferred = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp >= :since
                AND e.event_kind = :kind
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::Deferred)
            ->getSingleScalarResult();

        $fallbacks = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp >= :since
                AND e.event_kind = :kind
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::Fallback)
            ->getSingleScalarResult();

        $avgDrift = $this->em->createQuery(
            <<<'DQL'
                SELECT AVG(ABS(e.drift_seconds)) FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp >= :since
                AND e.event_kind = :kind AND e.drift_seconds IS NOT NULL
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::TrackQueued)
            ->getSingleScalarResult();

        $separationRelaxed = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp >= :since
                AND e.separation_relaxed = 1
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->getSingleScalarResult();

        $burnRateWarning = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp >= :since
                AND e.burn_rate_warning = 1
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->getSingleScalarResult();

        /** @var array<array{reason: ClockWheelFallbackReason|string|null, cnt: string}> $reasonRows */
        $reasonRows = $this->em->createQuery(
            <<<'DQL'
                SELECT e.fallback_reason AS reason, COUNT(e.id) AS cnt
                FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp >= :since
                AND e.event_kind = :kind AND e.fallback_reason IS NOT NULL
                GROUP BY e.fallback_reason
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::Fallback)
            ->getArrayResult();

        $fallbackReasons = [];
        foreach ($reasonRows as $row) {
            $reason = $row['reason'] ?? null;
            if ($reason === null) {
                continue;
            }

            $key = $reason instanceof BackedEnum ? $reason->value : (string)$reason;
            $fallbackReasons[$key] = (int)$row['cnt'];
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

    /**
     * @return array<int, array{
     *     wheel_id: int|null,
     *     name: string,
     *     tracks_queued: int,
     *     fallbacks: int,
     *     deferred: int
     * }>
     */
    public function getStationAnalyticsByWheel(Station $station, DateTimeImmutable $since): array
    {
        /** @var array<array{wheel_id: int|null, cnt: string}> $queuedRows */
        $queuedRows = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(e.clock_wheel) AS wheel_id, COUNT(e.id) AS cnt
                FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp >= :since
                AND e.event_kind = :kind
                GROUP BY e.clock_wheel
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::TrackQueued)
            ->getArrayResult();

        /** @var array<array{wheel_id: int|null, cnt: string}> $fallbackRows */
        $fallbackRows = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(e.clock_wheel) AS wheel_id, COUNT(e.id) AS cnt
                FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp >= :since
                AND e.event_kind = :kind
                GROUP BY e.clock_wheel
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::Fallback)
            ->getArrayResult();

        /** @var array<array{wheel_id: int|null, cnt: string}> $deferredRows */
        $deferredRows = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(e.clock_wheel) AS wheel_id, COUNT(e.id) AS cnt
                FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp >= :since
                AND e.event_kind = :kind
                GROUP BY e.clock_wheel
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('kind', ClockWheelEventKind::Deferred)
            ->getArrayResult();

        $wheelIds = [];
        foreach ([$queuedRows, $fallbackRows, $deferredRows] as $rows) {
            foreach ($rows as $row) {
                if (null !== $row['wheel_id']) {
                    $wheelIds[(int)$row['wheel_id']] = true;
                }
            }
        }

        $wheelNames = [];
        if ($wheelIds !== []) {
            /** @var array<array{id: int, name: string}> $wheels */
            $wheels = $this->em->createQuery(
                <<<'DQL'
                    SELECT w.id, w.name FROM App\Entity\StationClockWheel w
                    WHERE w.id IN (:ids)
                DQL
            )->setParameter('ids', array_keys($wheelIds))
                ->getArrayResult();

            foreach ($wheels as $wheel) {
                $wheelNames[(int)$wheel['id']] = $wheel['name'];
            }
        }

        $indexed = [];

        foreach ($queuedRows as $row) {
            $wheelId = null !== $row['wheel_id'] ? (int)$row['wheel_id'] : null;
            $indexed[$wheelId ?? 0] ??= [
                'wheel_id' => $wheelId,
                'name' => $this->resolveWheelDisplayName($wheelId, $wheelNames),
                'tracks_queued' => 0,
                'fallbacks' => 0,
                'deferred' => 0,
            ];
            $indexed[$wheelId ?? 0]['tracks_queued'] = (int)$row['cnt'];
        }

        foreach ($fallbackRows as $row) {
            $wheelId = null !== $row['wheel_id'] ? (int)$row['wheel_id'] : null;
            $indexed[$wheelId ?? 0] ??= [
                'wheel_id' => $wheelId,
                'name' => $this->resolveWheelDisplayName($wheelId, $wheelNames),
                'tracks_queued' => 0,
                'fallbacks' => 0,
                'deferred' => 0,
            ];
            $indexed[$wheelId ?? 0]['fallbacks'] = (int)$row['cnt'];
        }

        foreach ($deferredRows as $row) {
            $wheelId = null !== $row['wheel_id'] ? (int)$row['wheel_id'] : null;
            $indexed[$wheelId ?? 0] ??= [
                'wheel_id' => $wheelId,
                'name' => $this->resolveWheelDisplayName($wheelId, $wheelNames),
                'tracks_queued' => 0,
                'fallbacks' => 0,
                'deferred' => 0,
            ];
            $indexed[$wheelId ?? 0]['deferred'] = (int)$row['cnt'];
        }

        $result = array_values($indexed);
        usort(
            $result,
            static fn (array $a, array $b): int => $b['tracks_queued'] <=> $a['tracks_queued'],
        );

        return $result;
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
     *     }>,
     *     fallback_count: int
     * }
     */
    public function getStationLegalIdComplianceSummary(
        Station $station,
        DateTimeImmutable $since,
        int $toleranceSeconds = 10,
    ): array {
        $compliance = $this->getLegalIdComplianceForQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station
                AND e.event_timestamp >= :since
                AND e.anchor_type = :anchor
                AND e.event_kind = :kind
                AND e.actual_play_at IS NOT NULL
                ORDER BY e.actual_play_at DESC
            DQL,
            [
                'station' => $station,
                'since' => $since,
                'anchor' => 'legal_id',
                'kind' => ClockWheelEventKind::TrackQueued,
            ],
            $toleranceSeconds,
        );

        $fallbackCount = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station
                AND e.event_timestamp >= :since
                AND e.anchor_type = :anchor
                AND e.event_kind = :kind
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('anchor', 'legal_id')
            ->setParameter('kind', ClockWheelEventKind::Fallback)
            ->getSingleScalarResult();

        $compliance['fallback_count'] = $fallbackCount;

        return $compliance;
    }

    /**
     * @param array<int, string> $wheelNames
     */
    private function resolveWheelDisplayName(?int $wheelId, array $wheelNames): string
    {
        if (null === $wheelId) {
            return __('Top of Hour (station-wide)');
        }

        return $wheelNames[$wheelId] ?? sprintf(__('Clock Wheel #%d'), $wheelId);
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

    public function findLatestUnplayedTopOfHourLegalIdQueued(
        Station $station,
        int $queueId,
    ): ?ClockWheelEvent {
        return $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station
                AND e.clock_wheel IS NULL
                AND e.event_kind = :kind
                AND e.anchor_type = :anchor
                AND e.actual_play_at IS NULL
                AND e.station_queue_id = :queueId
                ORDER BY e.id DESC
            DQL
        )->setParameter('station', $station)
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
     *     }>,
     *     fallback_count: int
     * }
     */
    public function getStationTopOfHourLegalIdComplianceSummary(
        Station $station,
        DateTimeImmutable $since,
        int $toleranceSeconds = 10,
    ): array {
        $compliance = $this->getLegalIdComplianceForQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station
                AND e.clock_wheel IS NULL
                AND e.event_timestamp >= :since
                AND e.anchor_type = :anchor
                AND e.event_kind = :kind
                AND e.actual_play_at IS NOT NULL
                ORDER BY e.actual_play_at DESC
            DQL,
            [
                'station' => $station,
                'since' => $since,
                'anchor' => 'legal_id',
                'kind' => ClockWheelEventKind::TrackQueued,
            ],
            $toleranceSeconds,
        );

        $fallbackCount = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station
                AND e.clock_wheel IS NULL
                AND e.event_timestamp >= :since
                AND e.anchor_type = :anchor
                AND e.event_kind = :kind
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('anchor', 'legal_id')
            ->setParameter('kind', ClockWheelEventKind::Fallback)
            ->getSingleScalarResult();

        $compliance['fallback_count'] = $fallbackCount;

        return $compliance;
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
        return $this->getLegalIdComplianceForQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\ClockWheelEvent e
                WHERE e.clock_wheel = :wheel
                AND e.event_timestamp >= :since
                AND e.anchor_type = :anchor
                AND e.event_kind = :kind
                AND e.actual_play_at IS NOT NULL
                ORDER BY e.actual_play_at DESC
            DQL,
            [
                'wheel' => $wheel,
                'since' => $since,
                'anchor' => 'legal_id',
                'kind' => ClockWheelEventKind::TrackQueued,
            ],
            $toleranceSeconds,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     *
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
    private function getLegalIdComplianceForQuery(
        string $dql,
        array $parameters,
        int $toleranceSeconds,
    ): array {
        $query = $this->em->createQuery($dql);
        foreach ($parameters as $key => $value) {
            $query->setParameter($key, $value);
        }

        /** @var ClockWheelEvent[] $events */
        $events = $query->getResult();

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
