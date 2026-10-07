<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\StationLogEntry;
use App\Entity\StationPlaylist;
use App\Event\Radio\RevalidateQueuedSong;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Removes any queued, not-yet-sent row whose playlist may not play at the time
 * it will actually air: a scheduled playlist outside its window, or an
 * unscheduled playlist while a scheduled block is open.
 *
 * Runs on every revalidation pass, so it covers the live AutoDJ queue, the
 * hand-off to Liquidsoap, and the saved Linear Log (the builder seeds saved
 * lines into the queue and drops any line whose row is removed here). Choices
 * made earlier -- including log lines saved by older code -- never bypass it.
 */
final class ScheduleWindowGuard implements EventSubscriberInterface
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the Top-of-Hour listener (-1), so a row held for the new hour is
        // judged at the time it will really open, not its stale projection.
        return [
            RevalidateQueuedSong::class => ['onRevalidateQueuedSong', -10],
        ];
    }

    public function onRevalidateQueuedSong(RevalidateQueuedSong $event): void
    {
        $row = $event->getQueueRow();
        if (!$this->em->contains($row) || $row->sent_to_autodj || $row->is_played) {
            return;
        }

        if ($row->top_of_hour_legal_id || $row->clock_wheel_legal_id_substitute) {
            return;
        }

        $playlist = $row->playlist;
        $clockWheel = $row->clock_wheel;
        if (!$playlist instanceof StationPlaylist && null === $clockWheel) {
            return;
        }

        $airsAt = $event->getOpensAfter() ?? $event->getExpectedPlayAt();
        if ($airsAt < $event->getExpectedPlayAt()) {
            $airsAt = $event->getExpectedPlayAt();
        }

        $airsAtImmutable = DateTimeImmutable::createFromInterface($airsAt);

        // A wheel's tracks are drawn from member playlists that usually carry no
        // schedule of their own. Judge such a row by the wheel that owns the
        // slot, or the wheel's own programme would delete itself.
        if (null !== $clockWheel) {
            if ($this->scheduler->isClockWheelAllowedAt($clockWheel, $airsAtImmutable)) {
                return;
            }

            $sourceName = $clockWheel->name;
        } else {
            if ($this->scheduler->isPlaylistAllowedAt($playlist, $airsAtImmutable)) {
                return;
            }

            $sourceName = $playlist->name;
        }

        $this->logger->notice(
            'Removed a queued song whose source may not play at its air time.',
            [
                'queue_id' => $row->id,
                'source' => $sourceName,
                'song' => trim(($row->artist ?? '') . ' - ' . ($row->title ?? ''), ' -'),
                'airs_at' => $airsAt->format(DATE_ATOM),
            ]
        );

        // The log line stays, marked dropped with the reason: deleted, it left
        // a hole in the log with no trace of what was planned there.
        if (null !== $row->log_entry_id) {
            $entry = $this->em->find(StationLogEntry::class, $row->log_entry_id);
            if (
                $entry instanceof StationLogEntry
                && in_array($entry->status, [StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED], true)
            ) {
                $entry->status = StationLogEntry::STATUS_DROPPED;
                $airsAtLocal = $airsAtImmutable->setTimezone($row->station->getTimezoneObject());
                $entry->note = mb_substr(
                    'Dropped: ' . $sourceName . ' may not play at ' . $airsAtLocal->format('g:i:s A'),
                    0,
                    255
                );
                $entry->queue_id = null;
                $this->em->persist($entry);
            }
        }

        $this->em->remove($row);
        $this->em->flush();
    }
}
