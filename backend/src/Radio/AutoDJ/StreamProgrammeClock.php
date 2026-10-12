<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\PlaylistRemoteTypes;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * How long a scheduled remote-stream show holds the air once its queue row
 * has been handed to Liquidsoap.
 *
 * A stream row is not marked played while its show airs (the stream's own
 * titles never match the row), so it stays "sent, not played" for the whole
 * show. Its stored length says nothing about when it ends: the show ends when
 * its schedule window closes. The queue and the final check before a row is
 * sent each worked that out their own way. The queue used the end of the whole
 * run of stream shows, so Nightsounds (11:00pm) and Unshackled (11:30pm) were
 * timed at midnight behind Life Perspectives (10:00pm) and thrown out as
 * outside their windows. The final check used "now" plus the stored length, so
 * every line planned for the hour after a show was judged inside the show and
 * thrown out, one each time Liquidsoap asked (Sat 2026-10-10, and the 9am hour
 * behind the Morning Show on Oct 8 and 9).
 *
 * Both read this one answer now, as StrictProgrammeClock gives it for shows
 * the strict lane plays.
 */
final class StreamProgrammeClock
{
    use EntityManagerAwareTrait;

    /**
     * A show's row is sent while the last song before it is still on air, so
     * its projected start can fall shortly before its window opens (the
     * Top-of-Hour ID airs in between).
     */
    private const int OPENER_LEAD_IN_SECONDS = 300;

    /** True for the queue row of a scheduled remote-stream show. */
    public function isStreamProgrammeRow(StationQueue $row): bool
    {
        $playlist = $row->playlist;

        return null !== $row->autodj_custom_uri
            && $playlist instanceof StationPlaylist
            && PlaylistSources::RemoteUrl === $playlist->source
            && PlaylistRemoteTypes::Stream === ($playlist->remote_type ?? PlaylistRemoteTypes::Stream)
            && $playlist->schedule_items->count() > 0;
    }

    /**
     * When a stream show's row that is on air from $from hands the air back:
     * the end of its own schedule window. $from itself when that window has
     * already closed, since the show holds nothing more. Null when the row is
     * not a scheduled stream show and its stored length still applies.
     */
    public function airHeldUntil(StationQueue $row, DateTimeInterface $from): ?CarbonImmutable
    {
        if (!$this->isStreamProgrammeRow($row)) {
            return null;
        }

        $playlist = $row->playlist;
        assert($playlist instanceof StationPlaylist);

        $tz = $row->station->getTimezoneObject();
        $from = CarbonImmutable::instance($from)->setTimezone($tz);
        $fromTs = $from->getTimestamp();

        $heldUntil = null;
        foreach ($playlist->schedule_items as $schedule) {
            $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                $schedule,
                $tz,
                $from->subDay(),
                $from->addDay(),
            );

            foreach ($occurrences as $occurrence) {
                $startTs = $occurrence->start->getTimestamp();
                $endTs = $occurrence->end->getTimestamp();

                // Half-open, as the schedule guard reads a window: one ending
                // at $from no longer owns the air there.
                if ($endTs <= $fromTs || $startTs > $fromTs + self::OPENER_LEAD_IN_SECONDS) {
                    continue;
                }

                if (null === $heldUntil || $endTs < $heldUntil) {
                    $heldUntil = $endTs;
                }
            }
        }

        return null === $heldUntil
            ? $from
            : CarbonImmutable::createFromTimestamp($heldUntil, $tz);
    }

    /**
     * Until when a stream show already handed to Liquidsoap holds the air, or
     * null when none does.
     *
     * Each row is asked from the start the queue last projected for it, so a
     * show sent while the song before it is still playing already counts.
     */
    public function onAirHeldUntil(Station $station, DateTimeInterface $now): ?CarbonImmutable
    {
        $now = CarbonImmutable::instance($now);

        /** @var StationQueue[] $rows */
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sq, sp FROM App\Entity\StationQueue sq
                JOIN sq.playlist sp
                WHERE sq.station = :station
                AND sq.is_played = 0
                AND sq.sent_to_autodj = 1
                AND sq.autodj_custom_uri IS NOT NULL
            DQL
        )->setParameter('station', $station)
            ->getResult();

        $heldUntil = null;
        foreach ($rows as $row) {
            $startsAt = null !== $row->timestamp_played
                ? CarbonImmutable::instance($row->timestamp_played)->max($now)
                : $now;

            // A row whose window has closed holds nothing: it gets $startsAt back.
            $rowHeldUntil = $this->airHeldUntil($row, $startsAt);
            if (
                null !== $rowHeldUntil
                && $rowHeldUntil->greaterThan($startsAt)
                && (null === $heldUntil || $rowHeldUntil->greaterThan($heldUntil))
            ) {
                $heldUntil = $rowHeldUntil;
            }
        }

        return $heldUntil;
    }
}
