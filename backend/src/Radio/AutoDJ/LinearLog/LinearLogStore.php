<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
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

    public function clearUnlockedPlan(Station $station, int $lockedUntil): int
    {
        return (int)$this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
                AND e.status = :planned
                AND e.is_locked = 0
                AND e.planned_at > :lockedUntil
            DQL
        )->setParameter('station', $station)
            ->setParameter('planned', StationLogEntry::STATUS_PLANNED)
            ->setParameter('lockedUntil', $lockedUntil)
            ->execute();
    }

    /**
     * Put every planned line into the (rolled-back) simulation queue, in log
     * order, after the live queue rows.
     */
    public function seedQueue(Station $station): int
    {
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

        $cued = CarbonImmutable::now('UTC');
        $count = 0;
        foreach ($planned as $entry) {
            if (null === $entry->media) {
                continue;
            }

            $row = $this->toQueueRow($station, $entry, null);
            $row->timestamp_cued = $cued->addMilliseconds(++$count);
            $this->em->persist($row);
        }

        $this->em->flush();

        return $count;
    }

    /**
     * Queue row that plays a log line exactly as planned.
     */
    public function toQueueRow(Station $station, StationLogEntry $entry, ?DateTimeInterface $markPlayedAt): StationQueue
    {
        $media = $entry->media;
        assert($media instanceof StationMedia);

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
     * Plain-data description of a simulated queue row, captured before the
     * simulation is rolled back.
     *
     * @return array<string, mixed>
     */
    public function describeRow(StationQueue $row, int $plannedAt, bool $isLive, ?int $entryIndex): array
    {
        return [
            'index' => $entryIndex,
            'log_entry_id' => $row->log_entry_id,
            'is_live' => $isLive,
            'skip' => $row->top_of_hour_legal_id
                || null !== $row->request
                || null !== $row->autodj_custom_uri
                || null === $row->media,
            'planned_at' => $plannedAt,
            'duration' => max(1.0, (float)($row->duration ?? $row->media?->length ?? 0.0)),
            'media_id' => $row->media?->id,
            'playlist_id' => $row->playlist?->id,
            'title' => $row->title,
            'artist' => $row->artist,
            'text' => $row->text,
            'payload' => [
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
            ],
        ];
    }

    /**
     * Save the simulated plan into the log and return the report entries,
     * annotated with each line's log status, plus this hour's as-run lines.
     *
     * @param list<array<string, mixed>> $logRows
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function applyPlan(Station $station, array $logRows, array $entries): array
    {
        $maxSequence = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT MAX(e.sequence) FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
            DQL
        )->setParameter('station', $station)
            ->getSingleScalarResult();

        usort($logRows, static fn(array $a, array $b): int => $a['planned_at'] <=> $b['planned_at']);

        /** @var list<array{StationLogEntry, ?int}> $touched */
        $touched = [];

        foreach ($logRows as $data) {
            if ($data['skip']) {
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
                    // final-song swap). Keep it unless the line is locked.
                    if (
                        StationLogEntry::STATUS_PLANNED === $entry->status
                        && !$entry->is_locked
                        && null !== $data['media_id']
                        && $entry->media?->id !== $data['media_id']
                    ) {
                        $this->applyRowData($entry, $data);
                        $entry->note = 'Adjusted by the planner to land on the top of the hour';
                    }
                    $this->em->persist($entry);
                }

                $touched[] = [$entry, $data['index']];
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
            $touched[] = [$entry, $data['index']];
        }

        $this->em->flush();

        foreach ($touched as [$entry, $index]) {
            if (null !== $index && isset($entries[$index])) {
                $entries[$index] = [...$entries[$index], ...$this->logFields($entry)];
            }
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

        return array_values($entries);
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
            'media_type' => $entry->media?->type ?? ($payload['media_type'] ?? 'music'),
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

    private static function cut(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        return mb_substr((string)$value, 0, 255);
    }
}
