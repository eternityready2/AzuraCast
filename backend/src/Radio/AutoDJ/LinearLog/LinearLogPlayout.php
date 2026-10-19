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
use Doctrine\DBAL\Types\Types;
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

    /** Planned lines looked at per query when finding the one for a slot. */
    private const int LINE_BATCH_SIZE = 8;

    /** Lines passed over for one slot before the log stands down for it. */
    private const int MAX_LINES_PASSED_OVER = 48;

    /** Last log line offered, to detect a validator rejecting it. */
    private ?int $offeredEntryId = null;
    private ?int $offeredAt = null;

    /** @var array<int, int> expected-play timestamp => log line that failed validation there */
    private array $replacementFor = [];

    public function __construct(
        private readonly LinearLogPreviewContext $previewContext,
        private readonly LinearLogStore $store,
        private readonly StrictProgrammeClock $strictProgrammeClock,
        private readonly LinearLogLineWindow $lineWindow,
        private readonly LinearLogHour $hour,
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
     * End of the clock hour the slot at $expected airs in, in station time.
     *
     * A song due in the swap's hold window before the ID is held and opens the
     * next hour, so that is the hour the slot's line comes from. Counted in the
     * hour that was ending, the log stood down for it and the AutoDJ's pick
     * aired after the ID as an extra song ahead of the next hour's own first
     * line (22:59:57, Wed 2026-10-07), or was held, sent back by the hour guard
     * and picked again (10:06-11:00am, Mon 2026-10-12).
     */
    private function hourEnd(Station $station, DateTimeInterface $expected): int
    {
        return $this->hour->endOf($station, $expected->getTimestamp());
    }

    /**
     * True when the log has a line to play in the slot at $expected.
     */
    private function hasLineForHour(Station $station, DateTimeInterface $expected): bool
    {
        return null !== $this->nextLineFor($station, $expected);
    }

    /**
     * The planned line playout takes for the slot at $expected, or null when
     * the log has none for it.
     *
     * The log is taken in order, so when an hour runs out of lines the next one
     * up is the following hour's opener. Airing it now pulled every later hour
     * forward with it: lines planned for 02:43 went out at 01:48, and the hours
     * behind them were left with nothing planned. An hour that comes up short is
     * filled by the normal pickers instead, as on FM automation, and each
     * hour's own lines stay in their hour.
     *
     * The same holds the other way: a line the hour before had no room for is
     * not given a slot in this one. The queue fills about an hour ahead, so an
     * hour's leftovers were taken for the next hour's first slots long before
     * their own hour ended, where closeOutEndedHours() no longer reaches them.
     * They opened the new hour ahead of its own lines, and every hour passed
     * its overrun on: 16 minutes by 9pm on Sun 2026-10-11, which put the 9pm
     * hour's last four songs inside the 10pm Life Perspectives show. A
     * leftover stays planned, for its own hour only, until that hour ends
     * (closeOutEndedHours()).
     * Shows are timed events and are offered as before.
     *
     * "In order" is air-time order. Sequence is only the order lines were
     * written: a locked line keeps its old sequence through a rebuild, and
     * programme lines are written after the music around them, so taking by
     * sequence put an 11:00 locked line ahead of the 03:00-10:59 lines and
     * this guard then held back every hour in between.
     *
     * A line whose source may not play in this slot is passed over when the
     * log's own time for it is fine: it was planned for another block (the
     * last Hymns songs of an hour that ran long, once the slot is past 6:00;
     * a show's line while the queue is timing the slot outside its window).
     * Offered anyway, the schedule guard threw its row out on the next pass
     * and the line with it. It stays planned for a slot it may play in, and is
     * closed out with its hour's other leftovers if that never comes.
     */
    private function nextLineFor(Station $station, DateTimeInterface $expected): ?StationLogEntry
    {
        $hourEnd = $this->hourEnd($station, $expected);

        // A slot in the last seconds before the ID airs when the new hour
        // opens (see hourEnd()), so that is where its line is judged.
        $slotAt = new DateTimeImmutable('@' . max($expected->getTimestamp(), $hourEnd - 3600));

        $strictLane = $this->strictLanePlaylistIds($station);

        for ($offset = 0; $offset < self::MAX_LINES_PASSED_OVER; $offset += self::LINE_BATCH_SIZE) {
            /** @var StationLogEntry[] $lines */
            $lines = $this->em->createQuery(
                <<<'DQL'
                    SELECT e FROM App\Entity\StationLogEntry e
                    LEFT JOIN e.playlist p
                    WHERE e.station = :station AND e.status = :planned
                    AND e.planned_at < :hourEnd
                    AND (e.planned_at >= :hourStart OR e.payload LIKE :programme OR p.is_programme = true)
                    AND (e.playlist IS NULL OR e.playlist NOT IN (:strictLane))
                    ORDER BY e.planned_at ASC, e.sequence ASC
                DQL
            )->setParameter('station', $station)
                ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
                ->setParameter('hourEnd', $hourEnd)
                ->setParameter('hourStart', $hourEnd - 3600)
                ->setParameter('programme', '%scheduled_programme%')
                ->setParameter('strictLane', $strictLane)
                ->setFirstResult($offset)
                ->setMaxResults(self::LINE_BATCH_SIZE)
                ->getResult();

            foreach ($lines as $line) {
                if ($this->lineWindow->mayAirAt($line, $slotAt) || !$this->lineWindow->mayAirAsPlanned($line)) {
                    return $line;
                }
            }

            if (count($lines) < self::LINE_BATCH_SIZE) {
                break;
            }
        }

        return null;
    }

    /**
     * Planned air time of the next line the log has from $hourStart on, or null
     * when none is planned. An earlier hour's leftovers are not lines for it.
     */
    private function nextPlannedAt(Station $station, int $hourStart): ?int
    {
        $plannedAt = $this->em->createQuery(
            <<<'DQL'
                SELECT e.planned_at FROM App\Entity\StationLogEntry e
                WHERE e.station = :station AND e.status = :planned
                AND e.planned_at >= :hourStart
                AND (e.playlist IS NULL OR e.playlist NOT IN (:strictLane))
                ORDER BY e.planned_at ASC, e.sequence ASC
            DQL
        )->setParameter('station', $station)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->setParameter('hourStart', $hourStart)
            ->setParameter('strictLane', $this->strictLanePlaylistIds($station))
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return is_array($plannedAt) ? (int)$plannedAt['planned_at'] : null;
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
            $this->writeLiveLine($event, $row);
            return;
        }

        $entry = $this->em->find(StationLogEntry::class, $entryId);
        if (!$entry instanceof StationLogEntry) {
            return;
        }

        // The Top-of-Hour swap put a short item that ends on the ID in this
        // slot: the next hour's line stays in the log and opens that hour.
        if ($this->hour->isFillerBeforeLine($event->getStation(), $row, $at, $entry)) {
            $row->log_entry_id = null;
            $this->writeLiveLine($event, $row);
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
            ? $this->em->getConnection()->fetchOne(
                'SELECT status FROM station_log_entries WHERE id = ?',
                [$entryId]
            )
            : StationLogEntry::STATUS_QUEUED;
        if (StationLogEntry::STATUS_QUEUED !== $dbStatus) {
            $this->logger->notice(
                'Linear Log: planned line was taken away before it was queued; airing as a live pick.',
                [
                    'log_entry_id' => $entryId,
                    'status_now' => false === $dbStatus ? 'deleted' : $dbStatus,
                    'song' => $row->text,
                ]
            );
            $row->log_entry_id = null;
            $this->em->detach($entry);
            if ($this->offeredEntryId === $entryId) {
                $this->offeredEntryId = null;
            }
            return;
        }

        // The row was built from the line as takeNext() read it, and that is
        // what airs. A song written to the line between that read and this
        // claim (LinearLogHourFit re-fits planned lines every minute, in
        // another process) would leave the log naming a song that never
        // plays, and the row's own song recorded as a Top-of-Hour swap when it
        // airs. Nothing re-fits a claimed line, so it is put back here as read.
        $this->keepLineAsRead($entry);

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
     * Write a claimed line's song back as this pass read it, when the saved
     * line has been given another one since.
     */
    private function keepLineAsRead(StationLogEntry $entry): void
    {
        $conn = $this->em->getConnection();

        $savedMediaId = $conn->fetchOne('SELECT media_id FROM station_log_entries WHERE id = ?', [$entry->id]);
        if (false === $savedMediaId || (null === $savedMediaId ? null : (int)$savedMediaId) === $entry->media?->id) {
            return;
        }

        $conn->update(
            'station_log_entries',
            [
                'media_id' => $entry->media?->id,
                'playlist_id' => $entry->playlist?->id,
                'duration' => $entry->duration,
                'text' => $entry->text,
                'title' => $entry->title,
                'artist' => $entry->artist,
                'payload' => $entry->payload,
                'note' => $entry->note,
            ],
            ['id' => $entry->id],
            ['payload' => Types::JSON]
        );

        $this->logger->notice('Linear Log: a line was given another song as it was queued; it airs as read.', [
            'log_entry_id' => $entry->id,
            'song' => $entry->text,
        ]);
    }

    /**
     * A song the AutoDJ picked on its own (the log had no line ready) goes into
     * the log the moment it is queued, as FM automation would show it, not only
     * once it has aired. Until now such a song was invisible in the upcoming log
     * and showed as a hole (Brandon Heath, queued for 15:02:55 on Mon
     * 2026-10-05). Written with plain SQL so no other pending change is flushed
     * mid-build; ReconcileLinearLogTask then settles it like any queued line.
     *
     * The note says why the log had no line for the slot, so a hole in the plan
     * reads differently from an hour whose own lines ran out before the ID.
     */
    private function writeLiveLine(BuildQueue $event, StationQueue $row): void
    {
        if (null === $row->media || $row->top_of_hour_legal_id || $row->clock_wheel_legal_id_substitute) {
            return;
        }

        $station = $event->getStation();
        $expected = $event->getExpectedPlayTime();
        $at = $expected->getTimestamp();

        if (null !== $row->request) {
            $note = 'Live: listener request';
        } else {
            $hourEnd = $this->hourEnd($station, $expected);
            $nextPlannedAt = $this->nextPlannedAt($station, $hourEnd - 3600);
            $reason = match (true) {
                $event->isInterrupting() => 'an interrupting playlist took the slot',
                null === $nextPlannedAt => 'the log has no planned lines',
                $nextPlannedAt >= $hourEnd => "this hour's log lines ran out",
                default => 'no log line was ready',
            };
            // A show's own file is not a song the AutoDJ chose: it is the
            // scheduled show airing, or going on after the Station ID, with no
            // line on the plan for it.
            $note = true === $row->playlist?->is_programme
                ? 'Live: scheduled show (' . $reason . ')'
                : 'Live: picked by AutoDJ (' . $reason . ')';

            $this->logger->notice('Linear Log: the AutoDJ picked a song the log did not plan.', [
                'reason' => $reason,
                'airs_at' => $expected->format(DATE_ATOM),
                'next_planned_at' => null === $nextPlannedAt
                    ? null
                    : $expected->setTimestamp($nextPlannedAt)->format(DATE_ATOM),
                'song' => $row->text,
            ]);
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
            'duration' => max(1.0, (float)($row->duration ?? $row->media->length)),
            'status' => StationLogEntry::STATUS_QUEUED,
            'text' => mb_substr((string)$row->text, 0, 255) ?: null,
            'title' => null !== $row->title ? mb_substr($row->title, 0, 255) : null,
            'artist' => null !== $row->artist ? mb_substr($row->artist, 0, 255) : null,
            'payload' => json_encode(LinearLogStore::payloadForQueueRow($row), JSON_THROW_ON_ERROR),
            'note' => $note,
            'is_locked' => 0,
            'created_at' => time(),
        ]);

        $row->log_entry_id = (int)$conn->lastInsertId();
    }

    /**
     * Drop the planned lines left over from an hour that has already ended
     * ("hit the post": the new hour opens with its own first line).
     *
     * An hour has only "ended" once the wall clock is past it. The expected
     * slot time can run hours ahead of the clock (a long programme row in the
     * queue put it at 11:07 at 08:59), and using it alone dropped the 10:00
     * God Family & Country line as a leftover before its hour began -- the
     * hour then aired nothing (Mon 2026-10-05).
     *
     * Nor is the hour on the clock closed early once the slot time passes its
     * end. That time is the queue's estimate, and it jumps: behind a stream
     * show, and after a restart, it reached the next hour with most of the
     * hour on the clock still to air. Closing early then dropped all eleven
     * songs left in the 9am hour at 9:00:37 (Thu 2026-10-15) and all nine
     * left in the 11pm hour at 11:19:30 (Fri 2026-10-16), and the AutoDJ
     * filled both hours. A leftover is never offered once the queue is in a
     * later hour (nextLineFor()), and the page lists it as having no room
     * (LinearLogStore::floatSoftLines()), so it waits harmlessly for the
     * clock.
     */
    private function closeOutEndedHours(Station $station, DateTimeInterface $expected): void
    {
        $hourStart = CarbonImmutable::instance($expected)
            ->min(CarbonImmutable::now())
            ->setTimezone($station->getTimezoneObject())
            ->startOfHour()
            ->getTimestamp();

        // Lines of a show the strict lane plays from its own files are neither
        // queued nor dropped here: Liquidsoap airs the show, and the queue only
        // carries what follows it. Taken, Faith Horizons' programme line (no
        // file of its own) was dropped as a removed file, and the log moved
        // straight on to the songs after the show (Wed 2026-10-07 17:00).
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
            ->setParameter('strictLane', $this->strictLanePlaylistIds($station))
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
    }

    /**
     * The line for the slot at $expected, once the leftovers of ended hours
     * are closed out and any line whose file is gone has been dropped.
     */
    private function takeNext(Station $station, DateTimeImmutable $expected): ?StationLogEntry
    {
        $this->closeOutEndedHours($station, $expected);

        for ($i = 0; $i < 20; $i++) {
            // A later hour's line stays in its hour, and a line that may not
            // play in this slot stays in the log; see nextLineFor().
            $entry = $this->nextLineFor($station, $expected);
            if (!$entry instanceof StationLogEntry) {
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
            $entry->note = LinearLogRemovedFiles::REMOVED_NOTE;
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
