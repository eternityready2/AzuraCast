<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Station;
use App\Service\AiDjCleanup;
use Throwable;

final class AiDjCleanupTask extends AbstractTask
{
    public function __construct(
        private readonly AiDjCleanup $cleanup,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '0 * * * *';
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            try {
                /** @var Station $station */
                $this->cleanupStation($station->id);
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage(), [
                    'station' => (string)$station,
                ]);
            }
        }
    }

    private function cleanupStation(int $stationId): void
    {
        $removed = $this->cleanup->cleanupOldClips($stationId);
        $usedMb = $this->cleanup->checkDiskUsage($stationId);

        $this->logger->info('AI DJ cleanup completed', [
            'station_id' => $stationId,
            'files_removed' => $removed,
            'storage_mb' => $usedMb,
        ]);
    }
}
