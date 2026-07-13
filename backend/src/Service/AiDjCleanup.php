<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Finder\Finder;

final class AiDjCleanup
{
    private const string CLIP_DIR_NAME = 'ai_dj';
    private const int MAX_STORAGE_MB = 500;
    private const int CLIP_TTL_DAYS = 7;

    public function cleanupOldClips(int $stationId): int
    {
        $dir = $this->getClipDirectory($stationId);
        
        if (!is_dir($dir)) {
            return 0;
        }

        $finder = new Finder();
        $finder
            ->files()
            ->in($dir)
            ->date('before ' . self::CLIP_TTL_DAYS . ' days ago');

        $removed = 0;
        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if (false !== $filePath && @unlink($filePath)) {
                $removed++;
            }
        }

        return $removed;
    }

    public function checkDiskUsage(int $stationId): int
    {
        $dir = $this->getClipDirectory($stationId);

        if (!is_dir($dir)) {
            return 0;
        }

        $bytes = 0;
        $finder = new Finder();
        $finder->files()->in($dir);

        foreach ($finder as $file) {
            $bytes += $file->getSize();
        }

        return (int)round($bytes / 1024 / 1024);
    }

    public function enforceQuota(int $stationId): bool
    {
        $usedMb = $this->checkDiskUsage($stationId);
        return $usedMb < self::MAX_STORAGE_MB;
    }

    private function getClipDirectory(int $stationId): string
    {
        return '/var/azuracast/stations/' . $stationId . '/' . self::CLIP_DIR_NAME;
    }
}
