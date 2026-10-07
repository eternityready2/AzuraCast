<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\PlaylistRemoteTypes;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Song;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationLogEntry;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
use App\Entity\StationQueue;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Doctrine\DBAL\LockMode;

/**
 * Persistence for the saved 24-hour linear log.
 *
 * The builder seeds its queue simulation with the lines already planned, so
 * they keep their order and only get re-timed; new lines are appended after
 * them. Only a rebuild request re-plans lines, and never inside the lock
 * window or a line an operator locked.
 */
final class LinearLogStore
{
    use EntityManagerAwareTrait;

    /** The next two hours are never re-planned by a rebuild. */
    public const int LOCK_SECONDS = 7200;

    /**
     * Ids of the planned lines a rebuild is allowed to re-plan: unlocked, and
     * past the lock window.
     *
     * A rebuild used to delete these up front, before simulating the new plan.
     * If the build then died -- an exception, or a worker restarted mid-build --
     * the log was left empty while the snapshot still showed the old plan, and
     * playout had nothing to follow. The ids are read here instead, excluded
     * from the simulation, and deleted only once the new plan is ready to be
     * written in their place.
     *
     * @return list<int>
     */
    public function unlockedPlanIds(Station $station, int $lockedUntil): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT e.id FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
                AND e.status = :planned
                AND e.is_locked = 0
                AND e.planned_at > :lockedUntil
            DQL
        )->setParameter('station', $station)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->setParameter('lockedUntil', $lockedUntil)
            ->getScalarResult();

        return array_map(static fn(array $row): int => (int)$row['id'], $rows);
    }

    /**
     * Delete planned lines a rebuild has replaced. Locked lines and lines that
     * have since been queued or aired are left alone.
     *
     * @param list<int> $ids
     */
    public function removeReplacedPlan(Station $station, array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int)$this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
                AND e.id IN (:ids)
                AND e.status = :planned
                AND e.is_locked = 0
            DQL
        )->setParameter('station', $station)
            ->setParameter('ids', $ids)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->execute();
    }

    /**
     * Lock the lines a rebuild replaces and make sure playout has not taken any
     * of them while the build ran. A schedule-change rebuild re-plans lines
     * inside the queue's reach: "It's Beginning To Rain" was queued 30s into
     * one, the new plan had its own line at the same 02:20:34, and both stayed.
     *
     * @param list<int> $ids
     */
    private function assertReplacedStillPlanned(Station $station, array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        /** @var list<array{id: int, status: string}> $rows */
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT e.id, e.status FROM App\Entity\StationLogEntry e
                WHERE e.station = :station AND e.id IN (:ids)
            DQL
        )->setParameter('station', $station)
            ->setParameter('ids', $ids)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult();

        foreach ($rows as $row) {
            if (StationLogEntry::STATUS_PLANNED !== $row['status']) {
                throw new LinearLogPlanConflict(
                    sprintf('Log line %d was %s while the build ran.', $row['id'], $row['status'])
                );
            }
        }
    }

    /**
     * Put every planned line into the (rolled-back) simulation queue, in log
     * order, after the live queue rows.
     *
     * @param list<int> $excludeIds lines a rebuild is re-planning; they must not
     *     seed the simulation, or the planner would simply keep them.
     * @param int|null $pinnedAfter on a rebuild, the end of the lock window: a
     *     locked line after it keeps its air time and is fitted around by
     *     applyPlan(). Seeded, it played straight after the lock window instead.
     * @return array<int, int> planned air time of each seeded line other than a
     *     scheduled programme, by log line id: the simulation must not play such
     *     a line before it, nor after its hour has ended (see
     *     {@see \App\Radio\AutoDJ\Queue::buildQueue()}).
     */
    public function seedQueue(Station $station, array $excludeIds = [], ?int $pinnedAfter = null): array
    {
        $excluded = array_fill_keys($excludeIds, true);

        /** @var StationLogEntry[] $planned */
        $planned = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\StationLogEntry e
                WHERE e.station = :station AND e.status = :planned
                ORDER BY e.planned_at ASC, e.sequence ASC
            DQL
        )->setParameter('station', $station)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->getResult();

        // The queue runs in timestamp_cued order, and live rows are cued in
        // the future. Cue the planned lines after the last live row so they
        // follow it instead of jumping ahead of it.
        $lastCued = $this->em->createQuery(
            <<<'DQL'
                SELECT MAX(sq.timestamp_cued) FROM App\Entity\StationQueue sq
                WHERE sq.station = :station AND sq.is_played = 0
            DQL
        )->setParameter('station', $station)
            ->getSingleScalarResult();

        $cued = CarbonImmutable::now('UTC');
        if (null !== $lastCued) {
            $cued = CarbonImmutable::parse((string)$lastCued, 'UTC')->max($cued)->addSecond();
        }
        $count = 0;
        $plannedAt = [];
        foreach ($planned as $entry) {
            if (null === $entry->media || isset($excluded[$entry->id])) {
                continue;
            }
            if (null !== $pinnedAfter && $entry->is_locked && $entry->planned_at > $pinnedAfter) {
                continue;
            }
            // A scheduled programme keeps the air its window gives it; playout
            // never drops one as a leftover while that window is open.
            if (!$this->isScheduledProgramme($entry)) {
                $plannedAt[$entry->id] = $entry->planned_at;
            }

            $row = $this->toQueueRow($station, $entry, null);
            $row->timestamp_cued = $cued->addMilliseconds(++$count);
            $this->em->persist($row);
        }

        $this->em->flush();

        return $plannedAt;
    }

    /**
     * Drop planned lines the planner could no longer keep. Locked lines stay.
     *
     * Dropped, not deleted: a line that silently vanished left a hole with no
     * record of why. The 7-9am Morning Show took every line after it on each
     * build this way (2026-10-05 to 10-07), and nothing in the log said so.
     *
     * @param list<int> $ids
     */
    public function dropUnplannable(Station $station, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return (int)$this->em->createQuery(
            <<<'DQL'
                UPDATE App\Entity\StationLogEntry e
                SET e.status = :dropped, e.note = :note
                WHERE e.station = :station
                AND e.id IN (:ids)
                AND e.status = :planned
                AND e.is_locked = 0
            DQL
        )->setParameter('dropped', StationLogEntry::STATUS_DROPPED)
            ->setParameter('note', 'Dropped: the planner could not keep it at its planned time')
            ->setParameter('station', $station)
            ->setParameter('ids', $ids)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->execute();
    }

    /**
     * Queue row that plays a log line exactly as planned.
     */
    public function toQueueRow(Station $station, StationLogEntry $entry, ?DateTimeInterface $markPlayedAt): StationQueue
    {
        $media = $entry->media;
        if (!$media instanceof StationMedia) {
            // A scheduled remote-stream programme has no library file; it airs
            // through a custom-URI queue row. Queue timing caps its duration to
            // the window that remains, so a full-window value is fine here.
            return $this->toRemoteProgrammeRow($station, $entry);
        }

        $row = StationQueue::fromMedia($station, $media);
        $row->playlist = $entry->playlist;
        $row->log_entry_id = $entry->id;

        $payload = $entry->payload ?? [];
        if (!empty($payload['clock_wheel_id'])) {
            $row->clock_wheel = $this->em->getReference(StationClockWheel::class, (int)$payload['clock_wheel_id']);
        }
        $row->clock_wheel_max_play_seconds = isset($payload['clock_wheel_max_play_seconds'])
            ? (int)$payload['clock_wheel_max_play_seconds']
            : null;
        $row->clock_wheel_schedule_mode = $payload['clock_wheel_schedule_mode'] ?? null;
        $row->clock_wheel_enforce_cap = (bool)($payload['clock_wheel_enforce_cap'] ?? false);
        $row->clock_wheel_stretch_ratio = isset($payload['clock_wheel_stretch_ratio'])
            ? (float)$payload['clock_wheel_stretch_ratio']
            : null;
        $row->clock_wheel_legal_id_substitute = (bool)($payload['clock_wheel_legal_id_substitute'] ?? false);
        $row->playlist_chain = $payload['playlist_chain'] ?? null;

        // Keep rotation history honest, as the random picker does.
        if (null !== $markPlayedAt && null !== $entry->playlist) {
            $spm = $this->em->getRepository(StationPlaylistMedia::class)->findOneBy([
                'playlist' => $entry->playlist,
                'media' => $media,
            ]);
            if ($spm instanceof StationPlaylistMedia) {
                $spm->played($markPlayedAt->getTimestamp());
                $this->em->persist($spm);
            }
        }

        return $row;
    }

    /**
     * A saved log line that is a scheduled programme the AutoDJ can air on its
     * own -- a remote-stream window, which plays through a custom-URI queue row
     * and so has no library file. Anything scheduled in AzuraCast must be
     * honored by the log, so these lines are never treated as missing files.
     */
    public function isScheduledProgramme(StationLogEntry $entry): bool
    {
        if ('scheduled_programme' !== ($entry->payload['source_type'] ?? null)) {
            return false;
        }

        $playlist = $entry->playlist;

        return $playlist instanceof StationPlaylist
            && PlaylistSources::RemoteUrl === $playlist->source
            && PlaylistRemoteTypes::Stream === ($playlist->remote_type ?? PlaylistRemoteTypes::Stream)
            && null !== $playlist->remote_url;
    }

    /**
     * Queue row that airs a scheduled remote-stream programme block.
     */
    private function toRemoteProgrammeRow(Station $station, StationLogEntry $entry): StationQueue
    {
        $playlist = $entry->playlist;
        assert($playlist instanceof StationPlaylist);

        $row = new StationQueue($station, Song::createFromText($playlist->name));
        $row->playlist = $playlist;
        $row->autodj_custom_uri = $playlist->remote_url;
        $row->duration = max(1.0, (float)$entry->duration);
        $row->log_entry_id = $entry->id;

        return $row;
    }

    /**
     * Plain-data description of a simulated queue row, captured before the
     * simulation is rolled back.
     *
     * @return array<string, mixed>
     */
    public function describeRow(StationQueue $row, int $plannedAt, bool $isLive, ?string $entryKey): array
    {
        return [
            // The report entry's stable 'id'. A positional index cannot be used:
            // the builder re-sorts its entries by air time after all rows are
            // described, which would silently rebind every log line.
            'entry_key' => $entryKey,
            'log_entry_id' => $row->log_entry_id,
            'is_live' => $isLive,
            'skip' => $row->top_of_hour_legal_id
                || null !== $row->request
                || null !== $row->autodj_custom_uri
                || null === $row->media,
            'planned_at' => $plannedAt,
            'duration' => max(1.0, (float)($row->duration ?? $row->media->length ?? 0.0)),
            'media_id' => $row->media?->id,
            'playlist_id' => $row->playlist?->id,
            'title' => $row->title,
            'artist' => $row->artist,
            'text' => $row->text,
            'payload' => self::payloadForQueueRow($row),
        ];
    }

    /**
     * The log entry payload describing a queue row: which song it actually is
     * (song_id/album, for the ON AIR match) plus clock wheel/playlist-chain
     * context. Shared so a row re-describing an entry after the fact (a
     * swap/replace reconciled once it airs) builds the exact same shape as a
     * freshly planned one, rather than leaving stale pre-swap data behind.
     *
     * @return array<string, mixed>
     */
    public static function payloadForQueueRow(StationQueue $row): array
    {
        return [
            'clock_wheel_id' => $row->clock_wheel?->id,
            'clock_wheel' => $row->clock_wheel?->name,
            'clock_wheel_max_play_seconds' => $row->clock_wheel_max_play_seconds,
            'clock_wheel_schedule_mode' => $row->clock_wheel_schedule_mode,
            'clock_wheel_enforce_cap' => $row->clock_wheel_enforce_cap,
            'clock_wheel_stretch_ratio' => $row->clock_wheel_stretch_ratio,
            'clock_wheel_legal_id_substitute' => $row->clock_wheel_legal_id_substitute,
            'playlist_chain' => $row->playlist_chain,
            'album' => $row->album,
            'song_id' => $row->song_id,
            'media_type' => $row->media?->type,
        ];
    }

    /**
     * Save the simulated plan into the log and return the report entries,
     * annotated with each line's log status, plus this hour's as-run lines.
     *
     * @param list<array<string, mixed>> $logRows
     * @param list<array<string, mixed>> $entries
     * @param list<int> $replacedIds planned lines this rebuild replaces; deleted
     *     in the same transaction that writes the new plan, so a failure cannot
     *     leave the log empty.
     * @return list<array<string, mixed>>
     */
    public function applyPlan(
        Station $station,
        array $logRows,
        array $entries,
        array $replacedIds = []
    ): array {
        $maxSequence = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT MAX(e.sequence) FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
            DQL
        )->setParameter('station', $station)
            ->getSingleScalarResult();

        usort($logRows, static fn(array $a, array $b): int => $a['planned_at'] <=> $b['planned_at']);

        $positionByKey = [];
        foreach ($entries as $position => $entry) {
            $key = $entry['id'] ?? null;
            if (null !== $key) {
                $positionByKey[(string)$key] = $position;
            }
        }

        /** @var list<array{StationLogEntry, ?string}> $touched */
        $touched = [];

        $pinned = $this->pinnedLines($station, $logRows);
        /** @var array<string, true> $displacedKeys */
        $displacedKeys = [];

        foreach ($logRows as $data) {
            if ($data['skip']) {
                continue;
            }

            // The builder removed this row from the report because a strict
            // programme's native source owns its air time. Saving it as a planned
            // line handed it to playout anyway, so the same episode was queued a
            // second time on top of the strict programme (Faith Horizons,
            // 2026-09-30 17:00 and 17:00:03).
            if (null === $data['entry_key']) {
                if (null !== $data['log_entry_id']) {
                    $entry = $this->em->find(StationLogEntry::class, (int)$data['log_entry_id']);
                    if ($entry instanceof StationLogEntry && $entry->isOpen() && !$entry->is_locked) {
                        if (null !== $entry->queue_id) {
                            $queueRow = $this->em->find(StationQueue::class, $entry->queue_id);
                            if ($queueRow instanceof StationQueue && !$queueRow->is_played) {
                                $this->em->remove($queueRow);
                            }
                        }

                        $entry->status = StationLogEntry::STATUS_DROPPED;
                        $entry->note = 'Dropped: a strict programme owns this air time';
                        $entry->queue_id = null;
                        $this->em->persist($entry);
                    }
                }

                continue;
            }

            if (null !== $data['log_entry_id']) {
                $entry = $this->em->find(StationLogEntry::class, (int)$data['log_entry_id']);
                if (!$entry instanceof StationLogEntry) {
                    continue;
                }

                if ($entry->isOpen()) {
                    $entry->planned_at = (int)$data['planned_at'];
                    $entry->duration = (float)$data['duration'];

                    // The planner fitted a different song into this line (the
                    // final-song swap). Keep it unless the line is locked. A
                    // queued line is included: the swap can re-fit a row that is
                    // already queued, and the log must name what will air.
                    if (
                        !$entry->is_locked
                        && null !== $data['media_id']
                        && $entry->media?->id !== $data['media_id']
                    ) {
                        $this->applyRowData($entry, $data);
                        $entry->note = 'Adjusted by the planner to land on the top of the hour';
                    }
                    $this->em->persist($entry);
                }

                $touched[] = [$entry, $data['entry_key']];
                continue;
            }

            if ($data['is_live']) {
                // Already queued before the log took over; it is recorded as
                // an as-run line when it airs.
                continue;
            }

            // A locked line holds its air time. The simulation cannot place it
            // there, so it fills that time as if it were free; saving that fill
            // put the same programme in the log twice (CMS Week 22 at Mon 11:00,
            // lines 69767 and 70058).
            if ($this->overlapsPinned($data, $pinned)) {
                $displacedKeys[(string)$data['entry_key']] = true;
                continue;
            }

            $entry = new StationLogEntry($station, (int)$data['planned_at'], ++$maxSequence);
            $this->applyRowData($entry, $data);
            $entry->duration = (float)$data['duration'];
            $this->em->persist($entry);
            $touched[] = [$entry, $data['entry_key']];
        }

        // One transaction: the plan the rebuild replaces goes out and the new plan
        // goes in together, or neither does.
        $this->em->wrapInTransaction(function () use ($station, $replacedIds): void {
            $this->assertReplacedStillPlanned($station, $replacedIds);
            $this->removeReplacedPlan($station, $replacedIds);
            $this->em->flush();
        });

        foreach ($touched as [$entry, $key]) {
            if (null === $key || !isset($positionByKey[$key])) {
                continue;
            }

            $position = $positionByKey[$key];
            $entries[$position] = [...$entries[$position], ...$this->logFields($entry)];
        }

        if ([] !== $displacedKeys) {
            $entries = array_values(array_filter(
                $entries,
                static fn(array $entry): bool => !isset($displacedKeys[(string)($entry['id'] ?? '')]),
            ));
        }
        foreach ($pinned as $entry) {
            $entries[] = $this->mapEntry($entry);
        }

        // This hour's as-run lines (aired, swapped, replaced, dropped).
        $hourStart = CarbonImmutable::now($station->getTimezoneObject())->startOfHour()->getTimestamp();

        /** @var StationLogEntry[] $history */
        $history = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
                AND e.status NOT IN (:open)
                AND (e.aired_at >= :hourStart OR (e.aired_at IS NULL AND e.planned_at >= :hourStart))
            DQL
        )->setParameter('station', $station)
            ->setParameter('open', [StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED])
            ->setParameter('hourStart', $hourStart)
            ->getResult();

        foreach ($history as $entry) {
            $entries[] = $this->mapEntry($entry);
        }

        usort(
            $entries,
            static fn(array $a, array $b): int => ((int)($a['played_at'] ?? 0)) <=> ((int)($b['played_at'] ?? 0)),
        );

        return $entries;
    }

    /**
     * Locked lines still to air that the simulation did not carry: their air
     * time is fixed, so the rest of the plan has to fit around them.
     *
     * @param list<array<string, mixed>> $logRows
     * @return list<StationLogEntry>
     */
    private function pinnedLines(Station $station, array $logRows): array
    {
        $carried = [];
        foreach ($logRows as $data) {
            if (null !== $data['log_entry_id']) {
                $carried[(int)$data['log_entry_id']] = true;
            }
        }

        /** @var StationLogEntry[] $locked */
        $locked = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
                AND e.status IN (:open)
                AND e.is_locked = 1
                AND e.media IS NOT NULL
                AND e.planned_at + e.duration > :now
            DQL
        )->setParameter('station', $station)
            ->setParameter('open', [StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED])
            ->setParameter('now', time())
            ->getResult();

        return array_values(array_filter(
            $locked,
            static fn(StationLogEntry $entry): bool => !isset($carried[$entry->id]),
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<StationLogEntry> $pinned
     */
    private function overlapsPinned(array $data, array $pinned): bool
    {
        $start = (int)$data['planned_at'];
        $end = $start + (float)$data['duration'];

        foreach ($pinned as $entry) {
            if ($start < $entry->planned_at + $entry->duration && $end > $entry->planned_at) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $data */
    private function applyRowData(StationLogEntry $entry, array $data): void
    {
        $entry->media = null !== $data['media_id']
            ? $this->em->getReference(StationMedia::class, (int)$data['media_id'])
            : null;
        $entry->playlist = null !== $data['playlist_id']
            ? $this->em->getReference(StationPlaylist::class, (int)$data['playlist_id'])
            : null;
        $entry->title = self::cut($data['title']);
        $entry->artist = self::cut($data['artist']);
        $entry->text = self::cut($data['text']);
        $entry->payload = $data['payload'];
    }

    /** @return array<string, mixed> */
    public function logFields(StationLogEntry $entry): array
    {
        return [
            'log_entry_id' => $entry->id,
            'log_status' => $entry->status,
            'log_note' => $entry->note,
            'aired_at' => $entry->aired_at,
            'is_locked' => $entry->is_locked,
        ];
    }

    /**
     * A log line in the same shape as a Linear Log report entry.
     *
     * @return array<string, mixed>
     */
    public function mapEntry(StationLogEntry $entry): array
    {
        $payload = $entry->payload ?? [];
        $isRequest = null !== $entry->note && str_contains($entry->note, 'listener request');
        // A scheduled programme block stays a programme line in every state;
        // read back as-run it used to turn into a "Music" line.
        $isProgramme = 'scheduled_programme' === ($payload['source_type'] ?? null);

        return [
            'id' => 'log-' . $entry->id,
            'queue_id' => $entry->queue_id,
            'song_id' => $payload['song_id'] ?? null,
            'played_at' => $entry->aired_at ?? $entry->planned_at,
            'cued_at' => $entry->created_at,
            'duration' => max(1.0, $entry->duration),
            'title' => $entry->title,
            'artist' => $entry->artist,
            'album' => $payload['album'] ?? null,
            'text' => $entry->text,
            'playlist' => $entry->playlist?->name,
            'playlist_id' => $entry->playlist?->id,
            'playlist_chain' => $payload['playlist_chain'] ?? null,
            'clock_wheel' => $payload['clock_wheel'] ?? null,
            'clock_wheel_id' => $payload['clock_wheel_id'] ?? null,
            'media_type' => $isProgramme
                ? 'programme'
                : ($entry->media->type ?? ($payload['media_type'] ?? 'music')),
            'source_type' => match (true) {
                $isProgramme => 'scheduled_programme',
                $isRequest => 'request',
                !empty($payload['clock_wheel_id']) => 'clock_wheel',
                null !== $entry->playlist => 'playlist',
                default => 'autodj',
            },
            'is_request' => $isRequest,
            'is_live_queue' => false,
            'sent_to_autodj' => true,
            'top_of_hour_legal_id' => false,
            'autodj_custom_uri' => null,
            'clock_wheel_schedule_mode' => $payload['clock_wheel_schedule_mode'] ?? null,
            'clock_wheel_enforce_cap' => (bool)($payload['clock_wheel_enforce_cap'] ?? false),
            'clock_wheel_stretch_ratio' => $payload['clock_wheel_stretch_ratio'] ?? null,
            'clock_wheel_legal_id_substitute' => (bool)($payload['clock_wheel_legal_id_substitute'] ?? false),
            'hour_boundary_enforce_cap' => false,
            'hour_boundary_max_play_seconds' => null,
            'top_of_hour_pre_id_fade' => false,
            ...$this->logFields($entry),
        ];
    }

    /**
     * Snapshot entries re-timed from live playback, the way FM automation keeps
     * the log's times current without rebuilding it: queued lines take the live
     * queue's time (and its song, if the Top-of-Hour swap changed it), later
     * lines in the same hour move by the same drift, and lines in later hours
     * keep their saved time (each hour is anchored to its ID). Statuses come
     * from the saved log. Only now .. now + $hours is returned.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function liveEntries(Station $station, array $entries, int $hours): array
    {
        $conn = $this->em->getConnection();
        $now = time();
        $tz = $station->getTimezoneObject();

        // Include recently-played rows (last 10 min) so the currently-on-air
        // song stays in the map while ReconcileLinearLogTask catches up and
        // sets aired_at. Without this, the entry drops out the moment
        // Liquidsoap marks is_played=1 and the ON AIR badge shows the wrong
        // line for up to a minute.
        $recentCutoff = $now - 600;
        $queued = [];
        foreach (
            $conn->fetchAllAssociative(
                'SELECT sq.log_entry_id, UNIX_TIMESTAMP(sq.timestamp_played) AS t,
                        sq.text, sq.title, sq.artist, sq.duration, sq.song_id,
                        sq.playlist_id, sq.album, sp.name AS playlist_name
                FROM station_queue sq
                LEFT JOIN station_playlists sp ON sp.id = sq.playlist_id
                WHERE sq.station_id = ? AND sq.log_entry_id IS NOT NULL
                AND (sq.is_played = 0
                     OR (sq.is_played = 1
                         AND sq.timestamp_played >= FROM_UNIXTIME(?)))',
                [$station->id, $recentCutoff]
            ) as $row
        ) {
            $queued[(int)$row['log_entry_id']] = $row;
        }

        // The current hour's history plus the configured hours ahead.
        [$from, $until] = self::window($station, $hours, $now);

        $ids = [];
        foreach ($entries as $entry) {
            if (!empty($entry['log_entry_id'])) {
                $ids[] = (int)$entry['log_entry_id'];
            }
        }

        // Every saved line is shown, not only the ones the last build wrote. The
        // build's snapshot is a copy taken when it ran: a song the AutoDJ filled
        // in live, or a line refilled after a drop, is in the log the moment it
        // is written, and the page has to show it then, not at the next build.
        foreach ($this->savedLinesSince($station, $ids, $from, $until) as $line) {
            $entries[] = $this->mapEntry($line);
            $ids[] = (int)$line->id;
        }

        $saved = [];
        if ([] !== $ids) {
            foreach (
                $conn->fetchAllAssociative(
                    'SELECT id, status, aired_at, note, is_locked, title, artist, text, payload
                    FROM station_log_entries WHERE id IN (?)',
                    [$ids],
                    [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                ) as $row
            ) {
                $saved[(int)$row['id']] = $row;
            }
        }

        usort($entries, static fn(array $a, array $b): int => ((int)($a['played_at'] ?? 0)) <=> ((int)($b['played_at'] ?? 0)));

        // A line is re-timed only from its own live-queue row. The saved log is
        // the authority for every other line.
        //
        // This used to take the delta of whichever queued line it saw and push
        // it onto every planned line left in the hour. One queue row that has
        // been re-planned since the log was built is enough to drag the rest of
        // the hour backwards -- a -20m delta moved the evening's music inside a
        // programme's exclusive window, where the leak filter below then deleted
        // it, leaving a 23-minute hole that existed in neither the saved log nor
        // the queue and reading ~23h instead of 24h on the page.
        foreach ($entries as &$entry) {
            $id = (int)($entry['log_entry_id'] ?? 0);
            if ($id > 0 && isset($saved[$id])) {
                $entry['log_status'] = $saved[$id]['status'];
                $entry['log_note'] = $saved[$id]['note'];
                $entry['is_locked'] = (bool)$saved[$id]['is_locked'];
                // The saved line names what it plays now: a hand replacement, or
                // what the Top-of-Hour swap aired. The snapshot keeps the name
                // from its build, so an aired replacement showed the old song.
                if (null !== $saved[$id]['text'] && $saved[$id]['text'] !== ($entry['text'] ?? null)) {
                    $entry['text'] = $saved[$id]['text'];
                    $entry['title'] = $saved[$id]['title'];
                    $entry['artist'] = $saved[$id]['artist'];
                    $savedSongId = json_decode((string)$saved[$id]['payload'], true)['song_id'] ?? null;
                    if (is_string($savedSongId) && '' !== $savedSongId) {
                        $entry['song_id'] = $savedSongId;
                    }
                }
                if (null !== $saved[$id]['aired_at']) {
                    $entry['aired_at'] = (int)$saved[$id]['aired_at'];
                    $entry['played_at'] = (int)$saved[$id]['aired_at'];
                    continue;
                }
            }

            if ($id > 0 && isset($queued[$id])) {
                $live = $queued[$id];
                $liveAt = (int)$live['t'];
                $entry['played_at'] = $liveAt;
                $entry['is_live_queue'] = true;
                if (($live['text'] ?? null) !== ($entry['text'] ?? null)) {
                    $entry['text'] = $live['text'];
                    $entry['title'] = $live['title'];
                    $entry['artist'] = $live['artist'];
                    // ON AIR is matched by song id.
                    if (null !== ($live['song_id'] ?? null)) {
                        $entry['song_id'] = $live['song_id'];
                    }
                    if (null !== $live['duration']) {
                        $entry['duration'] = max(1.0, (float)$live['duration']);
                    }
                    // A replacement song may come from a different playlist
                    // and album; keep the report consistent with what airs.
                    if (null !== ($live['playlist_id'] ?? null)) {
                        $entry['playlist_id'] = (int)$live['playlist_id'];
                        $entry['playlist'] = $live['playlist_name'];
                    }
                    if (null !== ($live['album'] ?? null)) {
                        $entry['album'] = $live['album'];
                    }
                }
                continue;
            }
        }
        unset($entry);

        usort($entries, static fn(array $a, array $b): int => ((int)($a['played_at'] ?? 0)) <=> ((int)($b['played_at'] ?? 0)));

        $entries = self::floatSoftLines(self::showAfterNews($entries), $now);

        // Schedule exclusivity (a live-queue fill-in leaking into a scheduled
        // playlist's window) is enforced by LinearLogRules::apply(), which runs
        // every minute against the authoritative Scheduler, drops the line with
        // a recorded reason, and respects operator locks. That is the single
        // authority; no ad-hoc re-derivation of windows happens here. (A removed
        // filter used to rebuild windows from this page's own entries using
        // queue-shifted played_at times, which could disagree with the saved log
        // and silently blank lines that were never actually dropped.)

        return array_values(array_filter(
            $entries,
            static fn(array $e): bool => (int)($e['played_at'] ?? 0) + (int)ceil((float)($e['duration'] ?? 0)) >= $from
                && (int)($e['played_at'] ?? 0) <= $until
        ));
    }

    /**
     * Saved lines airing in $from .. $until that are not among $knownIds.
     *
     * @param list<int> $knownIds
     * @return list<StationLogEntry>
     */
    private function savedLinesSince(Station $station, array $knownIds, int $from, int $until): array
    {
        // Reach back far enough to catch a programme that began before the
        // window and is still on air; the caller trims by end time.
        $reachBack = 6 * 3600;

        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(StationLogEntry::class, 'e')
            ->where('e.station = :station')
            ->andWhere('COALESCE(e.aired_at, e.planned_at) >= :from')
            ->andWhere('COALESCE(e.aired_at, e.planned_at) <= :until')
            ->setParameter('station', $station)
            ->setParameter('from', $from - $reachBack)
            ->setParameter('until', $until);

        if ([] !== $knownIds) {
            $qb->andWhere('e.id NOT IN (:known)')
                ->setParameter('known', $knownIds);
        }

        /** @var list<StationLogEntry> */
        return $qb->getQuery()->getResult();
    }

    /**
     * A show due while the Station ID or a News bulletin airs is drawn starting
     * when they are done. On air the ID and the bulletin always go first and a
     * show scheduled at :00 waits for them -- Faith Horizons, Wed 2026-09-30: ID
     * 16:59:59, News 17:00:36, show 17:03:25 -- while the plan pins the show to
     * its schedule time, so the page drew it on top of them. The lines after the
     * show follow it in {@see floatSoftLines()}. Display only: nothing here is
     * saved, and playout never reads it.
     *
     * @param list<array<string, mixed>> $entries sorted by played_at
     * @return list<array<string, mixed>> sorted by played_at
     */
    private static function showAfterNews(array $entries): array
    {
        $at = static fn(array $e): int => (int)($e['played_at'] ?? 0);

        // In air order, so a show moved past the ID is then checked against the
        // bulletin that follows it.
        foreach ($entries as $marker) {
            $source = $marker['source_type'] ?? null;
            if ('ai_news' !== $source && 'top_of_hour_id' !== $source) {
                continue;
            }

            $markerStart = $at($marker);
            $markerEnd = $markerStart + (int)ceil((float)($marker['duration'] ?? 0));

            foreach ($entries as $i => $entry) {
                if (
                    'scheduled_programme' === ($entry['source_type'] ?? null)
                    && !isset($entry['aired_at'])
                    && self::isAirable($entry)
                    && $at($entry) >= $markerStart
                    && $at($entry) < $markerEnd
                ) {
                    $entries[$i]['played_at'] = $markerEnd;
                }
            }
        }

        usort($entries, static fn(array $a, array $b): int => $at($a) <=> $at($b));

        return $entries;
    }

    /**
     * Soft lines float, hard events stay put -- how FM automation keeps a log's
     * times true without rebuilding it. Playout takes the log's lines in order,
     * each starting when the one before it ends, so a line that has not started
     * yet is drawn starting when the line before it ends. Hard events keep their
     * own time and start a new chain: what is already on air, and the timed
     * events -- the Station ID, News, scheduled programmes and the build's other
     * markers. When the air ran 7m51s ahead of the plan (2026-10-07 14:40:59)
     * the next planned liner still showed its plan time of 14:48:50, and the
     * empty time between read as a hole. A run that overruns the next hard event
     * shows the overrun, which the Top-of-Hour swap settles on air. Display
     * only: nothing here is saved, and playout never reads it.
     *
     * @param list<array<string, mixed>> $entries sorted by played_at
     * @return list<array<string, mixed>> sorted by played_at
     */
    private static function floatSoftLines(array $entries, int $now): array
    {
        $end = static fn(array $e): int => (int)$e['played_at'] + (int)ceil((float)($e['duration'] ?? 0));

        // A line planned at the same second as a hard event airs after it: the
        // song held for the new hour is planned at the ID's 03:59:59 and opens
        // the hour once the ID ends.
        usort(
            $entries,
            static fn(array $a, array $b): int => [(int)($a['played_at'] ?? 0), self::isSoft($a, $now)]
                <=> [(int)($b['played_at'] ?? 0), self::isSoft($b, $now)]
        );

        $cursor = null;
        // Hard events can overlap (a bulletin drawn inside a show), so a chain
        // restarts from the latest end among them, not the last one listed.
        $hardEnd = null;
        foreach ($entries as $i => $entry) {
            if (!self::isAirable($entry)) {
                continue;
            }

            if (self::isSoft($entry, $now)) {
                if (null !== $cursor) {
                    $entries[$i]['played_at'] = $cursor;
                }
                $cursor = $end($entries[$i]);
                continue;
            }

            $hardEnd = max($hardEnd ?? PHP_INT_MIN, $end($entry));
            $cursor = $hardEnd;
        }

        usort(
            $entries,
            static fn(array $a, array $b): int => ((int)($a['played_at'] ?? 0)) <=> ((int)($b['played_at'] ?? 0))
        );

        return $entries;
    }

    /**
     * A line playout takes in turn that has not started yet: in the saved log,
     * not on air, and not a timed event (a scheduled programme or a marker).
     *
     * @param array<string, mixed> $entry
     */
    private static function isSoft(array $entry, int $now): bool
    {
        if (
            empty($entry['log_entry_id'])
            || isset($entry['aired_at'])
            || 'scheduled_programme' === ($entry['source_type'] ?? null)
        ) {
            return false;
        }

        return match ($entry['log_status'] ?? null) {
            StationLogEntry::STATUS_PLANNED => true,
            // In the live queue: soft until it starts.
            StationLogEntry::STATUS_QUEUED => (int)($entry['played_at'] ?? 0) > $now,
            default => false,
        };
    }

    /** @param array<string, mixed> $entry */
    private static function isAirable(array $entry): bool
    {
        return !in_array(
            $entry['log_status'] ?? null,
            [StationLogEntry::STATUS_DROPPED, StationLogEntry::STATUS_SWAPPED],
            true
        );
    }

    /**
     * How deep the log actually runs *from right now*: this is the one place
     * anything that cares whether the log is "whole" has to ask, so the page and
     * the station health check can never disagree about it the way the
     * duration-sum ("program runtime") and the raw build snapshot did.
     *
     * Deliberately not the same window {@see liveEntries()} filters by --
     * that window starts at the top of the current hour so the page can show
     * what already aired, but depth has to start at now: a few seconds of
     * rounding in already-aired history must never make a whole log measure
     * as broken.
     *
     * Takes the same live-rendered entries {@see liveEntries()} produces --
     * dropped/swapped lines are not airable and must be excluded by the
     * caller the same way liveEntries() already excludes them from the page.
     *
     * @param list<array<string, mixed>> $liveEntries
     */
    public function measureCoverage(Station $station, array $liveEntries, int $hours): LinearLogCoverage
    {
        // Depth is measured from this instant forward, never from the top of
        // the current hour. The past cannot be repaired, and a few seconds of
        // write/rounding slack in history already aired (the Top-of-Hour ID's
        // exact end time, a crossfade write landing a beat late) would
        // otherwise read as a hole every single hour, forever, and never let
        // the log be measured as whole.
        $now = time();
        [, $until] = self::window($station, $hours, $now);
        $from = $now;

        $airable = array_filter(
            $liveEntries,
            static fn(array $e): bool => !in_array($e['log_status'] ?? null, ['dropped', 'swapped'], true),
        );

        $spans = array_map(
            static function (array $e): array {
                $start = (int)($e['played_at'] ?? 0);
                return [
                    'start' => $start,
                    'end' => $start + (int)ceil((float)($e['duration'] ?? 0)),
                    'media_type' => $e['media_type'] ?? null,
                ];
            },
            array_values($airable),
        );
        usort($spans, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        return LinearLogCoverage::measure($this->absorbPreIdSlack($spans), $from, $until);
    }

    /**
     * The queue simulation projects a static plan; the real Top-of-Hour swap
     * stretches or squeezes the last song, or falls back to Liquidsoap's own
     * fit/pre-fade, to land exactly on the ID at :59:59 (see the station's
     * standing Top-of-Hour rules). None of that runtime fit-up is visible to
     * this projection, so a short, bounded gap immediately before an ID marker
     * is Liquidsoap's job, not a dead-air hole -- counting it as one would mark
     * every single hour boundary as a failure, forever, even on a station
     * playing cleanly.
     *
     * @param list<array{start: int, end: int, media_type: string|null}> $spans Sorted by start.
     * @return list<array{start: int, end: int}>
     */
    private function absorbPreIdSlack(array $spans): array
    {
        /** Generous enough for normal swap/fit variance, nowhere near large
         *  enough to hide a real hole (every one fixed so far has run minutes). */
        $tolerance = 30;

        for ($i = 1, $count = count($spans); $i < $count; $i++) {
            if ('id' !== $spans[$i]['media_type']) {
                continue;
            }
            $gap = $spans[$i]['start'] - $spans[$i - 1]['end'];
            if ($gap > 0 && $gap <= $tolerance) {
                $spans[$i]['start'] = $spans[$i - 1]['end'];
            }
        }

        return array_map(
            static fn(array $s): array => ['start' => $s['start'], 'end' => $s['end']],
            $spans,
        );
    }

    /** @return array{0: int, 1: int} [from, until] */
    private static function window(Station $station, int $hours, ?int $now = null): array
    {
        $now ??= time();
        $from = CarbonImmutable::createFromTimestamp($now, $station->getTimezoneObject())
            ->startOfHour()->getTimestamp();

        return [$from, $now + $hours * 3600];
    }

    private static function cut(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        return mb_substr((string)$value, 0, 255);
    }
}
