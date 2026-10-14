<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use App\Radio\AutoDJ\StrictProgrammeClock;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use JsonException;
use Throwable;

/**
 * Keeps the planned log naming files that are there to air, timed as they
 * will air, from the moment a file changes instead of at air time.
 *
 * A line names its file, and is timed by it, when the plan is built. The 9am
 * import of a new "Altered Stories with Mischelle" episode on Wed 2026-10-14
 * deleted the old file (28:38) and added the new one (32:39). The 7pm line
 * kept the old length with no file behind it: the page read the hour 3:46
 * short of its ID, the hour fit took the file-less line for a stream and left
 * the hour alone, and at 7pm playout would have dropped the show.
 *
 * Each minute, before the rules, the refill and the hour fit:
 *
 * - A show (a playlist with "Spoken-Word Programming" ticked) that holds one
 *   file has each of its planned airings timed from that file as it airs
 *   (ProgrammeParts): a part runs until the file ends or the Top-of-Hour ID
 *   takes the air, the next part picks up after the ID, and a part the file no
 *   longer reaches is dropped. This gives an airing whose file was deleted the
 *   show's file now, and re-times one whose file was replaced in place. The
 *   lines after a show are not moved: the page draws them after it, and the
 *   hour fit brings that hour back to its ID.
 * - Any other line whose file was deleted is dropped, as playout drops it at
 *   air time, and the refill gives the slot a song of about the same length.
 *
 * An airing that has started, or holds a locked line, is left alone; so are
 * Clock Wheel lines, strict-lane programmes and streams, and a show whose
 * playlist holds no file (an episode deleted before the next is added) or
 * several (which one airs is the AutoDJ's pick at air time). A file that only
 * left its playlist is LinearLogRules' to drop. Nothing is written under a
 * build, which writes the whole plan back when it ends.
 */
