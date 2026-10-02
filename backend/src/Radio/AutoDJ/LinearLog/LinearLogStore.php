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
     * Put every planned line into the (rolled-back) simulation queue, in log
     * order, after the live queue rows.
     */
    /**
     * @param list<int> $excludeIds lines a rebuild is re-planning; they must not
     *     seed the simulation, or the planner would simply keep them.
     * @return list<int> the seeded log line ids
     */
    public function seedQueue(Station $station, array $excludeIds = []): array
    {
        $excluded = array_fill_keys($excludeIds, true);

        /** @var StationLogEntry[] $planned */
        $planned = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\StationLogEntry e
                WHERE e.station = :station AND e.status = :planned
                ORDER BY e.sequence ASC
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
        $ids = [];
        foreach ($planned as $entry) {
            if (null === $entry->media || isset($excluded[$entry->id])) {
                continue;
            }
            $ids[] = $entry->id;

            $row = $this->toQueueRow($station, $entry, null);
            $row->timestamp_cued = $cued->addMilliseconds(++$count);
            $this->em->persist($row);
        }

        $this->em->flush();

        return $ids;
    }

    /**
     * Delete planned lines the planner could no longer fit. Locked lines stay.
     *
     * @param list<int> $ids
     */
    public function removeUnplannable(Station $station, array $ids): int
    {
        if (empty($ids)) {
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

        $row = new StationQueue($station, Song::createFromText('Remote Playlist URL'));
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

            $entry = new StationLogEntry($station, (int)$data['planned_at'], ++$maxSequence);
            $this->applyRowData($entry, $data);
            $entry->duration = (float)$data['duration'];
            $this->em->persist($entry);
            $touched[] = [$entry, $data['entry_key']];
        }

        // One transaction: the plan the rebuild replaces goes out and the new plan
        // goes in together, or neither does.
        $this->em->wrapInTransaction(function () use ($station, $replacedIds): void {
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
            'media_type' => $entry->media->type ?? ($payload['media_type'] ?? 'music'),
            'source_type' => match (true) {
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

        $queued = [];
        foreach (
            $conn->fetchAllAssociative(
                'SELECT sq.log_entry_id, UNIX_TIMESTAMP(sq.timestamp_played) AS t,
                        sq.text, sq.title, sq.artist, sq.duration,
                        sq.playlist_id, sq.album, sp.name AS playlist_name
                FROM station_queue sq
                LEFT JOIN station_playlists sp ON sp.id = sq.playlist_id
                WHERE sq.station_id = ? AND sq.is_played = 0 AND sq.log_entry_id IS NOT NULL',
                [$station->id]
            ) as $row
        ) {
            $queued[(int)$row['log_entry_id']] = $row;
        }

        $ids = [];
        foreach ($entries as $entry) {
            if (!empty($entry['log_entry_id'])) {
                $ids[] = (int)$entry['log_entry_id'];
            }
        }
        $saved = [];
        if ([] !== $ids) {
            foreach (
                $conn->fetchAllAssociative(
                    'SELECT id, status, aired_at, note, is_locked FROM station_log_entries WHERE id IN (?)',
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

        // Collect exclusive windows from scheduled_programme markers AND from
        // scheduled playlists whose planned entries dominate a time slot. A live
        // queue entry from a different playlist inside such a window is a leak
        // that won't (or shouldn't) air.
        $exclusiveWindows = [];
        foreach ($entries as $e) {
            if ('scheduled_programme' === ($e['source_type'] ?? '')) {
                $exclusiveWindows[] = [
                    'start' => (int)($e['played_at'] ?? 0),
                    'end' => (int)($e['played_at'] ?? 0) + (int)ceil((float)($e['duration'] ?? 0)),
                    'playlist_id' => $e['playlist_id'] ?? null,
                ];
            }
        }

        // Build scheduled playlist ownership from the station's schedule data.
        $scheduledPlaylistIds = [];
        foreach ($station->playlists as $pl) {
            if ($pl->is_enabled && $pl->schedule_items->count() > 0) {
                $scheduledPlaylistIds[$pl->id] = true;
            }
        }

        if ([] !== $scheduledPlaylistIds) {
            // For each planned entry from a scheduled playlist, its time slot
            // belongs to that playlist — any live queue entry from another
            // playlist at the same time is a leak.
            foreach ($entries as $e) {
                $plId = $e['playlist_id'] ?? null;
                if (null === $plId || !isset($scheduledPlaylistIds[(int)$plId])) {
                    continue;
                }
                if ('scheduled_programme' === ($e['source_type'] ?? '')) {
                    continue;
                }
                $status = $e['log_status'] ?? '';
                if ($status === 'dropped' || $status === 'swapped') {
                    continue;
                }
                $start = (int)($e['played_at'] ?? 0);
                $end = $start + (int)ceil((float)($e['duration'] ?? 0));
                $exclusiveWindows[] = [
                    'start' => $start,
                    'end' => $end,
                    'playlist_id' => (int)$plId,
                ];
            }
        }

        if ([] !== $exclusiveWindows) {
            $entries = array_values(array_filter(
                $entries,
                static function (array $e) use ($exclusiveWindows): bool {
                    if ('scheduled_programme' === ($e['source_type'] ?? '')) {
                        return true;
                    }
                    $status = $e['log_status'] ?? '';
                    if ($status === 'dropped' || $status === 'swapped') {
                        return true;
                    }
                    $at = (int)($e['played_at'] ?? 0);
                    $plId = $e['playlist_id'] ?? null;
                    foreach ($exclusiveWindows as $w) {
                        if ($at >= $w['start'] && $at < $w['end']
                            && null !== $plId && null !== $w['playlist_id']
                            && (int)$plId !== (int)$w['playlist_id']) {
                            return false;
                        }
                    }
                    return true;
                },
            ));
        }

        // The current hour's history plus the configured hours ahead.
        [$from, $until] = self::window($station, $hours, $now);

        return array_values(array_filter(
            $entries,
            static fn(array $e): bool => (int)($e['played_at'] ?? 0) + (int)ceil((float)($e['duration'] ?? 0)) >= $from
                && (int)($e['played_at'] ?? 0) <= $until
        ));
    }

    /**
     * How deep the log actually runs *from right now*: this is the one place
     * anything that cares whether the log is "whole" has to ask, so the page,
     * the hourly top-up, and the repair pass can never disagree about it the
     * way the duration-sum ("program runtime") and the raw build snapshot did.
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
