<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Entity\StationQueue;
use App\Radio\AutoDJ\LinearLog\LinearLogPlayout;
use App\Radio\AutoDJ\LinearLog\LinearLogRefill;
use App\Radio\AutoDJ\LinearLog\LinearLogRules;
use App\Radio\AutoDJ\LinearLog\LinearLogStore;
use App\Radio\AutoDJ\StrictProgrammeClock;
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
    private const string NO_AUDIO_NOTE = 'Dropped: no audio from the stream during its window';

    private const string REOFFERED_NOTE = 'Re-offered: handed to Liquidsoap but never aired';

    /**
     * How far song history may sit from a queue row's played time and still
     * be that play. Liquidsoap reports a track as it starts, so after it is
     * quick; before it is wide because history keeps one row for a song
     * repeated back to back, and the queue's played times for those repeats
     * ran minutes late (Sun 21:00 clock wheel: aired 21:02 and 21:04, rows
     * say 21:06 and 21:08).
     */
    private const int HEARD_AFTER_SECONDS = 120;

    /** Clock slack before a "played" row's future air time proves it never aired. */
    private const int FUTURE_PLAY_GRACE_SECONDS = 30;
    private const int HEARD_BEFORE_SECONDS = 600;

    public function __construct(
        private readonly LinearLogRules $rules,
        private readonly LinearLogRefill $refill,
        private readonly LinearLogStore $store,
        private readonly StrictProgrammeClock $strictProgrammeClock,
    ) {
    }

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
                // Standing operator rules run on every pass, so a wrong line is
                // taken out of the plan long before its air time.
                $this->rules->apply($station);
                // A repeat is taken out here only while its slot can still be
                // refilled; otherwise QueuedRepeatGuard catches it at the queue.
                if (LinearLogRefill::isEnabled($station)) {
                    $this->rules->dropRepeats($station);
                }
                // Whatever was dropped -- by a rule, the schedule guard, a hand
                // edit or a missing file -- is refilled in the log now, not left
                // as a hole the AutoDJ fills only when the queue reaches it.
                $this->refill->refill($station);
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

        $this->settleStreamProgrammes($station, $now);

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

            // A Liquidsoap restart clears the queue by marking its rows played,
            // with their projected future air times still on them. A play cannot
            // lie in the future: the row was removed before it aired. Waiting
            // for that time to pass kept six such lines "queued" for up to an
            // hour, and the page chained them ahead of the real queue, drawing
            // songs inside Altered Stories (Wed 2026-10-07 18:00).
            if (
                $row instanceof StationQueue
                && $row->is_played
                && null !== $row->timestamp_played
                && $row->timestamp_played->getTimestamp() > $now + self::FUTURE_PLAY_GRACE_SECONDS
            ) {
                $row = null;
            }

            if (!$row instanceof StationQueue) {
                // A live pick's line is written when the AutoDJ picks the song.
                // If the pick was then discarded (e.g. a back-to-back retry), the
                // line describes nothing; it must not turn into a planned line
                // that playout would later air. The grace period covers the few
                // seconds before the picked row itself is saved.
                if (
                    null !== $entry->note
                    && str_starts_with($entry->note, 'Live:')
                    && $entry->created_at < $now - 120
                ) {
                    $this->em->remove($entry);
                    continue;
                }

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

            // A played flag is not airplay. The Top-of-Hour hold can refuse a
            // queued item that is still marked played afterwards: "Old Time
            // Religion" (line 67370, 00:40:24) was logged as aired and never
            // reached the air. Song history is what Liquidsoap reported playing.
            $playedAt = $row->timestamp_played?->getTimestamp() ?? $now;
            if (null !== $row->media && !$this->wasHeard($station, $row->media->id, $playedAt)) {
                if ($now < $playedAt + self::HEARD_AFTER_SECONDS) {
                    // Liquidsoap's report may still be on its way.
                    $this->em->persist($entry);
                    continue;
                }

                // Handed to Liquidsoap and lost before it aired (a restart, or the
                // Top-of-Hour ID discarding a prefetched item): the line is still
                // the log's, so it goes back to playout while its hour runs, as FM
                // automation keeps an unplayed event. Once only, and only if the
                // song has not aired since, so a late start is never replayed.
                $reoffered = null !== $entry->note && str_starts_with($entry->note, self::REOFFERED_NOTE);
                if (
                    !$reoffered
                    && $entry->planned_at >= $hourStart
                    && !$this->airedSince($station, $row->media->id, $playedAt - self::HEARD_BEFORE_SECONDS)
                ) {
                    $entry->status = StationLogEntry::STATUS_PLANNED;
                    $entry->queue_id = null;
                    $entry->note = self::REOFFERED_NOTE;
                    $this->em->persist($entry);
                    continue;
                }

                $entry->status = StationLogEntry::STATUS_DROPPED;
                $entry->note = 'Dropped: marked played but never aired';
                $this->em->persist($entry);
                continue;
            }

            $entry->aired_at = $playedAt;

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
                // The line runs as long as the song that aired. Left at the
                // planned song's length, a 6:36 swap-in logged as 5:45 ended
                // 51s before the ID on the page and in the check.
                $entry->duration = max(1.0, (float)($row->duration ?? $airedMedia->length));
                // The ON AIR match is by payload.song_id, not by title/artist; left
                // stale here it keeps pointing at the pre-swap song, so the Linear
                // Log page can show a different song than what's actually airing.
                $entry->payload = LinearLogStore::payloadForQueueRow($row);
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

        $pending = [];
        foreach ($rows as $row) {
            if (null === $row->media && null === $row->autodj_custom_uri) {
                continue;
            }

            $airedAt = $row->timestamp_played?->getTimestamp() ?? $now;

            // A stream programme's queue row is only marked played when the
            // AutoDJ gets the air back, long after the window opened. It is
            // the programme's own log line, not a new live item.
            $programmeLine = $this->findStreamProgrammeLine($station, $row, $airedAt);
            if (null !== $programmeLine) {
                $row->log_entry_id = $programmeLine->id;
                $this->em->persist($row);
                continue;
            }
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
            $entry->payload = LinearLogStore::payloadForQueueRow($row);
            $entry->note = null !== $row->request
                ? 'Live: listener request'
                : (null !== $row->autodj_custom_uri ? 'Live: AI DJ' : 'Live: picked by AutoDJ (no log line was ready)');
            $this->em->persist($entry);
            $pending[] = [$row, $entry];
        }

        if ([] === $pending) {
            return;
        }

        $this->em->flush();

        // Link each row only after its line has an id, so it is recorded once.
        foreach ($pending as [$row, $entry]) {
            $row->log_entry_id = $entry->id;
            $this->em->persist($row);
        }
    }

    /**
     * A scheduled stream programme airs from its own wall-clock switch in
     * Liquidsoap, not from the AutoDJ queue, so no queue row reports it. Its
     * log line is as-run from the moment its window opens -- once the stream
     * has been heard. Its track titles reach song history as rows with no
     * library file, and nothing else airs inside the window, so one such row
     * proves the audio. A window that closes without one aired silence
     * (Liquidsoap's mksafe fills a dead stream with blank), and its line says
     * so instead of claiming the programme aired.
     */
    private function settleStreamProgrammes(Station $station, int $now): void
    {
        /** @var StationLogEntry[] $lines */
        $lines = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
                AND e.status IN (:open)
                AND e.media IS NULL
                AND e.payload LIKE :programme
                AND e.planned_at <= :now
                AND e.planned_at >= :since
            DQL
        )->setParameter('station', $station)
            ->setParameter('open', [StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED])
            ->setParameter('programme', '%scheduled_programme%')
            ->setParameter('now', $now)
            ->setParameter('since', $now - 86400)
            ->getResult();

        foreach ($lines as $line) {
            if (
                null !== $line->playlist
                && $line->playlist->is_enabled
                && $this->strictProgrammeClock->isPlayedByStrictLane($line->playlist)
            ) {
                $this->settleStrictProgramme($station, $line, $now);
                continue;
            }

            if (!$this->store->isScheduledProgramme($line) || true !== $line->playlist?->is_enabled) {
                continue;
            }

            $windowEnd = $line->planned_at + (int)ceil($line->duration);
            $heard = $this->em->getConnection()->fetchOne(
                'SELECT 1 FROM song_history
                WHERE station_id = ? AND media_id IS NULL
                AND timestamp_start >= ? AND timestamp_start < ?
                LIMIT 1',
                [$station->id, gmdate('Y-m-d H:i:s', $line->planned_at), gmdate('Y-m-d H:i:s', min($now, $windowEnd))]
            );

            if (false !== $heard) {
                $line->status = StationLogEntry::STATUS_AIRED;
                $line->aired_at = $line->planned_at;
            } elseif ($now >= $windowEnd) {
                $line->status = StationLogEntry::STATUS_DROPPED;
                $line->note = self::NO_AUDIO_NOTE;
            } else {
                continue;
            }
            $this->em->persist($line);
        }
    }

    /**
     * A show the strict lane plays from its own files has one line, its
     * programme line, and never a queue row; the line is as-run when the show
     * reaches song history. It starts after the Top-of-Hour ID and news when
     * its window opens on the hour (Faith Horizons: line 17:00:00, on air
     * 17:03:25), so its start is read from history, not assumed.
     */
    private function settleStrictProgramme(Station $station, StationLogEntry $line, int $now): void
    {
        $windowEnd = $line->planned_at + (int)ceil($line->duration);
        $startedAt = $this->em->getConnection()->fetchOne(
            'SELECT MIN(timestamp_start) FROM song_history
            WHERE station_id = ? AND playlist_id = ?
            AND timestamp_start >= ? AND timestamp_start < ?',
            [
                $station->id,
                $line->playlist?->id,
                gmdate('Y-m-d H:i:s', $line->planned_at - self::HEARD_AFTER_SECONDS),
                gmdate('Y-m-d H:i:s', $windowEnd),
            ]
        );

        if (is_string($startedAt) && '' !== $startedAt) {
            $line->status = StationLogEntry::STATUS_AIRED;
            $line->aired_at = CarbonImmutable::parse($startedAt, 'UTC')->getTimestamp();
        } elseif ($now >= $windowEnd + self::HEARD_BEFORE_SECONDS) {
            $line->status = StationLogEntry::STATUS_DROPPED;
            $line->note = 'Dropped: the show did not reach the air in its window';
        } else {
            return;
        }

        $this->em->persist($line);
    }

    /** True when the song has started on air at any time since $since. */
    private function airedSince(Station $station, int $mediaId, int $since): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM song_history
            WHERE station_id = ? AND media_id = ? AND timestamp_start >= ?
            LIMIT 1',
            [$station->id, $mediaId, gmdate('Y-m-d H:i:s', $since)]
        );
    }

    private function wasHeard(Station $station, int $mediaId, int $playedAt): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM song_history
            WHERE station_id = ? AND media_id = ?
            AND timestamp_start >= ? AND timestamp_start <= ?
            LIMIT 1',
            [
                $station->id,
                $mediaId,
                gmdate('Y-m-d H:i:s', $playedAt - self::HEARD_BEFORE_SECONDS),
                gmdate('Y-m-d H:i:s', $playedAt + self::HEARD_AFTER_SECONDS),
            ]
        );
    }

    /**
     * The log line of the stream programme window a played queue row belongs to.
     */
    private function findStreamProgrammeLine(Station $station, StationQueue $row, int $airedAt): ?StationLogEntry
    {
        $playlist = $row->playlist;
        if (
            null === $row->autodj_custom_uri
            || null === $playlist
            || PlaylistSources::RemoteUrl !== $playlist->source
        ) {
            return null;
        }

        /** @var StationLogEntry|null $line */
        $line = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
                AND e.playlist = :playlist
                AND e.media IS NULL
                AND e.payload LIKE :programme
                AND (e.status = :aired OR (e.status = :dropped AND e.note = :noAudio))
                AND e.planned_at <= :airedAt
                AND e.planned_at >= :since
                ORDER BY e.planned_at DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('playlist', $playlist)
            ->setParameter('programme', '%scheduled_programme%')
            ->setParameter('aired', StationLogEntry::STATUS_AIRED)
            ->setParameter('dropped', StationLogEntry::STATUS_DROPPED)
            ->setParameter('noAudio', self::NO_AUDIO_NOTE)
            ->setParameter('airedAt', $airedAt)
            ->setParameter('since', $airedAt - 86400)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $line;
    }
}
