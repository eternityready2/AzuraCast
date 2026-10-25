<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Song;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Event\Radio\BuildQueue;
use App\Event\Radio\ResolveQueueClockConstraint;
use App\Event\Radio\RevalidateQueuedSong;
use App\Utilities\ScheduleRecurrence;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A show goes on after whatever takes the air away from it.
 *
 * The Top-of-Hour ID cuts the item on air at :59:59 and the next item starts
 * from its beginning; for music that is the rule. A show file longer than the
 * time left in the hour was cut the same way and never came back: Mad Christian
 * Radio (one 2h50m file, Saturdays 12-3pm) was cut at the 2pm ID, a song aired
 * in its place, and the file then started again from 0:00 (Sat 2026-10-10).
 *
 * A show is a playlist with "Spoken-Word Programming" ticked. Its file airs in
 * parts: each part runs until its file ends or the clock takes the air, and the
 * next part starts where the one before it stopped. Where a part starts is
 * worked out from the parts already queued and aired, so it holds through a
 * late start or a restart, and nothing about it is stored. A part is an
 * ordinary queue row and an ordinary log line, with its own length.
 */
final class ProgrammeParts implements EventSubscriberInterface
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    /** A file with less than this left is finished; nothing comes back for it. */
    private const float MIN_REMAINDER_SECONDS = 15.0;

    /** A part due this close to a clock event waits for it instead of starting. */
    private const float MIN_PART_SECONDS = 60.0;

    /** A part picks up this far before the cut: the ID fades the last seconds out. */
    private const float REJOIN_SECONDS = 5.0;

    /** How far back the part before a row is looked for. */
    private const int LOOKBACK_SECONDS = 6 * 3600;

    /** How far song history may sit from a queue row's played time and still be that play. */
    private const int HISTORY_MATCH_SECONDS = 120;

    private const int MAX_PARTS = 24;

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Scheduler $scheduler,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After every picker and timing listener (stretch/squeeze is -6),
            // before the Linear Log links the row (-190).
            BuildQueue::class => ['timePick', -8],
            // After the holds (Top-of-Hour -1, strict programme -5), which say
            // when a held row really starts; before the schedule guard (-10).
            RevalidateQueuedSong::class => ['retimeQueuedRow', -7],
            // After the cue and cap annotators (15 down to 9).
            AnnotateNextSong::class => ['annotatePart', 7],
        ];
    }

    public function isProgrammeRow(?StationQueue $row): bool
    {
        if (null === $row || null === $row->media || null !== $row->autodj_custom_uri) {
            return false;
        }
        if ($row->top_of_hour_legal_id || $row->clock_wheel_legal_id_substitute || null !== $row->request) {
            return false;
        }

        $playlist = $row->playlist;

        return $playlist instanceof StationPlaylist
            && $playlist->is_programme
            && PlaylistSources::Songs === $playlist->source;
    }

    /**
     * The row that carries on a show the slot at $at interrupts, or null when no
     * show is waiting to go on: the item before the slot is not a show, its file
     * has finished, or its playlist may no longer play.
     */
    public function continuationFor(Station $station, DateTimeImmutable $at): ?StationQueue
    {
        $before = $this->rowBefore($station, $at, null);
        if (null === $before || !$this->isProgrammeRow($before) || null === $before->timestamp_played) {
            return null;
        }

        $media = $before->media;
        $playlist = $before->playlist;
        if (!$media instanceof StationMedia || !$playlist instanceof StationPlaylist) {
            return null;
        }

        $offset = $this->position($before, $before->timestamp_played)['offset'];
        $stoppedAt = $offset + $this->airedSeconds($before, $offset);
        $left = $media->length - $stoppedAt;
        if ($left < self::MIN_REMAINDER_SECONDS) {
            return null;
        }

        // Judged where the part will air, which is after the clock event when
        // the slot is the cut itself.
        $airtime = $this->airtime($station, $at, $left);
        if (!$this->scheduler->isPlaylistAllowedAt($playlist, $airtime['start'])) {
            return null;
        }

        $row = StationQueue::fromMedia($station, $media);
        $row->playlist = $playlist;
        $this->em->persist($row);

        $playlist->played_at = $at;
        $this->em->persist($playlist);

        $this->logger->info('The show goes on after the clock event that interrupts it.', [
            'playlist' => $playlist->name,
            'media_id' => $media->id,
            'from_seconds' => round(max(0.0, $stoppedAt - self::REJOIN_SECONDS), 1),
            'airs_at' => $airtime['start']->format(DATE_ATOM),
        ]);

        return $row;
    }

    public function timePick(BuildQueue $event): void
    {
        $rows = $event->getNextSongs();
        if (1 !== count($rows) || !$this->isProgrammeRow($rows[0])) {
            return;
        }

        $this->time($rows[0], DateTimeImmutable::createFromInterface($event->getExpectedPlayTime()));
    }

    public function retimeQueuedRow(RevalidateQueuedSong $event): void
    {
        $row = $event->getQueueRow();
        if (!$this->em->contains($row) || $row->sent_to_autodj || $row->is_played || !$this->isProgrammeRow($row)) {
            return;
        }

        $start = $event->getOpensAfter() ?? $event->getExpectedPlayAt();
        if ($start < $event->getExpectedPlayAt()) {
            $start = $event->getExpectedPlayAt();
        }

        // The rows ahead of this one were re-timed a moment ago in the same
        // pass; the part before it is looked up by those times.
        $this->em->flush();

        $this->time($row, $start);
        $this->em->persist($row);
    }

    /**
     * A part after the first is sent with its own cue-in, through the cue points
     * AzuraCast already hands Liquidsoap for every analysed song.
     */
    public function annotatePart(AnnotateNextSong $event): void
    {
        $row = $event->getQueue();
        $media = $event->getMedia();
        if (
            null === $row
            || !$event->isAsAutoDj()
            || !$this->isProgrammeRow($row)
            || !$media instanceof StationMedia
        ) {
            return;
        }

        $start = $event->getExpectedPlayAt() ?? $row->timestamp_played ?? Time::nowUtc();
        $position = $this->time($row, DateTimeImmutable::createFromInterface($start));
        if ($position['offset'] <= 0.0) {
            return;
        }

        // A play cap counts seconds on air, so with a cue-in it ends that many
        // seconds after it.
        $cueOut = $media->length;
        if ($row->hour_boundary_enforce_cap && ($row->hour_boundary_max_play_seconds ?? 0) > 0) {
            $cueOut = min($cueOut, $position['offset'] + (float)$row->hour_boundary_max_play_seconds);
        }

        $event->addAnnotations([
            'title' => $row->title,
            'song_id' => $row->song_id,
            'duration' => round($cueOut - $position['offset'], 2),
            'azuracast_autocue' => true,
            'autocue_cue_in' => round($position['offset'], 2),
            'autocue_cue_out' => $cueOut,
            'autocue_fade_in' => 0.0,
            'autocue_fade_out' => 0.0,
        ]);
    }

    /**
     * Give a show's row the length it will hold the air for and, after the
     * first part, its own name: Liquidsoap reports a track change by title, and
     * the queue reads a second row of one song as a repeat to retry.
     *
     * @return array{offset: float, number: int}
     */
    private function time(StationQueue $row, DateTimeImmutable $start): array
    {
        $media = $row->media;
        if (!$media instanceof StationMedia) {
            return ['offset' => 0.0, 'number' => 1];
        }

        $position = $this->position($row, $start);
        $left = $position['offset'] > 0.0
            ? max(1.0, $media->length - $position['offset'])
            : max(1.0, $media->getCalculatedLength());
        $seconds = $this->airtime($row->station, $start, $left)['seconds'];

        // A whole show the clock leaves alone keeps the length the queue gave
        // it, as before.
        if ($position['offset'] > 0.0 || $seconds < $left) {
            if ($row->hour_boundary_enforce_cap && ($row->hour_boundary_max_play_seconds ?? 0) > 0) {
                $seconds = min($seconds, (float)$row->hour_boundary_max_play_seconds);
            }
            $row->duration = max(1.0, $seconds);
        }

        $title = $position['number'] > 1
            ? sprintf('%s (Part %d)', $this->baseTitle($media), $position['number'])
            : $media->title;
        if ($row->title !== $title) {
            $row->setSong(Song::createFromArray([
                'artist' => $media->artist,
                'title' => $title,
                'album' => $media->album,
            ]));
        }

        return $position;
    }

    private function baseTitle(StationMedia $media): string
    {
        $title = trim((string)$media->title);

        return '' !== $title ? $title : pathinfo($media->path, PATHINFO_FILENAME);
    }

    /**
     * Where in its file the row starts, and which part of the show that is.
     *
     * @return array{offset: float, number: int}
     */
    private function position(StationQueue $row, DateTimeInterface $start, int $depth = 0): array
    {
        $first = ['offset' => 0.0, 'number' => 1];
        $media = $row->media;
        if (!$media instanceof StationMedia || $depth >= self::MAX_PARTS) {
            return $first;
        }

        $before = $this->partBefore($row, $start);
        if (null === $before || null === $before->timestamp_played) {
            return $first;
        }

        $beforePosition = $this->position($before, $before->timestamp_played, $depth + 1);
        $stoppedAt = $beforePosition['offset'] + $this->airedSeconds($before, $beforePosition['offset']);

        // The part before it reached the end of the file: this is a new play.
        if ($media->length - $stoppedAt < self::MIN_REMAINDER_SECONDS) {
            return $first;
        }

        return [
            'offset' => max(0.0, $stoppedAt - self::REJOIN_SECONDS),
            'number' => $beforePosition['number'] + 1,
        ];
    }

    /**
     * The part of the same file this row follows: for a scheduled show, the
     * newest earlier one in the same occurrence of its schedule; otherwise only
     * the item that airs immediately before the row.
     */
    private function partBefore(StationQueue $row, DateTimeInterface $start): ?StationQueue
    {
        $playlist = $row->playlist;
        $media = $row->media;
        if (!$playlist instanceof StationPlaylist || !$media instanceof StationMedia) {
            return null;
        }

        $windowStart = $this->scheduleWindowStart($playlist, $start);
        if (null === $windowStart) {
            $before = $this->rowBefore($row->station, $start, $row);

            return null !== $before
                && $this->isProgrammeRow($before)
                && $before->media?->id === $media->id
                && $before->playlist?->id === $playlist->id
                ? $before
                : null;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('sq')
            ->from(StationQueue::class, 'sq')
            ->where('sq.station = :station')
            ->andWhere('sq.media = :media')
            ->andWhere('sq.playlist = :playlist')
            ->andWhere('sq.timestamp_played < :start')
            ->andWhere('sq.timestamp_played >= :since')
            ->orderBy('sq.timestamp_played', 'DESC')
            ->addOrderBy('sq.id', 'DESC')
            ->setParameter('station', $row->station)
            ->setParameter('media', $media)
            ->setParameter('playlist', $playlist)
            ->setParameter('start', CarbonImmutable::instance($start)->utc())
            // The window's first part is queued a little before it opens, to
            // start as the ID ends.
            ->setParameter('since', $windowStart->utc()->subSeconds(self::HISTORY_MATCH_SECONDS))
            ->setMaxResults(1);

        if (isset($row->id)) {
            $qb->andWhere('sq.id != :self')->setParameter('self', $row->id);
        }

        $before = $qb->getQuery()->getOneOrNullResult();

        return $before instanceof StationQueue && !$this->neverAired($before) ? $before : null;
    }

    /** The queue row that airs immediately before $at: the newest one that starts before it. */
    private function rowBefore(Station $station, DateTimeInterface $at, ?StationQueue $except): ?StationQueue
    {
        $moment = CarbonImmutable::instance($at)->utc();

        /** @var StationQueue[] $rows */
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sq FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.timestamp_played < :at
                AND sq.timestamp_played >= :since
                AND sq.top_of_hour_legal_id = 0
                ORDER BY sq.timestamp_played DESC, sq.id DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('at', $moment)
            ->setParameter('since', $moment->subSeconds(self::LOOKBACK_SECONDS))
            ->setMaxResults(4)
            ->getResult();

        foreach ($rows as $candidate) {
            if ($candidate !== $except && !$this->neverAired($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A Liquidsoap restart clears the queue by marking its rows played with
     * their projected air times still on them; a play cannot lie in the future.
     */
    private function neverAired(StationQueue $row): bool
    {
        return $row->is_played
            && null !== $row->timestamp_played
            && $row->timestamp_played->getTimestamp() > time() + 30;
    }

    /** Start of the occurrence of the playlist's schedule that is open at $at. */
    private function scheduleWindowStart(StationPlaylist $playlist, DateTimeInterface $at): ?CarbonImmutable
    {
        $tz = $playlist->station->getTimezoneObject();
        $moment = CarbonImmutable::instance($at)->setTimezone($tz);
        $latest = null;

        foreach ($playlist->schedule_items as $schedule) {
            foreach (
                ScheduleRecurrence::getOccurrencesInRange(
                    $schedule,
                    $tz,
                    $moment->subDay(),
                    $moment->addDay()
                ) as $occurrence
            ) {
                $start = CarbonImmutable::instance($occurrence->start);
                // A part held at the end of the window still belongs to it.
                if ($start <= $moment && $moment <= CarbonImmutable::instance($occurrence->end)) {
                    $latest = null === $latest || $start > $latest ? $start : $latest;
                }
            }
        }

        return $latest;
    }

    /**
     * Seconds of its file a part plays, starting $offset into it: what song
     * history says once the part is over, and until then up to the end of the
     * file or the clock event that takes the air first.
     */
    private function airedSeconds(StationQueue $row, float $offset): float
    {
        $media = $row->media;
        $start = $row->timestamp_played;
        if (!$media instanceof StationMedia || null === $start) {
            return 0.0;
        }

        $left = max(0.0, $media->length - $offset);

        if ($row->is_played) {
            $startTs = (float)$start->format('U.u');
            $aired = $this->em->getConnection()->fetchOne(
                'SELECT TIMESTAMPDIFF(MICROSECOND, timestamp_start, timestamp_end) / 1000000
                FROM song_history
                WHERE station_id = ? AND media_id = ? AND timestamp_end IS NOT NULL
                AND timestamp_start >= ? AND timestamp_start <= ?
                ORDER BY ABS(TIMESTAMPDIFF(MICROSECOND, timestamp_start, ?)) ASC
                LIMIT 1',
                [
                    $row->station->id,
                    $media->id,
                    gmdate('Y-m-d H:i:s', (int)$startTs - self::HISTORY_MATCH_SECONDS),
                    gmdate('Y-m-d H:i:s', (int)$startTs + self::HISTORY_MATCH_SECONDS),
                    gmdate('Y-m-d H:i:s', (int)$startTs),
                ]
            );
            if (is_numeric($aired) && (float)$aired > 0.0) {
                return min($left, (float)$aired);
            }
        }

        return $this->airtime(
            $row->station,
            DateTimeImmutable::createFromInterface($start),
            $left,
            $row->is_played,
        )['seconds'];
    }

    /**
     * When an item with $left seconds to play really starts, and how long it
     * holds the air: to its end, or to the clock event that interrupts it. Due
     * during that event or less than a minute before it, it starts when the
     * event is over.
     *
     * @return array{start: DateTimeImmutable, seconds: float}
     */
    private function airtime(Station $station, DateTimeImmutable $start, float $left, bool $started = false): array
    {
        // A clock event that is under way at $start, or begins within a minute
        // of it, has the air first. An item that has started is where it is.
        if (!$started) {
            $near = $this->clockCut(
                $station,
                CarbonImmutable::instance($start)->subSeconds((int)self::MIN_PART_SECONDS)->toDateTimeImmutable(),
                $left + self::MIN_PART_SECONDS,
            );
            if (
                null !== $near
                && $near['resume'] > $start
                && $this->secondsBetween($start, $near['interrupt']) < self::MIN_PART_SECONDS
            ) {
                $start = $near['resume'];
            }
        }

        $cut = $this->clockCut($station, $start, $left);

        return [
            'start' => $start,
            'seconds' => null === $cut ? $left : min($left, $this->secondsBetween($start, $cut['interrupt'])),
        ];
    }

    /**
     * The clock event (the Top-of-Hour ID) that takes the air from an item
     * starting at $start with $seconds to play, as the queue's own projection
     * asks for the song on air.
     *
     * @return array{interrupt: DateTimeImmutable, resume: DateTimeImmutable}|null
     */
    private function clockCut(Station $station, DateTimeImmutable $start, float $seconds): ?array
    {
        $event = new ResolveQueueClockConstraint(
            $station,
            $start,
            CarbonImmutable::instance($start)
                ->addMilliseconds((int)round(max(0.0, $seconds) * 1000))
                ->toDateTimeImmutable(),
        );
        $this->dispatcher->dispatch($event);

        $interruptAt = $event->getInterruptAt();
        $resumeAt = $event->getResumeAt();
        if (null === $interruptAt || null === $resumeAt || $interruptAt <= $start) {
            return null;
        }

        return ['interrupt' => $interruptAt, 'resume' => $resumeAt];
    }

    private function secondsBetween(DateTimeInterface $from, DateTimeInterface $to): float
    {
        return (float)$to->format('U.u') - (float)$from->format('U.u');
    }
}
