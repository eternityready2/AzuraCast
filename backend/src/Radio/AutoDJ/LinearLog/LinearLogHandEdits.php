<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use App\Entity\StationLogEdit;
use App\Entity\StationLogEntry;
use App\Entity\StationQueue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use JsonException;

/**
 * The history of hand edits on the saved linear log: written with every edit,
 * shown on the log line, and what Undo restores from.
 */
final class LinearLogHandEdits
{
    use EntityManagerAwareTrait;

    /** Edits listed on a line; a line is rarely edited more than once or twice. */
    private const int SHOWN_PER_LINE = 3;

    public const string REMOVED_NOTE = 'Dropped: removed by hand';

    /**
     * The line, and the queue slot it holds, as they are now.
     *
     * @return array<string, mixed>
     */
    public function snapshot(StationLogEntry $entry, ?StationQueue $queueRow): array
    {
        return [
            'status' => $entry->status,
            'media_id' => $entry->media?->id,
            'title' => $entry->title,
            'artist' => $entry->artist,
            'text' => $entry->text,
            'duration' => $entry->duration,
            'payload' => $entry->payload,
            'note' => $entry->note,
            'is_locked' => $entry->is_locked,
            'queue_id' => $entry->queue_id,
            'planned_at' => $entry->planned_at,
            'sequence' => $entry->sequence,
            // What a replace clears on the queue row; Undo puts it back.
            'queue' => null === $queueRow ? null : [
                'autodj_custom_uri' => $queueRow->autodj_custom_uri,
                'clock_wheel_id' => $queueRow->clock_wheel?->id,
                'clock_wheel_max_play_seconds' => $queueRow->clock_wheel_max_play_seconds,
                'clock_wheel_schedule_mode' => $queueRow->clock_wheel_schedule_mode,
                'clock_wheel_enforce_cap' => $queueRow->clock_wheel_enforce_cap,
                'clock_wheel_stretch_ratio' => $queueRow->clock_wheel_stretch_ratio,
                'clock_wheel_legal_id_substitute' => $queueRow->clock_wheel_legal_id_substitute,
                'hour_boundary_enforce_cap' => $queueRow->hour_boundary_enforce_cap,
                'hour_boundary_max_play_seconds' => $queueRow->hour_boundary_max_play_seconds,
            ],
        ];
    }

    /**
     * Saved with the edit itself: the caller flushes inside the edit's transaction.
     *
     * @param array<string, mixed> $before
     */
    public function record(StationLogEntry $entry, string $edit, array $before): StationLogEdit
    {
        $record = new StationLogEdit($entry, $edit, $before);
        $this->em->persist($record);

        return $record;
    }

    /** The newest remove or replace on the line that has not been undone. */
    public function lastUndoable(StationLogEntry $entry): ?StationLogEdit
    {
        $record = $this->em->createQuery(
            <<<'DQL'
                SELECT h FROM App\Entity\StationLogEdit h
                WHERE h.entry = :entry AND h.undone_at IS NULL AND h.edit IN (:undoable)
                ORDER BY h.id DESC
            DQL
        )->setParameter('entry', $entry)
            ->setParameter('undoable', StationLogEdit::UNDOABLE)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $record instanceof StationLogEdit ? $record : null;
    }

    /**
     * Adds each line's hand edits to the Linear Log report rows.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function tagEntries(Station $station, array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            if (!empty($entry['log_entry_id'])) {
                $ids[] = (int)$entry['log_entry_id'];
            }
        }
        if ([] === $ids) {
            return $entries;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, entry_id, edit, edited_at, before_state, undone_at
            FROM station_log_edits
            WHERE station_id = ? AND entry_id IN (?)
            ORDER BY id DESC',
            [$station->id, $ids],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER]
        );

        $byEntry = [];
        foreach ($rows as $row) {
            $byEntry[(int)$row['entry_id']][] = $row;
        }

        foreach ($entries as $i => $entry) {
            $history = $byEntry[(int)($entry['log_entry_id'] ?? 0)] ?? [];
            if ([] === $history) {
                continue;
            }

            $canUndo = false;
            foreach ($history as $row) {
                if (null === $row['undone_at'] && in_array($row['edit'], StationLogEdit::UNDOABLE, true)) {
                    $canUndo = $this->lineCanBePutBack($entry, (string)$row['edit']);
                    break;
                }
            }

            $entries[$i]['hand_edits'] = array_map(
                static fn(array $row): array => [
                    'edit' => (string)$row['edit'],
                    'at' => (int)$row['edited_at'],
                    'was' => self::describeBefore((string)$row['edit'], (string)$row['before_state']),
                    'undone' => null !== $row['undone_at'],
                ],
                array_slice($history, 0, self::SHOWN_PER_LINE)
            );
            $entries[$i]['can_undo'] = $canUndo;
        }

        return $entries;
    }

    /**
     * Whether the page offers Undo; the edit itself checks again under lock.
     *
     * @param array<string, mixed> $entry
     */
    private function lineCanBePutBack(array $entry, string $edit): bool
    {
        $status = $entry['log_status'] ?? null;

        if (StationLogEdit::EDIT_REMOVE === $edit) {
            return StationLogEntry::STATUS_DROPPED === $status
                && self::REMOVED_NOTE === ($entry['log_note'] ?? null);
        }

        return in_array($status, [StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED], true);
    }

    /** What the line was before the edit, in a few words for the page. */
    private static function describeBefore(string $edit, string $beforeJson): ?string
    {
        try {
            $before = json_decode($beforeJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($before)) {
            return null;
        }

        $song = trim((string)($before['text'] ?? ''));
        if ('' === $song) {
            $song = trim(($before['artist'] ?? '') . ' - ' . ($before['title'] ?? ''), ' -');
        }

        return match ($edit) {
            StationLogEdit::EDIT_REPLACE, StationLogEdit::EDIT_UNDO => '' === $song ? null : $song,
            StationLogEdit::EDIT_LOCK => 'unlocked',
            StationLogEdit::EDIT_UNLOCK => 'locked',
            default => null,
        };
    }
}
