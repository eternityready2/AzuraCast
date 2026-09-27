<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationLogEntry;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Radio\AutoDJ\Scheduler;
use DateTimeImmutable;

/**
 * Operator override rules for the saved 24-hour linear log.
 *
 * FM automation lets the human running the station correct the log by hand and
 * by rule, without waiting for the scheduler to be fixed. These rules are that
 * safety net: they walk the future of the saved log and take out any line that
 * may not play at the time it is planned for -- a song from another playlist
 * that leaked into a scheduled programme block, or a scheduled line planned
 * before its own window opens.
 *
 * A dropped line keeps its row with status "dropped" and a note saying which
 * rule took it out, so the log records what happened instead of quietly
 * changing. Lines an operator locked by hand are never touched: a hand edit is
 * more specific than a standing rule, and the human always wins.
 */
final class LinearLogRules
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
    }

    /**
     * Apply the station's enabled rules to every future line of its saved log.
     *
     * @return array{checked: int, dropped: int, skipped_locked: int, reasons: list<string>,
     *     dropped_ids: list<int>}
     */
    public function apply(Station $station, ?int $from = null): array
    {
        $config = $station->backend_config;
        $enforceWindows = $config->linear_log_rule_enforce_windows;
        $dropOutside = $config->linear_log_rule_drop_outside_window;

        $result = ['checked' => 0, 'dropped' => 0, 'skipped_locked' => 0, 'reasons' => [], 'dropped_ids' => []];
        if (!$enforceWindows && !$dropOutside) {
            return $result;
        }

        $from ??= time();

        /** @var StationLogEntry[] $entries */
        $entries = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\StationLogEntry e
                WHERE e.station = :station
                AND e.status IN (:open)
                AND e.planned_at >= :from
                ORDER BY e.planned_at ASC, e.sequence ASC
            DQL
        )->setParameter('station', $station)
            ->setParameter('open', [StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED])
            ->setParameter('from', $from)
            ->getResult();

        foreach ($entries as $entry) {
            $result['checked']++;

            $violation = $this->findViolation($entry, $enforceWindows, $dropOutside);
            if (null === $violation) {
                continue;
            }

            // A hand edit outranks a standing rule.
            if ($entry->is_locked) {
                $result['skipped_locked']++;
                continue;
            }

            $this->drop($entry, $violation);
            $result['dropped']++;
            $result['dropped_ids'][] = $entry->id;
            if (!in_array($violation, $result['reasons'], true)) {
                $result['reasons'][] = $violation;
            }
        }

        if ($result['dropped'] > 0) {
            $this->em->flush();
            $this->logger->notice(
                'Linear Log rules dropped planned lines that may not play at their planned time.',
                [
                    'station' => $station->name,
                    'dropped' => $result['dropped'],
                    'checked' => $result['checked'],
                    'reasons' => $result['reasons'],
                ]
            );
        }

        return $result;
    }

    /**
     * Why this line may not play when it is planned, or null when it is fine.
     */
    private function findViolation(
        StationLogEntry $entry,
        bool $enforceWindows,
        bool $dropOutside
    ): ?string {
        $at = new DateTimeImmutable('@' . $entry->planned_at);
        $station = $entry->station;

        $clockWheelId = $entry->payload['clock_wheel_id'] ?? null;
        if (null !== $clockWheelId) {
            $clockWheel = $this->em->find(StationClockWheel::class, (int)$clockWheelId);
            if ($clockWheel instanceof StationClockWheel && $clockWheel->station->id === $station->id) {
                if ($this->scheduler->isClockWheelAllowedAt($clockWheel, $at)) {
                    return null;
                }

                return $dropOutside
                    ? sprintf('Clock wheel "%s" is not scheduled at this time', $clockWheel->name)
                    : null;
            }
        }

        $playlist = $entry->playlist;
        if (!$playlist instanceof StationPlaylist) {
            return null;
        }

        if ($this->scheduler->isPlaylistAllowedAt($playlist, $at)) {
            return null;
        }

        // A scheduled playlist planned outside its own window: the "starts too
        // early" case.
        if ($playlist->schedule_items->count() > 0) {
            return $dropOutside
                ? sprintf('"%s" is planned outside its own scheduled window', $playlist->name)
                : null;
        }

        if (!$enforceWindows) {
            return null;
        }

        // An unscheduled playlist inside somebody else's block: the "leaked into
        // a scheduled playlist" case. Name the block so the note is useful.
        $owner = $this->openBlockName($station, $at);

        return null !== $owner
            ? sprintf('"%s" leaked into the scheduled block "%s"', $playlist->name, $owner)
            : sprintf('"%s" may not play at this time', $playlist->name);
    }

    /** Name of a scheduled playlist whose window is open at $at. */
    private function openBlockName(Station $station, DateTimeImmutable $at): ?string
    {
        foreach ($station->playlists as $playlist) {
            if (
                $playlist->schedule_items->count() > 0
                && $playlist->is_enabled
                && $this->scheduler->isPlaylistScheduledToPlayNow($playlist, $at, excludeSpecialRules: true)
            ) {
                return $playlist->name;
            }
        }

        return null;
    }

    /**
     * Take a line out of the plan, keeping it in the log as a dropped line so the
     * operator can see the rule fired, and pull its queue row if it had one.
     */
    private function drop(StationLogEntry $entry, string $reason): void
    {
        if (null !== $entry->queue_id) {
            $queueRow = $this->em->find(StationQueue::class, $entry->queue_id);
            if ($queueRow instanceof StationQueue && !$queueRow->is_played) {
                $this->em->remove($queueRow);
            }
        }

        $entry->status = StationLogEntry::STATUS_DROPPED;
        $entry->note = mb_substr('Dropped by log rule: ' . $reason, 0, 255);
        $entry->queue_id = null;
        $this->em->persist($entry);
    }
}
