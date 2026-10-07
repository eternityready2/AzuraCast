<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\LinearLogPreviewContext;
use App\Radio\AutoDJ\StrictProgrammeClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plays the saved 24-hour linear log in order.
 *
 * Runs at the same BuildQueue step as the random picker (after listener
 * requests and AI DJ, which stay live). While the log has a line ready, the
 * Clock Wheel and random pickers stand down. Validators still run on the
 * log's pick: a DMCA rejection makes the next attempt fall back to the normal
 * pickers and the replacement is linked back to the log line; a Top-of-Hour
 * swap changes the queue row and is written back when it airs.
 */
final class LinearLogPlayout implements EventSubscriberInterface
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    /** Last log line offered, to detect a validator rejecting it. */
    private ?int $offeredEntryId = null;
    private ?int $offeredAt = null;

    /** @var array<int, int> expected-play timestamp => log line that failed validation there */
    private array $replacementFor = [];

    public function __construct(
        private readonly LinearLogPreviewContext $previewContext,
        private readonly LinearLogStore $store,
        private readonly StrictProgrammeClock $strictProgrammeClock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => [
                ['supplyFromLog', 0],
                ['linkSelection', -190],
            ],
        ];
    }

    public static function isPlayoutEnabled(Station $station): bool
    {
        // One switch, as on FM automation: an enabled log is what plays.
        return $station->backend_config->linear_log_enabled;
    }

    /**
     * True when the log, not the Clock Wheel or random pickers, chooses this slot.
     */
    public function ownsSelection(BuildQueue $event): bool
    {
        $station = $event->getStation();
        if (
            !self::isPlayoutEnabled($station)
            || $this->previewContext->isActive()
            || $event->isInterrupting()
        ) {
            return false;
        }

        if (isset($this->replacementFor[$event->getExpectedPlayTime()->getTimestamp()])) {
            return false;
        }

        return $this->hasLineForHour($station, $event->getExpectedPlayTime());
    }

    /**
     * End of the clock hour $expected falls in, in station time.
     */
    private static function hourEnd(Station $station, DateTimeInterface $expected): int
    {
        return CarbonImmutable::instance($expected)
            ->setTimezone($station->getTimezoneObject())
            ->startOfHour()
            ->addHour()
            ->getTimestamp();
    }

    /**
     * True when the next planned line belongs to this hour (or an earlier one).
     *
     * The log is taken in order, so when an hour runs out of lines the next one
     * up is the following hour's opener. Airing it now pulled every later hour
     * forward with it: lines planned for 02:43 went out at 01:48, and the hours
     * behind them were left with nothing planned. An hour that comes up short is
     * filled by the normal pickers instead, as on FM automation, and each
     * hour's own lines stay in their hour.
     *
     * "In order" is air-time order. Sequence is only the order lines were
     * written: a locked line keeps its old sequence through a rebuild, and
     * programme lines are written after the music around them, so taking by
     * sequence put an 11:00 locked line ahead of the 03:00-10:59 lines and
     * this guard then held back every hour in between.
     */
    private function hasLineForHour(Station $station, DateTimeInterface $expected): bool
    {
        $plannedAt = $this->em->createQuery(
            <<<'DQL'
                SELECT e.planned_at FROM App\Entity\StationLogEntry e
                WHERE e.station = :station AND e.status = :planned
                AND (e.playlist IS NULL OR e.playlist NOT IN (:strictLane))
                ORDER BY e.planned_at ASC, e.sequence ASC
            DQL
        )->setParameter('station', $station)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->setParameter('strictLane', $this->strictLanePlaylistIds($station))
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return is_array($plannedAt) && (int)$plannedAt['planned_at'] < self::hourEnd($station, $expected);
    }

    public function supplyFromLog(BuildQueue $event): void
    {
        if (!empty($event->getNextSongs()) || !$this->ownsSelection($event)) {
            return;
        }

        $station = $event->getStation();
        $expected = $event->getExpectedPlayTime();
        $at = $expected->getTimestamp();

        $entry = $this->takeNext($station, $expected);
        if (!$entry instanceof StationLogEntry) {
            return;
        }

        // Same line offered for the same slot again: a validator (DMCA)
        // rejected it. Let the normal pickers choose; linkSelection() ties the
        // replacement back to this line.
        if ($this->offeredEntryId === $entry->id && $this->offeredAt === $at) {
            $this->replacementFor[$at] = $entry->id;
            $this->offeredEntryId = null;
            $this->logger->notice(
                'Linear Log: planned line failed validation; AutoDJ picks a replacement.',
                ['log_entry_id' => $entry->id, 'planned' => $entry->text]
            );
            return;
        }

        $row = $this->materialize($station, $entry, $expected);
        $this->offeredEntryId = $entry->id;
        $this->offeredAt = $at;

        $event->setNextSongs($row);
    }

    public function linkSelection(BuildQueue $event): void
    {
        // The planner's simulation never claims a line; it is rolled back.
        if ($this->previewContext->isActive()) {
            return;
        }

        $at = $event->getExpectedPlayTime()->getTimestamp();
        $rows = $event->getNextSongs();
        if (1 !== count($rows)) {
            return;
        }
        $row = $rows[0];

        $entryId = $row->log_entry_id;
        $note = null;

        if (null === $entryId && isset($this->replacementFor[$at])) {
            $entryId = $this->replacementFor[$at];
            $row->log_entry_id = $entryId;
            $note = 'Replaced at air time: the planned song failed the DMCA rules';
        }
        unset($this->replacementFor[$at]);

        if (null === $entryId) {
            $this->writeLiveLine($event->getStation(), $row, $at);
            return;
        }

        $entry = $this->em->find(StationLogEntry::class, $entryId);
        if (!$entry instanceof StationLogEntry) {
            return;
        }

        // Claim the line in the database, not only in memory: a schedule-change
        // re-plan may have replaced it since takeNext() read it. Its commit
        // locks the lines it replaces, so either this claim lands first and the
        // re-plan starts over, or the line is gone and this row airs as an
        // ordinary live pick instead of pointing at a deleted line.
        $claimed = $this->em->createQuery(
            <<<'DQL'
                UPDATE App\Entity\StationLogEntry e SET e.status = :queued
                WHERE e.id = :id AND e.status = :planned
            DQL
        )->setParameter('queued', StationLogEntry::STATUS_QUEUED)
            ->setParameter('id', $entryId)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->execute();
        // MySQL counts changed rows, so a line that was already queued (a
        // re-offer) reports 0 here too; only a missing or closed line is lost.
        $dbStatus = 0 === $claimed
            ? $this->em->getConnection()->fetchOne('SELECT status FROM station_log_entries WHERE id = ?', [$entryId])
            : StationLogEntry::STATUS_QUEUED;
        if (StationLogEntry::STATUS_QUEUED !== $dbStatus) {
            $this->logger->notice('Linear Log: planned line was taken away before it was queued; airing as a live pick.', [
                'log_entry_id' => $entryId,
                'status_now' => false === $dbStatus ? 'deleted' : $dbStatus,
                'song' => $row->text,
            ]);
            $row->log_entry_id = null;
            $this->em->detach($entry);
            if ($this->offeredEntryId === $entryId) {
                $this->offeredEntryId = null;
            }
            return;
        }

        $entry->status = StationLogEntry::STATUS_QUEUED;
        if (null !== $note) {
            $entry->note = mb_substr(
                $note . '; planned: ' . ($entry->text ?? 'unknown'),
                0,
                255,
            );

            // The replacement is a different song, possibly from a different
            // playlist. Update the log entry so the Linear Log report shows
            // the song that actually airs, not the one that was planned.
            $entry->media = $row->media;
            $entry->playlist = $row->playlist;
            $entry->title = $row->title;
            $entry->artist = $row->artist;
            $entry->text = mb_substr((string)$row->text, 0, 255) ?: null;
            $entry->duration = max(1.0, (float)($row->duration ?? 0.0));
            $entry->payload = [
                'song_id' => $row->song_id,
                'album' => $row->album,
                'media_type' => $row->media?->type,
            ];
        }
        $this->em->persist($entry);

        if ($this->offeredEntryId === $entryId) {
            $this->offeredEntryId = null;
        }
    }

    /**
     * A song the AutoDJ picked on its own (the log had no line ready) goes into
     * the log the moment it is queued, as FM automation would show it, not only
     * once it has aired. Until now such a song was invisible in the upcoming log
     * and showed as a hole (Brandon Heath, queued for 15:02:55 on Mon
     * 2026-10-05). Written with plain SQL so no other pending change is flushed
     * mid-build; ReconcileLinearLogTask then settles it like any queued line.
     */
    private function writeLiveLine(Station $station, StationQueue $row, int $at): void
    {
        if (null === $row->media || $row->top_of_hour_legal_id || $row->clock_wheel_legal_id_substitute) {
            return;
        }

        $conn = $this->em->getConnection();
        $sequence = 1 + (int)$conn->fetchOne(
            'SELECT COALESCE(MAX(sequence), 0) FROM station_log_entries WHERE station_id = ?',
            [$station->id]
        );

        $conn->insert('station_log_entries', [
            'station_id' => $station->id,
            'media_id' => $row->media->id,
            'playlist_id' => $row->playlist?->id,
            'planned_at' => $at,
            'sequence' => $sequence,
            'duration' => max(1.0, (float)($row->duration ?? $row->media->length ?? 0.0)),
            'status' => StationLogEntry::STATUS_QUEUED,
            'text' => mb_substr((string)$row->text, 0, 255) ?: null,
            'title' => null !== $row->title ? mb_substr($row->title, 0, 255) : null,
            'artist' => null !== $row->artist ? mb_substr($row->artist, 0, 255) : null,
            'payload' => json_encode(LinearLogStore::payloadForQueueRow($row), JSON_THROW_ON_ERROR),
            'note' => null !== $row->request
                ? 'Live: listener request'
                : 'Live: picked by AutoDJ (no log line was ready)',
            'is_locked' => 0,
            'created_at' => time(),
        ]);

        $row->log_entry_id = (int)$conn->lastInsertId();
    }

    /**
     * Next planned line, dropping any left over from an hour that has already
     * ended ("hit the post": the new hour opens with its own first line).
     */
    private function takeNext(Station $station, DateTimeImmutable $expected): ?StationLogEntry
    {
        // An hour has only "ended" once the wall clock is past it. The expected
        // slot time can run hours ahead of the clock (a long programme row in
        // the queue put it at 11:07 at 08:59), and using it alone dropped the
        // 10:00 God Family & Country line as a leftover before its hour began
        // -- the hour then aired nothing (Mon 2026-10-05).
        $hourStart = CarbonImmutable::instance(min($expected, CarbonImmutable::now()))
            ->setTimezone($station->getTimezoneObject())
            ->startOfHour()
            ->getTimestamp();

        // Lines of a show the strict lane plays from its own files are neither
        // queued nor dropped here: Liquidsoap airs the show, and the queue only
        // carries what follows it. Taken, Faith Horizons' programme line (no
        // file of its own) was dropped as a removed file, and the log moved
        // straight on to the songs after the show (Wed 2026-10-07 17:00).
        $strictLane = $this->strictLanePlaylistIds($station);

        $dropped = $this->em->createQuery(
            <<<'DQL'
                UPDATE App\Entity\StationLogEntry e
                SET e.status = :dropped, e.note = :note
                WHERE e.station = :station
                AND e.status = :planned
                AND e.planned_at < :hourStart
                AND (e.payload IS NULL OR e.payload NOT LIKE :programme OR e.planned_at + e.duration <= :now)
                AND (e.playlist IS NULL OR e.playlist NOT IN (:strictLane))
            DQL
        )->setParameter('dropped', StationLogEntry::STATUS_DROPPED)
            ->setParameter('strictLane', $strictLane)
            ->setParameter('note', 'Dropped: its hour ran long')
            // A scheduled programme block is not a leftover while its window is
            // still open: the next music line can belong to the following hour
            // because the programme owns the air until then.
            ->setParameter('programme', '%scheduled_programme%')
            ->setParameter('now', time())
            ->setParameter('station', $station)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->setParameter('hourStart', $hourStart)
            ->execute();

        if ($dropped > 0) {
            $this->logger->notice('Linear Log: dropped lines left over from an hour that ended.', [
                'count' => $dropped,
            ]);
        }

        for ($i = 0; $i < 20; $i++) {
            /** @var StationLogEntry|null $entry */
            $entry = $this->em->createQuery(
                <<<'DQL'
                    SELECT e FROM App\Entity\StationLogEntry e
                    WHERE e.station = :station AND e.status = :planned
                    AND (e.playlist IS NULL OR e.playlist NOT IN (:strictLane))
                    ORDER BY e.planned_at ASC, e.sequence ASC
                DQL
            )->setParameter('station', $station)
                ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
                ->setParameter('strictLane', $strictLane)
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if (!$entry instanceof StationLogEntry) {
                return null;
            }

            // A later hour's line stays in its hour; see hasLineForHour().
            if ($entry->planned_at >= self::hourEnd($station, $expected)) {
                return null;
            }

            if (null !== $entry->media) {
                return $entry;
            }

            // A media-less line that is a scheduled programme (a remote-stream
            // window) is not a missing file: it must air. Anything scheduled in
            // AzuraCast has to be honored by the log, so these are never dropped
            // -- the remote stream plays through a custom-URI queue row, exactly
            // as the AutoDJ would outside the log.
            if ($this->store->isScheduledProgramme($entry)) {
                return $entry;
            }

            $entry->status = StationLogEntry::STATUS_DROPPED;
            $entry->note = 'Dropped: the planned file was removed from the library';
            $this->em->persist($entry);
            $this->em->flush();
        }

        return null;
    }

    /** @return non-empty-list<int> */
    private function strictLanePlaylistIds(Station $station): array
    {
        $ids = [];
        foreach ($station->playlists as $playlist) {
            if ($this->strictProgrammeClock->isPlayedByStrictLane($playlist)) {
                $ids[] = $playlist->id;
            }
        }

        // NOT IN () is invalid SQL; 0 matches no playlist.
        return [] === $ids ? [0] : $ids;
    }

    private function materialize(
        Station $station,
        StationLogEntry $entry,
        DateTimeInterface $expected
    ): StationQueue {
        $this->logger->info('Linear Log: playing the next planned line.', [
            'log_entry_id' => $entry->id,
            'planned_at' => $entry->planned_at,
            'text' => $entry->text,
        ]);

        // The AutoDJ pickers record when a playlist last aired, and the
        // once-per-hour / per-X-minutes rules read it. Lines aired from the log
        // skipped that, so promos looked never-played and aired again and again.
        if (null !== $entry->playlist) {
            $entry->playlist->played_at = CarbonImmutable::instance($expected);
            $this->em->persist($entry->playlist);
        }

        return $this->store->toQueueRow($station, $entry, $expected);
    }
}
