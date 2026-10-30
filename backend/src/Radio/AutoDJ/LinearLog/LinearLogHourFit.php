<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\LinearLog;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Entity\StationLogEntry;
use App\Radio\AutoDJ\AiredLength;
use App\Radio\AutoDJ\LinearLogBuilder;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use App\Radio\AutoDJ\StrictProgrammeClock;
use Carbon\CarbonImmutable;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Keeps the hours of the saved log that the live queue has not been through
 * fitted to their Top-of-Hour Station ID, as FM automation keeps its log,
 * instead of leaving an hour as it was planned until the Top-of-Hour swap
 * rescues it at the ID.
 *
 * A line is timed when the plan is built, and nothing measured its hour again
 * before it aired. Hours planned at 3am on Mon 2026-10-12, with every song
 * timed at its file length, read up to 2:42 over and 1:23 short of their ID on
 * the page that afternoon, and would have until they aired: only the hour the
 * live queue was in was looked at, and only given a song when it was two
 * minutes or more short (LinearLogRefill::topUp()). Each minute now, every
 * hour from that one on is measured where the page measures it
 * (LinearLogStore::liveEntries()) and brought back to its ID whichever way it
 * is out:
 *
 * - A line that cannot start before the ID (LinearLogHour) is left where it
 *   is, behind the hour's last line, and listed as having no room. The
 *   measure is an estimate: when the hour runs short on air, that line is the
 *   one playout takes next. Dropped here instead, it could never come back,
 *   and the hour's last minutes aired from the AutoDJ, a song the ID then cut
 *   (1:57am and 3:58am, Sun 2026-10-18). Playout closes it out once its hour
 *   has ended.
 * - An hour two minutes or more short is given a song, as the queue's hour is.
 * - An hour whose last song ends more than half a minute off the ID gets
 *   another song in that line, one that ends on the ID; see landClosingSong().
 *   Where no song is the length that line needs, the song before it gets one
 *   that takes up the difference.
 *
 * The measure is the plan's own: file lengths, and what songs have really
 * taken on air in that hour (AirTimePerSong). It is an estimate, and the
 * Top-of-Hour swap still lands each hour on its ID when it airs. This keeps
 * the plan, and what the page shows of it, no further off than the estimate.
 *
 * Only planned lines change. The hour playout is taking lines from is brought
 * to its ID the same way while its last songs are still planned, and no
 * further: it is topped up as it always was (LinearLogRefill::topUp()) and a
 * line it has no room for is playout's to close out. It read 0:47 short on the
 * page from the moment the queue reached it until its last song was queued
 * (11pm, Mon 2026-10-12). Hours the queue has passed are the queue's. A locked line is the operator's. A show is
 * a timed event, and an hour whose show runs through the ID, that carries a
 * stream, or whose last line starts while a strict-lane programme holds the
 * air, is left alone. So is an hour with no Station ID at its top to be fitted
 * to.
 */
