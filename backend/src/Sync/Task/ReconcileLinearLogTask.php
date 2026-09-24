<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Entity\StationQueue;
use App\Radio\AutoDJ\LinearLog\LinearLogPlayout;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * As-run reconciliation for the saved linear log (every minute).
 *
 * Marks each line with what actually happened on air: aired (with its real
 * start time), swapped or replaced (with what was planned), or dropped when
 * its queue row disappeared after its hour. Live items that aired between log
 * lines (listener requests, a fallback pick) are recorded as their own lines,
 * so the log reads as the station's as-run log.
 */
final class ReconcileLinearLogTask extends AbstractTask
{
    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public function run(bool $force = false): void
    {
        /** @var array<int, array{id: int|string}> $stationRows */
        $stationRows = $this->em->createQuery(
            <<<'DQL'
                SELECT s.id AS id FROM App\Entity\Station s
            DQL
        )->getScalarResult();

        foreach ($stationRows as $stationRow) {
            $this->em->clear();
            $station = $this->em->find(Station::class, (int)$stationRow['id']);
            if (!$station instanceof Station || !LinearLogPlayout::isPlayoutEnabled($station)) {
                continue;
            }

            try {
                $this->reconcile($station);
            } catch (Throwable $e) {
                $this->logger->error('Linear Log reconciliation failed.', [
                    'station_id' => $station->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function reconcile(Station $station): void
    {
        $now = time();
        $hourStart = CarbonImmutable::now($station->getTimezoneObject())->startOfHour()->getTimestamp();

        /** @var StationLogEntry[] $queued */
        $queued = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\StationLogEntry e
                WHERE e.station = :station AND e.status = :queued
            DQL
        )->setParameter('station', $station)
            ->setParameter('queued', StationLogEntry::STATUS_QUEUED)
            ->getResult();

        foreach ($queued as $entry) {
            /** @var StationQueue|null $row */
            $row = $this->em->createQuery(
                <<<'DQL'
                    SELECT sq FROM App\Entity\StationQueue sq
                    WHERE sq.station = :station AND sq.log_entry_id = :entryId
                    ORDER BY sq.id DESC
                DQL
            )->setParameter('station', $station)
                ->setParameter('entryId', $entry->id)
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if (!$row instanceof StationQueue) {
                // Removed from the queue before airing.
                if ($entry->planned_at >= $hourStart) {
                    $entry->status = StationLogEntry::STATUS_PLANNED;
                } else {
                    $entry->status = StationLogEntry::STATUS_DROPPED;
                    $entry->note = 'Dropped: removed from the queue before it aired';
                }
                $this->em->persist($entry);
                continue;
            }

            $entry->queue_id = $row->id;

            if (!$row->is_played) {
                $this->em->persist($entry);
                continue;
            }

            $entry->aired_at = $row->timestamp_played?->getTimestamp() ?? $now;

            $plannedMediaId = $entry->media?->id;
            $airedMedia = $row->media;
            if (null !== $airedMedia && $airedMedia->id !== $plannedMediaId) {
                $wasReplaced = null !== $entry->note && str_starts_with($entry->note, 'Replaced');
                $entry->status = $wasReplaced
                    ? StationLogEntry::STATUS_REPLACED
                    : StationLogEntry::STATUS_SWAPPED;
                $entry->note = mb_substr(
                    ($wasReplaced ? $entry->note : 'Swapped at the top of the hour')
                    . '; planned: ' . ($entry->text ?? 'unknown'),
                    0,
                    255
                );
                $entry->media = $airedMedia;
                $entry->playlist = $row->playlist ?? $entry->playlist;
                $entry->title = $row->title;
                $entry->artist = $row->artist;
                $entry->text = $row->text;
            } else {
                $entry->status = StationLogEntry::STATUS_AIRED;
            }

            $this->em->persist($entry);
        }

        $this->recordLiveItems($station, $now);

        $this->em->flush();
    }

    /**
     * Queue rows that aired without a log line (listener requests, or AutoDJ
     * filling in when the log had nothing ready) become as-run lines.
     */
    private function recordLiveItems(Station $station, int $now): void
    {
        /** @var StationQueue[] $rows */
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sq FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.is_played = 1
                AND sq.log_entry_id IS NULL
                AND sq.top_of_hour_legal_id = 0
                AND sq.timestamp_played >= :since
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', CarbonImmutable::createFromTimestamp($now - 3600))
            ->getResult();

        foreach ($rows as $row) {
            if (null === $row->media && null === $row->autodj_custom_uri) {
                continue;
            }

            $airedAt = $row->timestamp_played?->getTimestamp() ?? $now;
            $entry = new StationLogEntry($station, $airedAt, 0);
            $entry->status = StationLogEntry::STATUS_AIRED;
            $entry->aired_at = $airedAt;
            $entry->queue_id = $row->id;
            $entry->media = $row->media;
            $entry->playlist = $row->playlist;
            $entry->duration = (float)($row->duration ?? $row->media->length ?? 0.0);
            $entry->title = $row->title;
            $entry->artist = $row->artist;
            $entry->text = $row->text;
            $entry->note = null !== $row->request
                ? 'Live: listener request'
                : (null !== $row->autodj_custom_uri ? 'Live: AI DJ' : 'Live: picked by AutoDJ (no log line was ready)');
            $this->em->persist($entry);
            $this->em->flush();

            // Link it so it is recorded only once.
            $row->log_entry_id = $entry->id;
            $this->em->persist($row);
        }
    }
}