final class LinearLogRemovedFiles
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    /** The note playout drops a line with when its file is gone at air time. */
    public const string REMOVED_NOTE = 'Dropped: the planned file was removed from the library';

    public const string NO_PART_NOTE = "Dropped: the show's file ends before this part";

    public const string RETIMED_NOTE = "Timed from the show's file; planned: ";

    /** Deleted songs dropped per pass: what the refill can replace in the same pass. */
    private const int DROPS_PER_PASS = 5;

    /** A part the file has less than this left for does not air (ProgrammeParts::MIN_REMAINDER_SECONDS). */
    private const float MIN_REMAINDER_SECONDS = 15.0;

    /** A part picks up this far before the cut (ProgrammeParts::REJOIN_SECONDS). */
    private const float REJOIN_SECONDS = 5.0;

    /** A show line this close to the length its file gives it is left as it is. */
    private const float TOLERANCE_SECONDS = 2.0;

    /** How far back an airing that is still to finish may have started. */
    private const int LOOKBACK_SECONDS = 6 * 3600;

    public function __construct(
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly StrictProgrammeClock $strictProgrammeClock,
        private readonly TopOfHourClock $clock,
    ) {
    }

    /**
     * @return int lines re-timed or dropped
     */
    public function resolve(Station $station): int
    {
        if (in_array($this->snapshotStore->get($station)['status'] ?? null, ['queued', 'building'], true)) {
            return 0;
        }

        $done = 0;
        foreach ($station->playlists as $playlist) {
            if (
                !$playlist->is_programme
                || PlaylistSources::Songs !== $playlist->source
                || $this->strictProgrammeClock->isPlayedByStrictLane($playlist)
            ) {
                continue;
            }

            try {
                $done += $this->timeShow($station, $playlist);
            } catch (Throwable $e) {
                $this->logger->error('Linear Log: timing a show from its file failed.', [
                    'playlist' => $playlist->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $done + $this->dropDeletedSongs($station);
    }

    /**
     * Time each planned airing of a one-file show from that file.
     */
    private function timeShow(Station $station, StationPlaylist $playlist): int
    {
        $conn = $this->em->getConnection();
        $mediaIds = $conn->fetchFirstColumn(
            'SELECT sm.id FROM station_playlist_media spm
            JOIN station_media sm ON sm.id = spm.media_id
            WHERE spm.playlist_id = ? AND (sm.type IS NULL OR sm.type NOT IN (?))',
            [$playlist->id, StationMediaTypes::stationIdTypeValues()],
            [1 => ArrayParameterType::STRING]
        );
        $media = 1 === count($mediaIds) ? $this->em->find(StationMedia::class, (int)$mediaIds[0]) : null;
        if (!$media instanceof StationMedia) {
            return 0;
        }

        /** @var list<array<string, mixed>> $lines */
        $lines = $conn->fetchAllAssociative(
            'SELECT id, planned_at, duration, media_id, status, is_locked, text, title, note, payload
            FROM station_log_entries
            WHERE station_id = ? AND playlist_id = ? AND status <> ? AND planned_at > ?
            AND (payload IS NULL OR payload NOT LIKE ?)
            ORDER BY planned_at ASC, sequence ASC',
            [
                $station->id,
                $playlist->id,
                StationLogEntry::STATUS_DROPPED,
                time() - self::LOOKBACK_SECONDS,
                '%scheduled_programme%',
            ]
        );

        $airings = [];
        foreach ($lines as $line) {
            $airings[$this->airingKey($playlist, (int)$line['planned_at'], (int)$line['id'])][] = $line;
        }

        $done = 0;
        foreach ($airings as $airing) {
            $done += $this->timeAiring($station, $playlist, $media, $airing);
        }

        return $done;
    }

    /**
     * Give an airing's lines the parts the show's file airs in.
     *
     * @param non-empty-list<array<string, mixed>> $airing its lines, in planned order
     */
    private function timeAiring(Station $station, StationPlaylist $playlist, StationMedia $media, array $airing): int
    {
        foreach ($airing as $line) {
            // Started or locked airings are playout's and the operator's; a
            // line naming another file still in the library is LinearLogRules'.
            if (
                StationLogEntry::STATUS_PLANNED !== $line['status']
                || (bool)$line['is_locked']
                || (null !== $line['media_id'] && (int)$line['media_id'] !== $media->id)
                || null !== (self::decodePayload($line['payload'])['clock_wheel_id'] ?? null)
            ) {
                return 0;
            }
        }

        $parts = $this->parts($station, $media, $airing);

        $done = 0;
        $dropped = [];
        foreach ($airing as $line) {
            $seconds = $parts[(int)$line['id']];
            if (null === $seconds) {
                $dropped[] = (int)$line['id'];
                continue;
            }
            if (
                (int)$line['media_id'] === $media->id
                && abs((float)$line['duration'] - $seconds) <= self::TOLERANCE_SECONDS
            ) {
                continue;
            }

            $done += $this->retime($line, $playlist, $media, $seconds);
        }

        if ([] !== $dropped) {
            $done += (int)$this->em->getConnection()->executeStatement(
                'UPDATE station_log_entries SET status = ?, note = ?
                WHERE station_id = ? AND id IN (?) AND status = ? AND is_locked = 0',
                [
                    StationLogEntry::STATUS_DROPPED,
                    self::NO_PART_NOTE,
                    $station->id,
                    $dropped,
                    StationLogEntry::STATUS_PLANNED,
                ],
                [3 => ArrayParameterType::INTEGER]
            );
        }

        return $done;
    }

    /**
     * Seconds of air each line of an airing gets from the file, or null for a
     * line the file no longer reaches: a part runs until the file ends or the
     * ID cuts it, and the next one picks up a little before the cut, as
     * ProgrammeParts airs them. A line due while an earlier part still holds
     * the air has nothing of its own.
     *
     * @param non-empty-list<array<string, mixed>> $airing
     * @return array<int, float|null> by line id
     */
    private function parts(Station $station, StationMedia $media, array $airing): array
    {
        $left = max(1.0, $media->getCalculatedLength());
        $heldUntil = 0;
        $parts = [];
        foreach ($airing as $line) {
            $startsAt = (int)$line['planned_at'];
            if ($left < self::MIN_REMAINDER_SECONDS || $startsAt < $heldUntil) {
                $parts[(int)$line['id']] = null;
                continue;
            }

            $cutAt = $this->idAfter($station, $startsAt);
            $seconds = null !== $cutAt ? min($left, (float)($cutAt - $startsAt)) : $left;
            $left -= $seconds;
            if ($left >= self::MIN_REMAINDER_SECONDS) {
                $left += self::REJOIN_SECONDS;
            }
            $heldUntil = (int)ceil($startsAt + $seconds);
            $parts[(int)$line['id']] = $seconds;
        }

        return $parts;
    }

    /**
     * When the next Top-of-Hour ID after $at starts, or null when no ID cuts
     * the air there (it is off, or a Clock Wheel owns the boundary).
     */
    private function idAfter(Station $station, int $at): ?int
    {
        if (!$this->clock->isEnabled($station)) {
            return null;
        }

        $from = new DateTimeImmutable('@' . $at);
        $boundary = $this->clock->getNextBoundary($station, $from);
        $idAt = $this->clock->getTargetStartFor($station, $from)->getTimestamp();
        if ($idAt <= $at) {
            // An ID moved ahead of the hour that has already passed: the next hour's.
            $from = $boundary;
            $boundary = $this->clock->getNextBoundary($station, $from);
            $idAt = $this->clock->getTargetStartFor($station, $from)->getTimestamp();
        }

        return $this->clock->clockWheelOwnsBoundary($station, $boundary) ? null : $idAt;
    }

    /**
     * Write a show line as the part of the file it airs.
     *
     * @param array<string, mixed> $line
     */
    private function retime(array $line, StationPlaylist $playlist, StationMedia $media, float $seconds): int
    {
        // What the line is beyond its file stays; the file's own fields are the new file's.
        $kept = array_diff_key(self::decodePayload($line['payload']), array_flip(['album', 'song_id', 'media_type']));
        $fields = LinearLogRefill::songFields($media, $playlist, $kept);
        $fields['duration'] = max(1.0, $seconds);

        $previous = (string)($line['note'] ?? '');
        $planned = str_starts_with($previous, self::RETIMED_NOTE)
            ? substr($previous, strlen(self::RETIMED_NOTE))
            : ((string)($line['title'] ?? '') ?: (string)($line['text'] ?? 'unknown'));
        $fields['note'] = mb_substr(self::RETIMED_NOTE . $planned, 0, 255);

        // Only while the line is still as it was read: playout may be taking
        // it, or the operator locking it, at this moment.
        $written = (int)$this->em->getConnection()->update(
            'station_log_entries',
            $fields,
            ['id' => (int)$line['id'], 'status' => StationLogEntry::STATUS_PLANNED, 'is_locked' => 0]
        );

        if ($written > 0) {
            $this->logger->notice("Linear Log: a show line was timed from the show's file.", [
                'log_entry_id' => (int)$line['id'],
                'show' => $playlist->name,
                'file' => $media->path,
                'was_seconds' => round((float)$line['duration'], 1),
                'now_seconds' => round($seconds, 1),
            ]);
        }

        return $written;
    }

    /**
     * Drop songs, liners and promos whose file was deleted, as playout would at
     * air time; the refill gives each slot another song in the same pass.
     */
    private function dropDeletedSongs(Station $station): int
    {
        $skip = [0];
        foreach ($station->playlists as $playlist) {
            if ($playlist->is_programme || $this->strictProgrammeClock->isPlayedByStrictLane($playlist)) {
                $skip[] = $playlist->id;
            }
        }

        $conn = $this->em->getConnection();
        $ids = [];
        foreach (
            $conn->fetchAllAssociative(
                'SELECT e.id, e.payload FROM station_log_entries e
                JOIN station_playlists p ON p.id = e.playlist_id
                WHERE e.station_id = ? AND e.status = ? AND e.is_locked = 0 AND e.media_id IS NULL
                AND e.planned_at > ? AND p.source = ? AND p.id NOT IN (?)
                AND (e.payload IS NULL OR e.payload NOT LIKE ?)
                ORDER BY e.planned_at ASC, e.sequence ASC',
                [
                    $station->id,
                    StationLogEntry::STATUS_PLANNED,
                    time(),
                    PlaylistSources::Songs->value,
                    $skip,
                    '%scheduled_programme%',
                ],
                [4 => ArrayParameterType::INTEGER]
            ) as $line
        ) {
            if (null !== (self::decodePayload($line['payload'])['clock_wheel_id'] ?? null)) {
                continue;
            }
            $ids[] = (int)$line['id'];
            if (count($ids) >= self::DROPS_PER_PASS) {
                break;
            }
        }

        if ([] === $ids) {
            return 0;
        }

        $dropped = (int)$conn->executeStatement(
            'UPDATE station_log_entries SET status = ?, note = ?
            WHERE station_id = ? AND id IN (?) AND status = ? AND is_locked = 0 AND media_id IS NULL',
            [StationLogEntry::STATUS_DROPPED, self::REMOVED_NOTE, $station->id, $ids, StationLogEntry::STATUS_PLANNED],
            [3 => ArrayParameterType::INTEGER]
        );

        if ($dropped > 0) {
            $this->logger->notice('Linear Log: dropped planned lines whose file was removed from the library.', [
                'dropped' => $dropped,
                'of_log_entry_ids' => $ids,
            ]);
        }

        return $dropped;
    }

    /**
     * Which airing of the show a line belongs to: the occurrence of its
     * schedule open at the line's time, as ProgrammeParts finds the part
     * before a row. A line outside every occurrence is an airing of its own.
     */
    private function airingKey(StationPlaylist $playlist, int $at, int $lineId): string
    {
        $tz = $playlist->station->getTimezoneObject();
        $moment = CarbonImmutable::createFromTimestamp($at, $tz);
        $latest = null;
        foreach ($playlist->schedule_items as $schedule) {
            $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                $schedule,
                $tz,
                $moment->subDay(),
                $moment->addDay()
            );
            foreach ($occurrences as $occurrence) {
                $start = CarbonImmutable::instance($occurrence->start);
                if ($start <= $moment && $moment <= CarbonImmutable::instance($occurrence->end)) {
                    $latest = null === $latest || $start > $latest ? $start : $latest;
                }
            }
        }

        return null !== $latest ? 'window-' . $latest->getTimestamp() : 'line-' . $lineId;
    }

    /** @return array<string, mixed> */
    private static function decodePayload(mixed $raw): array
    {
        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