final class LinearLogHourFit
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    public const string REFITTED_NOTE = 'Re-fitted to land on the top of the hour; planned: ';

    public const string REFITTED_BEFORE_NOTE = 'Re-fitted so the next song lands on the top of the hour; planned: ';

    /** Lines changed per pass; the rest wait for the next minute. */
    private const int MAX_PER_PASS = 10;

    /**
     * An hour whose last line ends within this of the ID is on time: the page
     * reads it so, and the Top-of-Hour swap and Liquidsoap's fit land what is
     * left by themselves.
     */
    private const int ON_TIME_SECONDS = 30;

    /** The ID that closes an hour starts within this before the hour's end, or on it. */
    private const int CLOSING_ID_SECONDS = 120;

    /**
     * How long an hour that no song could bring closer to its ID is left as it
     * is, while nothing about it changes: the search reads a whole
     * playlist, and a minute later it finds the same.
     */
    private const int REST_SECONDS = 900;

    public function __construct(
        private readonly LinearLogRefill $refill,
        private readonly LinearLogStore $store,
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly LinearLogBuilder $builder,
        private readonly LinearLogHour $hour,
        private readonly StrictProgrammeClock $strictProgrammeClock,
        private readonly AiredLength $airedLength,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Fit the hour the live queue is taking lines from and the hours ahead of it.
     *
     * @return int lines dropped, added or re-fitted
     */
    public function fit(Station $station): int
    {
        if (!LinearLogRefill::isEnabled($station)) {
            return 0;
        }

        // The refill in the same pass may have cleared the entity manager.
        $stationId = $station->id;
        $station = $this->em->find(Station::class, $stationId);
        if (!$station instanceof Station) {
            return 0;
        }

        // Around the ID the queue's times slide with the wall clock, and a
        // build writes the whole plan back when it ends: the hours are
        // measured once the top of the hour has settled, as a build waits for
        // it (LinearLogBuilder), and never under a build.
        $snapshot = $this->snapshotStore->get($station);
        if (
            empty($snapshot['entries'])
            || in_array($snapshot['status'] ?? null, ['queued', 'building'], true)
            || $this->builder->secondsUntilClearOfTopOfHour($station) > 0
        ) {
            return 0;
        }

        $now = time();
        $entries = $this->store->liveEntries(
            $station,
            $snapshot['entries'],
            $station->backend_config->linear_log_hours
        );

        // Where the live queue ends is the next slot playout fills, with a
        // line of the hour that slot airs in (LinearLogPlayout::nextLineFor()).
        // The hours behind that one are the queue's.
        $queueEnd = max($now, (int)ceil((float)$this->em->getConnection()->fetchOne(
            'SELECT MAX(UNIX_TIMESTAMP(timestamp_played) + duration) FROM station_queue
            WHERE station_id = ? AND is_played = 0 AND top_of_hour_legal_id = 0',
            [$stationId]
        )));
        $takingFrom = $this->hour->endOf($station, $queueEnd) - 3600;

        $done = 0;
        foreach ($this->hoursToAir($station, $entries, $now) as $hour) {
            if ($done >= self::MAX_PER_PASS) {
                break;
            }
            if ($hour['start'] < $takingFrom) {
                continue;
            }

            try {
                $done += $this->fitHour($stationId, $hour, $hour['start'] === $takingFrom);
            } catch (Throwable $e) {
                $this->logger->error('Linear Log: fitting an hour to its ID failed.', [
                    'hour_starts_at' => $hour['start'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $done;
    }

    /**
     * The hours still to air as the page lists them, each with the lines that
     * air before its Station ID and the lines it has no room for.
     *
     * An hour is fitted to the ID that closes it and measured from the one
     * that opens it. Where no ID is listed at a boundary (a Clock Wheel owns
     * it, the ID is off, or it has been moved more than two minutes ahead of
     * the top of the hour), the page runs lines on across it and playout does
     * not, so the hours on either side are not listed as they will air and are
     * left as planned. So are an hour that has ended, one the log stops
     * inside, one with nothing listed before its ID (the show of the hour
     * before is still on air) and one whose own show runs through the ID:
     * none has an ending to fit.
     *
     * @param list<array<string, mixed>> $entries from LinearLogStore::liveEntries()
     * @return list<array{
     *     start: int,
     *     id_at: int,
     *     lines: non-empty-list<array<string, mixed>>,
     *     leftovers: list<array<string, mixed>>
     * }>
     */
    private function hoursToAir(Station $station, array $entries, int $now): array
    {
        $tz = $station->getTimezoneObject();

        $logEnds = 0.0;
        $byHour = [];
        foreach ($entries as $entry) {
            $at = (int)($entry['played_at'] ?? 0);
            if ($at <= 0 || StationLogEntry::STATUS_DROPPED === ($entry['log_status'] ?? null)) {
                continue;
            }

            $logEnds = max($logEnds, $at + (float)($entry['duration'] ?? 0));
            $byHour[CarbonImmutable::createFromTimestamp($at, $tz)->startOfHour()->getTimestamp()][] = $entry;
        }
        ksort($byHour);

        // When each Top-of-Hour Station ID listed starts.
        $idStarts = [];
        foreach ($entries as $entry) {
            if (!empty($entry['top_of_hour_legal_id'])) {
                $idStarts[] = (int)($entry['played_at'] ?? 0);
            }
        }

        $hours = [];
        foreach ($byHour as $start => $items) {
            $end = $start + 3600;
            if ($end <= $now || $logEnds < $end) {
                continue;
            }

            // The hour on air opened in the past, whatever opened it.
            $idAt = self::idAtTopOfHour($idStarts, $end);
            if (null === $idAt || ($start > $now && null === self::idAtTopOfHour($idStarts, $start))) {
                continue;
            }

            $lines = [];
            $leftovers = [];
            $showRunsOn = false;
            foreach ($items as $item) {
                if (!empty($item['over_scheduled'])) {
                    $leftovers[] = $item;
                    continue;
                }

                $at = (int)$item['played_at'];
                if ($at >= $idAt) {
                    continue;
                }

                $lines[] = $item;
                $seconds = (float)($item['duration'] ?? 0);
                $showRunsOn = $showRunsOn || (self::isShow($item) && $at + $seconds > $idAt);
            }

            if ([] !== $lines && !$showRunsOn) {
                $hours[] = ['start' => $start, 'id_at' => $idAt, 'lines' => $lines, 'leftovers' => $leftovers];
            }
        }

        return $hours;
    }

    /**
     * When the Top-of-Hour Station ID at the top of the hour $topOfHour
     * starts: up to two minutes before it (a second before, as a rule) or on
     * it. Null when none is listed there.
     *
     * @param list<int> $idStarts when each listed Station ID starts
     */
    private static function idAtTopOfHour(array $idStarts, int $topOfHour): ?int
    {
        foreach ($idStarts as $at) {
            if ($at >= $topOfHour - self::CLOSING_ID_SECONDS && $at <= $topOfHour) {
                return $at;
            }
        }

        return null;
    }

    /**
     * Bring one hour back to its ID.
     *
     * @param array{
     *     start: int,
     *     id_at: int,
     *     lines: non-empty-list<array<string, mixed>>,
     *     leftovers: list<array<string, mixed>>
     * } $hour
     * @param bool $isQueueHour playout is taking this hour's lines for the queue
     * @return int lines added or re-fitted
     */
    private function fitHour(int $stationId, array $hour, bool $isQueueHour): int
    {
        // The hour's last line is the one that has to end on the ID.
        $closing = $hour['lines'][0];
        $hasQueuedLine = false;
        foreach ($hour['lines'] as $line) {
            if (self::landsAt($line) >= self::landsAt($closing)) {
                $closing = $line;
            }
            $hasQueuedLine = $hasQueuedLine || StationLogEntry::STATUS_QUEUED === ($line['log_status'] ?? null);
        }
        $offBy = self::landsAt($closing) - $hour['id_at'];

        // Where another line would start: behind the last one and whatever
        // airs after it.
        $endsAt = (int)ceil(
            (int)$closing['played_at'] + (float)($closing['air_seconds'] ?? $closing['duration'] ?? 0)
        );
        $shortBy = $hour['id_at'] - $endsAt;

        // Two things are true of every hour ahead of the queue: nothing in it
        // is queued, and a line is left over only because the lines before it
        // fill the hour. Where the list says otherwise it does not describe
        // the hour as it will air (a long show from the hour before holds the
        // air, or a line is out of its place in the list), and acting on it
        // would empty the hour or fill it twice. Nothing in it is changed.
        if (
            (!$isQueueHour && $hasQueuedLine)
            || ([] !== $hour['leftovers'] && $shortBy > LinearLogHour::ID_HOLD_WINDOW_SECONDS)
        ) {
            return 0;
        }

        // A line there is no room for stays planned behind the last one: it
        // airs if the hour runs short, and playout closes it out once the
        // hour has ended (LinearLogPlayout).
        if (abs($offBy) <= self::ON_TIME_SECONDS) {
            return 0;
        }

        $station = $this->em->find(Station::class, $stationId);
        if (!$station instanceof Station) {
            return 0;
        }

        // Before anything is written: an hour that carries a stream, or whose
        // last line starts under a strict-lane programme, is not the queue's
        // to fill.
        $openLines = $this->openLinesOf($stationId, $hour['start']);
        if ($this->carriesProgramme($station, $openLines, (int)$closing['played_at'])) {
            return 0;
        }

        // An hour that nothing could be found for rests while it stays as it
        // was: the search reads a whole playlist, and finds the same a minute
        // later.
        $restKey = 'linear_log_fit_rest_' . $stationId . '_' . $hour['start'];
        $state = implode(':', [
            (int)($closing['log_entry_id'] ?? 0),
            (string)($closing['song_id'] ?? ''),
            count($hour['lines']),
            intdiv((int)round($offBy), 10),
        ]);
        if ($state === $this->cache->get($restKey)) {
            return 0;
        }

        // Giving the queue's hour a song is the top-up's work, by its own
        // measure of that hour.
        $changed = 0;
        if (!$isQueueHour && $shortBy >= LinearLogRefill::MIN_TOP_UP_SECONDS) {
            // After the hour's last planned line ends, so playout takes it last.
            $lastPlannedEndsAt = $hour['start'];
            foreach ($openLines as $line) {
                if (StationLogEntry::STATUS_PLANNED === $line['status'] && (int)$line['planned_at'] >= $hour['start']) {
                    $lastPlannedEndsAt = max(
                        $lastPlannedEndsAt,
                        (int)$line['planned_at'] + (int)ceil((float)$line['duration'])
                    );
                }
            }
            $changed = $this->refill->addSong(
                $station,
                $hour['start'],
                $hour['id_at'],
                $endsAt,
                $shortBy,
                $lastPlannedEndsAt,
                true
            );
        }
        if (0 === $changed) {
            $changed = $this->landClosingSong($stationId, $hour, $closing, $offBy);
        }
        if (0 === $changed) {
            $this->cache->set($restKey, $state, self::REST_SECONDS);
        }

        return $changed;
    }

    /**
     * When a listed line ends on air. A song plays from its AutoCue start to
     * its end cue, the length the plan and the Top-of-Hour swap land an hour's
     * last song on the ID with.
     *
     * @param array<string, mixed> $line
     */
    private static function landsAt(array $line): float
    {
        return (int)$line['played_at'] + (float)($line['aired_duration'] ?? $line['duration'] ?? 0);
    }

    /** @param array<string, mixed> $line */
    private static function isPlannedAndUnlocked(array $line): bool
    {
        return !empty($line['log_entry_id'])
            && StationLogEntry::STATUS_PLANNED === ($line['log_status'] ?? null)
            && empty($line['is_locked']);
    }

    /**
     * The saved lines still to air that hold air time in the hour starting at $hourStart.
     *
     * @return list<array<string, mixed>>
     */
    private function openLinesOf(int $stationId, int $hourStart): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT status, planned_at, duration, media_id, playlist_id FROM station_log_entries
            WHERE station_id = ? AND status IN (?, ?) AND planned_at < ? AND planned_at + duration > ?',
            [
                $stationId,
                StationLogEntry::STATUS_PLANNED,
                StationLogEntry::STATUS_QUEUED,
                $hourStart + 3600,
                $hourStart,
            ]
        );
    }

    /**
     * True when one of the hour's lines is a stream, or a programme the strict
     * lane plays from its own source still holds the air where the hour's
     * last line starts.
     *
     * A strict-lane show that has ended hands the air back, and the lines
     * after it are the queue's like any other hour's: the 5pm hour of "Faith
     * Horizons" (over at 5:28pm) was left 0:33 short of its ID on the page for
     * the day before it aired (Wed 2026-10-14).
     *
     * @param list<array<string, mixed>> $openLines
     * @param int $closingStartsAt when the hour's last line starts, as the page lists it
     */
    private function carriesProgramme(Station $station, array $openLines, int $closingStartsAt): bool
    {
        $strictLane = [];
        foreach ($station->playlists as $playlist) {
            if ($this->strictProgrammeClock->isPlayedByStrictLane($playlist)) {
                $strictLane[$playlist->id] = true;
            }
        }

        // A show's line names no file, whichever lane plays it: the playlist
        // tells a strict-lane show from a stream.
        $hasStrictLaneLine = false;
        foreach ($openLines as $line) {
            $isStrictLane = isset($strictLane[(int)$line['playlist_id']]);
            if (null === $line['media_id'] && !$isStrictLane) {
                return true;
            }
            $hasStrictLaneLine = $hasStrictLaneLine || $isStrictLane;
        }

        return $hasStrictLaneLine
            && null !== $this->strictProgrammeClock->holdAt(
                $station,
                CarbonImmutable::createFromTimestamp($closingStartsAt, 'UTC')->toDateTimeImmutable()
            );
    }

    /**
     * Land the hour's last line on the ID, when it ends more than half a
     * minute past the ID or short of it: what the Top-of-Hour swap does when
     * the queue reaches the line, done in the log ahead of it.
     *
     * Two songs can do it. The last song can be another one, as long as the
     * room it starts with (refitOfClosing()); and the song before it can be
     * one as much longer or shorter as the hour is out, so the last line
     * starts where it ends on the ID (refitOfSongBefore()). The second is
     * looked for when the first does not bring the hour on time, and when the
     * hour ends on a liner or a promo, which is not replaced. Whichever lands
     * the hour closer is written, when that is closer than the swap's own
     * tolerance would leave it.
     *
     * An ending nothing brings closer to the ID is left to the swap, which
     * replaces a last song the ID would cut when it airs. The last song is not
     * dropped ahead of time when no song is short enough for its room: the
     * room is an estimate, and an hour that then ran short had lost its last
     * song for good.
     *
     * @param array{id_at: int, lines: non-empty-list<array<string, mixed>>, ...} $hour
     * @param array<string, mixed> $closing the hour's last line, as the page lists it
     * @param float $offBy seconds it ends past the ID; negative when short of it
     * @return int lines re-fitted
     */
    private function landClosingSong(int $stationId, array $hour, array $closing, float $offBy): int
    {
        $song = $this->fittableSong($closing);
        if (null === $song && !$this->passesTimeOn($closing)) {
            return 0;
        }

        $ofClosing = null !== $song ? $this->refitOfClosing($stationId, $hour['id_at'], $closing, $song) : null;

        // The song before is looked for unless the last line's own song is
        // written and brings the hour on time.
        $landsOnTime = null !== $ofClosing
            && self::landsCloser($ofClosing, $offBy)
            && abs($ofClosing['lands_off_by']) <= self::ON_TIME_SECONDS;
        $refits = array_filter([
            $ofClosing,
            $landsOnTime ? null : $this->refitOfSongBefore($stationId, $hour, $closing, $offBy),
        ]);

        // The one that lands the hour closer is written. Where its line was
        // claimed for the queue or locked in the meantime, the other is.
        usort($refits, static fn(array $a, array $b): int => abs($a['lands_off_by']) <=> abs($b['lands_off_by']));
        foreach ($refits as $refit) {
            if (self::landsCloser($refit, $offBy) && $this->putSong($refit, $offBy) > 0) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * True when a re-fit brings the hour's ending closer to the ID than the
     * Top-of-Hour swap's own tolerance would leave it: less is no reason to
     * change a line.
     *
     * @param array{lands_off_by: float, ...} $refit
     * @param float $offBy seconds the hour's last line ends past the ID now; negative when short of it
     */
    private static function landsCloser(array $refit, float $offBy): bool
    {
        return abs($refit['lands_off_by']) <= abs($offBy) - LinearLogRefill::ID_GRACE_SECONDS;
    }

    /**
     * A song for the hour's last line that ends on the ID, from the line's own
     * playlist while that may play. Null when no song is as short as the room
     * the line starts with.
     *
     * @param array<string, mixed> $closing the hour's last line, as the page lists it
     * @param StationLogEntry $line the saved line behind it
     * @return array{id: int, fields: array<string, mixed>, song: string, lands_off_by: float}|null
     */
    private function refitOfClosing(int $stationId, int $idAt, array $closing, StationLogEntry $line): ?array
    {
        $station = $this->em->find(Station::class, $stationId);
        $startsAt = (int)$closing['played_at'];
        $room = $idAt - $startsAt;
        if (!$station instanceof Station || $room < LinearLogRefill::MIN_SONG_SECONDS) {
            return null;
        }

        // Read before the search: it may clear the entity manager.
        $id = $line->id;
        $note = self::REFITTED_NOTE . self::firstPlanned($line);
        $kept = self::keptPayload($line);

        $pick = $this->refill->pickReplacement(
            $station,
            CarbonImmutable::createFromTimestamp($startsAt, 'UTC')->toDateTimeImmutable(),
            $room,
            $room + LinearLogRefill::ID_GRACE_SECONDS,
            $line->playlist,
            $line->media?->id,
            LinearLogSongMatch::OnId
        );
        if (null === $pick) {
            return null;
        }

        [$media, $playlist] = $pick;

        return [
            'id' => $id,
            'fields' => [...LinearLogRefill::songFields($media, $playlist, $kept), 'note' => mb_substr($note, 0, 255)],
            'song' => (string)$media->text,
            'lands_off_by' => $this->airedLength->lengthOf($media) - $room,
        ];
    }

    /**
     * A song for the line before the hour's last one, as much longer or
     * shorter than the song there as the hour ends short of the ID or past it.
     *
     * The last line can only be given a song as long as the room it starts
     * with, and a playlist has none for some rooms: 2:04 before the 2am ID
     * and 8:54 before the 7pm ID were left 0:40 short (Tue 2026-10-13). The
     * two lines together can be given what one cannot.
     *
     * @param array{id_at: int, lines: non-empty-list<array<string, mixed>>, ...} $hour
     * @param array<string, mixed> $closing the hour's last line, as the page lists it
     * @param float $offBy seconds it ends past the ID; negative when short of it
     * @return array{id: int, fields: array<string, mixed>, song: string, lands_off_by: float}|null
     */
    private function refitOfSongBefore(int $stationId, array $hour, array $closing, float $offBy): ?array
    {
        $before = $this->songBefore($hour['lines'], $closing);
        $line = null !== $before ? $this->fittableSong($before) : null;
        $station = $this->em->find(Station::class, $stationId);
        if (null === $before || null === $line || !$station instanceof Station) {
            return null;
        }

        // A last song is landed by the length it holds the air for, as the
        // swap lands it. A liner or a promo is landed by its file's length,
        // which is what decides whether it airs before the ID (LinearLogHour),
        // with none of the grace a song is given.
        $closesOnSong = LinearLogRefill::MUSIC_TYPE === ($closing['media_type'] ?? null);
        $closingSeconds = (float)($closing['duration'] ?? 0);
        $endsOffBy = $closesOnSong ? $offBy : (int)$closing['played_at'] + $closingSeconds - $hour['id_at'];

        // As the page lists it, which is what the hour was measured with.
        $length = (float)$before['duration'];
        $wanted = $length - $endsOffBy;
        if ($wanted < LinearLogRefill::MIN_SONG_SECONDS) {
            return null;
        }

        // Read before the search: it may clear the entity manager.
        $id = $line->id;
        $note = self::REFITTED_BEFORE_NOTE . self::firstPlanned($line);
        $kept = self::keptPayload($line);

        $pick = $this->refill->pickReplacement(
            $station,
            CarbonImmutable::createFromTimestamp((int)$before['played_at'], 'UTC')->toDateTimeImmutable(),
            $wanted,
            $wanted + ($closesOnSong ? LinearLogRefill::ID_GRACE_SECONDS : 0),
            $line->playlist,
            $line->media?->id,
            LinearLogSongMatch::Closest
        );
        if (null === $pick) {
            return null;
        }

        // Every line after this one moves by what it gains or loses, and the
        // last one must still air before the ID where it then starts. The
        // search may have cleared the entity manager.
        [$media, $playlist] = $pick;
        $fields = LinearLogRefill::songFields($media, $playlist, $kept);
        $movesBy = (float)$fields['duration'] - $length;
        $station = $this->em->find(Station::class, $stationId);
        if (
            !$station instanceof Station
            || $this->hour->opensNextHour($station, (int)floor((int)$closing['played_at'] + $movesBy), $closingSeconds)
        ) {
            return null;
        }

        return [
            'id' => $id,
            'fields' => [...$fields, 'note' => mb_substr($note, 0, 255)],
            'song' => (string)$media->text,
            'lands_off_by' => $offBy + $movesBy,
        ];
    }

    /**
     * The song that airs before the hour's last line, with nothing between
     * them but liners and promos: lines whose times move with it. Null when a
     * queued or locked line, a show or a Clock Wheel's slot comes first.
     *
     * @param non-empty-list<array<string, mixed>> $lines the hour's lines, as the page lists them
     * @param array<string, mixed> $closing the hour's last line
     * @return array<string, mixed>|null
     */
    private function songBefore(array $lines, array $closing): ?array
    {
        $earlier = array_filter(
            $lines,
            static fn(array $line): bool => (int)$line['played_at'] < (int)$closing['played_at']
        );
        usort($earlier, static fn(array $a, array $b): int => (int)$b['played_at'] <=> (int)$a['played_at']);

        foreach ($earlier as $line) {
            if (!$this->passesTimeOn($line)) {
                $isSong = LinearLogRefill::MUSIC_TYPE === ($line['media_type'] ?? null);

                return $isSong && self::isPlannedAndUnlocked($line) ? $line : null;
            }
        }

        return null;
    }

    /**
     * True for a planned, unlocked line that is neither a song nor a show nor
     * a Clock Wheel's slot (a liner, a promo): the fit does not replace it,
     * and it starts whenever the line before it ends.
     *
     * @param array<string, mixed> $listed the line as the page lists it
     */
    private function passesTimeOn(array $listed): bool
    {
        if (
            !self::isPlannedAndUnlocked($listed)
            || LinearLogRefill::MUSIC_TYPE === ($listed['media_type'] ?? null)
            || self::isShow($listed)
        ) {
            return false;
        }

        $line = $this->em->find(StationLogEntry::class, (int)$listed['log_entry_id']);

        return $line instanceof StationLogEntry && null === ($line->payload['clock_wheel_id'] ?? null);
    }

    /**
     * True for a show: a timed event, not a line the hour is filled with.
     *
     * @param array<string, mixed> $listed the line as the page lists it
     */
    private static function isShow(array $listed): bool
    {
        return 'scheduled_programme' === ($listed['source_type'] ?? null)
            || (float)($listed['duration'] ?? 0) >= LinearLogRefill::PROGRAMME_MIN_SECONDS;
    }

    /**
     * The saved line behind a listed one, when the fit may give it another
     * song: a planned, unlocked song. A show keeps its own window, a Clock
     * Wheel's slot keeps the song the wheel gave it, and a line playout handed
     * to the player and took back keeps the song it was handed over with.
     *
     * @param array<string, mixed> $listed the line as the page lists it
     */
    private function fittableSong(array $listed): ?StationLogEntry
    {
        if (
            !self::isPlannedAndUnlocked($listed)
            || LinearLogRefill::MUSIC_TYPE !== ($listed['media_type'] ?? null)
        ) {
            return null;
        }

        $line = $this->em->find(StationLogEntry::class, (int)$listed['log_entry_id']);

        return $line instanceof StationLogEntry
            && null !== $line->media
            && LinearLogHourGuard::isHourBound($line)
            && null === ($line->payload['clock_wheel_id'] ?? null)
            && !str_starts_with((string)$line->note, StationLogEntry::REOFFERED_NOTE)
            ? $line
            : null;
    }

    /** What the plan first had in a line, through however many re-fits. */
    private static function firstPlanned(StationLogEntry $line): string
    {
        foreach ([self::REFITTED_NOTE, self::REFITTED_BEFORE_NOTE] as $note) {
            if (null !== $line->note && str_starts_with($line->note, $note)) {
                return substr($line->note, strlen($note));
            }
        }

        return $line->text ?? 'unknown';
    }

    /**
     * What a line is beyond its song.
     *
     * @return array<string, mixed>
     */
    private static function keptPayload(StationLogEntry $line): array
    {
        return array_intersect_key($line->payload ?? [], ['top_up' => true, 'refill_of' => true]);
    }

    /**
     * Give a line another song, only while it is still planned and unlocked:
     * the operator may have locked it since it was read. A line playout is
     * claiming for the queue at this moment airs as playout read it, and
     * playout writes it back so (LinearLogPlayout::linkSelection()).
     *
     * @param array{id: int, fields: array<string, mixed>, song: string, lands_off_by: float} $refit
     * @param float $offBy seconds the hour's last line ended past the ID before; negative when short of it
     * @return int lines re-fitted
     */
    private function putSong(array $refit, float $offBy): int
    {
        $refitted = (int)$this->em->getConnection()->update(
            'station_log_entries',
            $refit['fields'],
            ['id' => $refit['id'], 'status' => StationLogEntry::STATUS_PLANNED, 'is_locked' => 0]
        );

        if ($refitted > 0) {
            $this->logger->notice('Linear Log: re-fitted a song so its hour lands on its ID.', [
                'log_entry_id' => $refit['id'],
                'note' => $refit['fields']['note'],
                'song' => $refit['song'],
                'was_off_by' => round($offBy, 1),
                'lands_off_by' => round($refit['lands_off_by'], 1),
            ]);
        }

        return $refitted;
    }
}
