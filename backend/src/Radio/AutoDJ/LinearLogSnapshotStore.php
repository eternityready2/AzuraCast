<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;
use App\Entity\StationLinearLogSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Psr\SimpleCache\CacheInterface;

final class LinearLogSnapshotStore
{
    private const int CACHE_TTL = 172800;

    /**
     * A build queued or started this long ago that never reported back is not
     * running any more. A build takes under a minute and waits at most 20
     * minutes for another one to finish.
     */
    private const int STALE_BUILD_SECONDS = 1800;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Station $station): array
    {
        $snapshot = $this->cache->get($this->getKey($station));
        if (is_array($snapshot)) {
            return $this->settleStaleBuild([
                ...$this->emptySnapshot($station),
                ...$snapshot,
            ]);
        }

        $persistent = $this->loadPersistent($station);
        if (is_array($persistent)) {
            $this->saveCache($station, $persistent);

            return [
                ...$this->emptySnapshot($station),
                ...$persistent,
            ];
        }

        return $this->emptySnapshot($station);
    }

    /**
     * A build whose worker was restarted under it (a deploy), or whose request
     * was lost, never marks itself finished or failed. Left "queued" or
     * "building", the page kept its spinner and held every edit button
     * disabled until the next daily build. It is reported as the failed build
     * it is, which also lets the hourly check start a new one.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function settleStaleBuild(array $snapshot): array
    {
        $since = match ($snapshot['status']) {
            'queued' => $snapshot['requested_at'],
            'building' => $snapshot['started_at'],
            default => null,
        };

        if (null === $since || time() - (int)$since < self::STALE_BUILD_SECONDS) {
            return $snapshot;
        }

        $snapshot['status'] = 'failed';
        $snapshot['failed_at'] = (int)$since + self::STALE_BUILD_SECONDS;
        $snapshot['error'] = 'The build did not finish: its background worker was restarted or the request '
            . 'was lost. The saved log still drives playout; the next hourly check builds again.';

        return $snapshot;
    }

    public function markQueued(Station $station, int $hours): void
    {
        $snapshot = $this->get($station);
        $snapshot['status'] = 'queued';
        $snapshot['hours'] = $hours;
        $snapshot['requested_at'] = time();
        $snapshot['held_until'] = null;
        $snapshot['error'] = null;

        $this->saveCache($station, $snapshot);
    }

    /**
     * Keep a build that was asked for while the top of the hour settles (see
     * LinearLogBuilder::secondsUntilClearOfTopOfHour()). One request is kept
     * per station; a second one widens it.
     */
    public function holdBuild(
        Station $station,
        int $hours,
        bool $rebuild,
        bool $throughTomorrow,
        bool $replanLockWindow,
        ?int $startsAt = null,
    ): void {
        $held = $this->cache->get($this->getHeldKey($station));
        $held = is_array($held) ? $held : [];

        $this->cache->set($this->getHeldKey($station), [
            'hours' => max($hours, (int)($held['hours'] ?? 0)),
            'rebuild' => $rebuild || ($held['rebuild'] ?? false),
            'through_tomorrow' => $throughTomorrow || ($held['through_tomorrow'] ?? false),
            'replan_lock_window' => $replanLockWindow || ($held['replan_lock_window'] ?? false),
        ], self::STALE_BUILD_SECONDS * 2);

        $this->markQueued($station, $hours);

        // The page tells the operator when the waiting build starts.
        $snapshot = $this->get($station);
        $snapshot['held_until'] = $startsAt;
        $this->saveCache($station, $snapshot);
    }

    /**
     * The held build, handed over once: the caller runs it.
     *
     * @return array{hours: int, rebuild: bool, through_tomorrow: bool, replan_lock_window: bool}|null
     */
    public function takeHeldBuild(Station $station): ?array
    {
        $held = $this->cache->get($this->getHeldKey($station));
        if (!is_array($held)) {
            return null;
        }

        $this->cache->delete($this->getHeldKey($station));

        return [
            'hours' => (int)($held['hours'] ?? $station->backend_config->linear_log_hours),
            'rebuild' => (bool)($held['rebuild'] ?? false),
            'through_tomorrow' => (bool)($held['through_tomorrow'] ?? false),
            'replan_lock_window' => (bool)($held['replan_lock_window'] ?? false),
        ];
    }

    public function markBuilding(Station $station, int $hours): void
    {
        $snapshot = $this->get($station);
        $snapshot['status'] = 'building';
        $snapshot['hours'] = $hours;
        $snapshot['started_at'] = time();
        $snapshot['held_until'] = null;
        $snapshot['error'] = null;

        $this->saveCache($station, $snapshot);
    }

    public function cancelQueued(Station $station): void
    {
        $snapshot = $this->get($station);
        $snapshot['status'] = null !== $snapshot['built_at'] ? 'ready' : 'idle';
        $snapshot['error'] = null;

        $this->saveCache($station, $snapshot);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<array<string, mixed>> $gaps
     * @param list<array<string, mixed>> $aiDjShifts
     */
    public function storeReady(
        Station $station,
        int $hours,
        int $buildStartedAt,
        int $coverageStart,
        int $coverageEnd,
        array $entries,
        array $gaps,
        array $aiDjShifts,
    ): void {
        $snapshot = [
            'version' => 2,
            'station_id' => $station->id,
            'status' => 'ready',
            'hours' => $hours,
            'requested_at' => $this->get($station)['requested_at'],
            'held_until' => null,
            'started_at' => $buildStartedAt,
            'built_at' => time(),
            'failed_at' => null,
            'coverage_start' => $coverageStart,
            'coverage_end' => $coverageEnd,
            'entries' => $entries,
            'gaps' => $gaps,
            'ai_dj_shifts' => $aiDjShifts,
            'error' => null,
        ];

        // Write the durable copy first. If cache is cleared by a container
        // restart or deployment, the report can repopulate from this row.
        $this->savePersistent($station, $snapshot);
        $this->saveCache($station, $snapshot);
    }

    public function markFailed(Station $station, int $hours, string $error): void
    {
        $snapshot = $this->get($station);
        $snapshot['status'] = 'failed';
        $snapshot['hours'] = $hours;
        $snapshot['failed_at'] = time();
        $snapshot['error'] = $error;

        // Do not replace the durable last-known-good snapshot with a failed
        // attempt. A restart should still recover the most recent good log.
        $this->saveCache($station, $snapshot);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPersistent(Station $station): ?array
    {
        return $this->findPersistent($station)?->snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function savePersistent(Station $station, array $snapshot): void
    {
        $record = $this->findPersistent($station);
        if (!$record instanceof StationLinearLogSnapshot) {
            $record = new StationLinearLogSnapshot($station);
        }

        $record->snapshot = $snapshot;
        $record->updated_at = time();

        $this->em->persist($record);
        $this->em->flush();
    }

    private function findPersistent(Station $station): ?StationLinearLogSnapshot
    {
        $record = $this->em
            ->getRepository(StationLinearLogSnapshot::class)
            ->findOneBy(['station' => $station]);

        return $record instanceof StationLinearLogSnapshot ? $record : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySnapshot(Station $station): array
    {
        return [
            'version' => 2,
            'station_id' => $station->id,
            'status' => 'idle',
            'hours' => $station->backend_config->linear_log_hours,
            'requested_at' => null,
            'held_until' => null,
            'started_at' => null,
            'built_at' => null,
            'failed_at' => null,
            'coverage_start' => null,
            'coverage_end' => null,
            'entries' => [],
            'gaps' => [],
            'ai_dj_shifts' => [],
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function saveCache(Station $station, array $snapshot): void
    {
        $this->cache->set($this->getKey($station), $snapshot, self::CACHE_TTL);
    }

    private function getKey(Station $station): string
    {
        return 'linear_log_v2.station_' . $station->id;
    }

    private function getHeldKey(Station $station): string
    {
        return 'linear_log_v2.held_build.station_' . $station->id;
    }
}
