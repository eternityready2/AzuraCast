<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\StationLogEntry;
use App\Entity\StationPlaylist;
use App\Event\Radio\RevalidateQueuedSong;
use App\Radio\AutoDJ\LinearLog\LinearLogHour;
use App\Radio\AutoDJ\LinearLog\LinearLogLineWindow;
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
 *
 * The row is what is removed. Its log line is only dropped when the line may
 * not play at the time the log planned it for; a line that is right in the log
 * and wrong only where the queue has timed its row goes back to the log.
 */
final class ScheduleWindowGuard implements EventSubscriberInterface
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly LinearLogLineWindow $lineWindow,
        private readonly LinearLogHour $hour,
        private readonly StreamProgrammeClock $streamProgrammeClock,
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
        $smartBlock = $row->smart_block;
        if (!$playlist instanceof StationPlaylist && null === $clockWheel && null === $smartBlock) {
            return;
        }

        $airsAt = $event->getOpensAfter() ?? $event->getExpectedPlayAt();
        if ($airsAt < $event->getExpectedPlayAt()) {
            $airsAt = $event->getExpectedPlayAt();
        }

        // A stream show due in the seconds before the ID airs after it; the
        // Top-of-Hour swap holds only songs, so nothing else says so. Judged
        // where the last song ended, Life Perspectives was outside its own
        // window and thrown out every 10s from 9:50 to 10pm (Sun 2026-10-11).
        if (
            null === $event->getOpensAfter()
            && $this->streamProgrammeClock->isStreamProgrammeRow($row)
            && $this->hour->opensNextHour($row->station, $airsAt->getTimestamp())
        ) {
            $airsAt = $airsAt->setTimestamp($this->hour->endOf($row->station, $airsAt->getTimestamp()) - 3600);
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
        } elseif (null !== $smartBlock) {
            if ($this->scheduler->isSmartBlockAllowedAt($smartBlock, $airsAtImmutable)) {
                return;
            }

            $sourceName = $smartBlock->name;
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
                && StationLogEntry::STATUS_QUEUED === $entry->status
                && null !== $entry->note
                && str_starts_with($entry->note, 'Live:')
            ) {
                // A song the AutoDJ picked itself was never on the plan; its
                // line was written as it was picked and describes nothing now.
                $this->em->remove($entry);
            } elseif (
                $entry instanceof StationLogEntry
                && in_array($entry->status, [StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED], true)
                && $this->lineWindow->mayAirAsPlanned($entry)
            ) {
                // The queue's time for this row is a projection, re-made on
                // every pass, and it is what failed here; the log's own time
                // for the line is fine. Dropped on it, every line planned for
                // the hour after a stream show was lost one by one while the
                // queue timed them an hour off (Sat 2026-10-10), and each
                // morning the 6:00 hour lost its first songs when the hour
                // before it ended a minute early. The line stays planned and
                // playout takes it again for a slot it may play in.
                $entry->status = StationLogEntry::STATUS_PLANNED;
                $entry->queue_id = null;
                $this->em->persist($entry);

                $this->logger->notice(
                    'Linear Log: the line of a mistimed queue row goes back to the log.',
                    ['log_entry_id' => $entry->id, 'planned_at' => $entry->planned_at]
                );
            } elseif (
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
