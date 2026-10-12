<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Event\Radio\RevalidateQueuedSong;
use App\Radio\AutoDJ\LinearLogPreviewContext;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hit the post on air: a log line that has not started when its hour ends does
 * not air, and the new hour opens on its own first line.
 *
 * The plan is built that way (Queue::buildQueue() drops a seeded line once its
 * hour has ended), and playout drops the lines an ended hour never queued. But
 * the live queue fills about an hour ahead, so the lines an hour had no room
 * for were queued long before it ended, and nothing took them back. They aired
 * after the Station ID, ahead of the next hour's own lines, and pushed that
 * hour's last lines into the one after it. At 8:40pm on Sun 2026-10-11 four
 * songs of the 8pm hour were queued for 9:00-9:16, the 9pm hour's sixty minutes
 * of lines started 16 minutes late, and its last four songs were listed inside
 * the 10pm Life Perspectives show.
 *
 * Runs on every re-check of a queued row that is not loaded yet: the queue's
 * own pass, and the final check before a row is handed to Liquidsoap. The row
 * is removed and its line goes back to the log. Playout offers it only for a
 * slot in its own hour, and closes it out when that hour ends
 * (LinearLogPlayout::closeOutEndedHours()).
 */
final class LinearLogHourGuard implements EventSubscriberInterface
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    public function __construct(
        private readonly LinearLogPreviewContext $previewContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the Top-of-Hour listener (-1), so a row it holds for the new
        // hour is judged where it opens. Before ScheduleWindowGuard (-10): a
        // leftover that would open a show's hour is a leftover, and its line
        // goes back to the log instead of being thrown out as a schedule fault.
        return [
            RevalidateQueuedSong::class => ['onRevalidateQueuedSong', -9],
        ];
    }

    /**
     * End of the clock hour, in station time, a line planned at $plannedAt belongs to.
     */
    public static function hourEnd(Station $station, int $plannedAt): int
    {
        return CarbonImmutable::createFromTimestamp($plannedAt, $station->getTimezoneObject())
            ->startOfHour()
            ->addHour()
            ->getTimestamp();
    }

    /**
     * True for a line that belongs to its hour: a song, a spot, a liner. A
     * show is a timed event with a window of its own, not a line of an hour.
     */
    public static function isHourBound(StationLogEntry $entry): bool
    {
        return 'scheduled_programme' !== ($entry->payload['source_type'] ?? null)
            && true !== $entry->playlist?->is_programme;
    }

    public function onRevalidateQueuedSong(RevalidateQueuedSong $event): void
    {
        // The plan's simulation applies the rule itself, from the saved times
        // it seeds the queue with.
        if ($this->previewContext->isActive()) {
            return;
        }

        $row = $event->getQueueRow();
        if (!$this->em->contains($row) || $row->sent_to_autodj || $row->is_played) {
            return;
        }

        // A request is the listener's choice, not a line of the hour.
        if (null === $row->log_entry_id || null !== $row->request) {
            return;
        }

        $station = $event->getStation();
        if (!LinearLogPlayout::isPlayoutEnabled($station)) {
            return;
        }

        $entry = $this->em->find(StationLogEntry::class, $row->log_entry_id);
        if (
            !$entry instanceof StationLogEntry
            || !in_array($entry->status, [StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED], true)
            || !self::isHourBound($entry)
        ) {
            return;
        }

        $startsAt = $event->getOpensAfter() ?? $event->getExpectedPlayAt();
        if ($startsAt < $event->getExpectedPlayAt()) {
            $startsAt = $event->getExpectedPlayAt();
        }

        if ($startsAt->getTimestamp() < self::hourEnd($station, $entry->planned_at)) {
            return;
        }

        $this->logger->notice(
            'Linear Log: a queued line would start after its hour has ended; it goes back to the log.',
            [
                'queue_id' => $row->id,
                'log_entry_id' => $entry->id,
                'song' => $row->text,
                'planned_at' => $entry->planned_at,
                'starts_at' => $startsAt->format(DATE_ATOM),
            ]
        );

        if (null !== $entry->note && str_starts_with($entry->note, 'Live:')) {
            // A song the AutoDJ picked itself was never on the plan; its line
            // was written as it was picked and describes nothing now.
            $this->em->remove($entry);
        } else {
            $entry->status = StationLogEntry::STATUS_PLANNED;
            $entry->queue_id = null;
            $this->em->persist($entry);
        }

        $this->em->remove($row);
        $this->em->flush();
    }
}
