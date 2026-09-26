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
        if (!$playlist instanceof StationPlaylist) {
            return;
        }

        $airsAt = $event->getOpensAfter() ?? $event->getExpectedPlayAt();
        if ($airsAt < $event->getExpectedPlayAt()) {
            $airsAt = $event->getExpectedPlayAt();
        }

        if ($this->scheduler->isPlaylistAllowedAt($playlist, DateTimeImmutable::createFromInterface($airsAt))) {
            return;
        }

        $this->logger->notice(
            'Removed a queued song whose playlist may not play at its air time.',
            [
                'queue_id' => $row->id,
                'playlist' => $playlist->name,
                'song' => trim(($row->artist ?? '') . ' - ' . ($row->title ?? ''), ' -'),
                'airs_at' => $airsAt->format(DATE_ATOM),
            ]
        );

        if (null !== $row->log_entry_id) {
            $entry = $this->em->find(StationLogEntry::class, $row->log_entry_id);
            if (
                $entry instanceof StationLogEntry
                && in_array($entry->status, [StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED], true)
            ) {
                $this->em->remove($entry);
            }
        }

        $this->em->remove($row);
        $this->em->flush();
    }
}
