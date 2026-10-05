<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\StationLogEntry;
use App\Event\Radio\RevalidateQueuedSong;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Removes a queued, not-yet-sent song when the same song airs again within the
 * station's duplicate-prevention window ahead of it.
 *
 * The pickers check duplicates only against what was already queued BEFORE the
 * slot they fill. The queue now runs about an hour ahead, so a pick that fills a
 * slot in the middle of it never sees the copy queued later: "There Is None Like
 * You" was picked into 14:12 while the same song already closed the 14:00 hour
 * and then opened 15:00 (Mon 2026-10-05). This re-checks every queued row on
 * each revalidation pass, the same point where ScheduleWindowGuard runs.
 */
final class QueuedRepeatGuard implements EventSubscriberInterface
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    /** A song ending this close to :59:59 is treated as the hour's final, swap-landed song. */
    private const int FINAL_SONG_TOLERANCE_SECONDS = 10;

    public static function getSubscribedEvents(): array
    {
        // After the Top-of-Hour listener (-1) and ScheduleWindowGuard (-10).
        return [
            RevalidateQueuedSong::class => ['onRevalidateQueuedSong', -11],
        ];
    }

    public function onRevalidateQueuedSong(RevalidateQueuedSong $event): void
    {
        $row = $event->getQueueRow();
        if (!$this->em->contains($row) || $row->sent_to_autodj || $row->is_played) {
            return;
        }

        // Music only: promos, IDs, talk and shows repeat by design. Requests are
        // the listener's choice.
        if (
            $row->top_of_hour_legal_id
            || $row->clock_wheel_legal_id_substitute
            || null !== $row->request
            || null === $row->media
            || 'music' !== ($row->media->type ?? 'music')
        ) {
            return;
        }

        $station = $event->getStation();
        $minutes = $station->backend_config->duplicate_prevention_time_range;
        if ($minutes <= 0) {
            return;
        }

        $airsAt = CarbonImmutable::instance($event->getOpensAfter() ?? $event->getExpectedPlayAt())
            ->max(CarbonImmutable::instance($event->getExpectedPlayAt()));

        // The Top-of-Hour swap may take a repeat on purpose when it is the only
        // song that lands the :59:59 ID. Pulling that song would leave the
        // gap or cut the swap exists to prevent, so a final song is left alone.
        $idTarget = $airsAt->setTimezone($station->getTimezoneObject())
            ->startOfHour()
            ->addMinutes(59)
            ->addSeconds(59);
        $endsAt = $airsAt->addSeconds((int)round((float)($row->duration ?? $row->media->length ?? 0.0)));
        if (abs($endsAt->getTimestamp() - $idTarget->getTimestamp()) <= self::FINAL_SONG_TOLERANCE_SECONDS) {
            return;
        }

        $since = $airsAt->subMinutes($minutes);
        $conn = $this->em->getConnection();

        $aired = $conn->fetchOne(
            'SELECT timestamp_start FROM song_history
            WHERE station_id = ? AND song_id = ? AND timestamp_start >= ?
            ORDER BY timestamp_start DESC LIMIT 1',
            [$station->id, $row->song_id, $since->utc()->format('Y-m-d H:i:s')]
        );

        $queuedAhead = false === $aired
            ? $conn->fetchOne(
                'SELECT timestamp_played FROM station_queue
                WHERE station_id = ? AND song_id = ? AND id <> ? AND is_played = 0
                AND timestamp_played >= ? AND timestamp_played < ?
                ORDER BY timestamp_played ASC LIMIT 1',
                [
                    $station->id,
                    $row->song_id,
                    $row->id,
                    $since->utc()->format('Y-m-d H:i:s'),
                    $airsAt->utc()->format('Y-m-d H:i:s'),
                ]
            )
            : false;

        if (false === $aired && false === $queuedAhead) {
            return;
        }

        $this->logger->notice(
            'Removed a queued song that already airs within the duplicate-prevention window.',
            [
                'queue_id' => $row->id,
                'song' => trim(($row->artist ?? '') . ' - ' . ($row->title ?? ''), ' -'),
                'airs_at' => $airsAt->toAtomString(),
                'other_airing' => false !== $aired ? $aired : $queuedAhead,
                'window_minutes' => $minutes,
            ]
        );

        if (null !== $row->log_entry_id) {
            $entry = $this->em->find(StationLogEntry::class, $row->log_entry_id);
            if (
                $entry instanceof StationLogEntry
                && in_array($entry->status, [StationLogEntry::STATUS_PLANNED, StationLogEntry::STATUS_QUEUED], true)
            ) {
                $entry->status = StationLogEntry::STATUS_DROPPED;
                $entry->note = sprintf('Dropped: the same song airs within %d minutes', $minutes);
                $entry->queue_id = null;
                $this->em->persist($entry);
            }
        }

        $this->em->remove($row);
        $this->em->flush();
    }
}
