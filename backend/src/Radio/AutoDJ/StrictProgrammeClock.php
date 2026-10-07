<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * When a strict programme played by Liquidsoap's own strict lane
 * (RigidScheduleRuntimeConfiguration) holds the air.
 *
 * That lane plays the show's files itself, so the show never appears in the
 * AutoDJ queue. Projected back to back, every queued song behind it was timed
 * as if the show were not there: at 16:55 the songs planned after Faith
 * Horizons (17:28-17:59) were projected at 17:03, inside the show's window,
 * and the schedule guard deleted them (Wed 2026-10-07).
 *
 * Only shows with library files: a remote-stream programme is queued as its
 * own row (LinearLogStore::toRemoteProgrammeRow()), so its time is already
 * in the queue.
 */
final class StrictProgrammeClock
{
    use EntityManagerAwareTrait;

    /**
     * How far before its window a show's first item may be projected and still
     * open the show: on air the Top-of-Hour swap ends the hour's last song on
     * the ID, so the show waits for it.
     */
    private const int OPENER_LEAD_IN_SECONDS = 300;

    /** News length when song history has none yet (the TOH swap uses the same). */
    private const float DEFAULT_NEWS_SECONDS = 150.0;

    public function __construct(
        private readonly RigidScheduleWindowResolver $windowResolver,
        private readonly Scheduler $scheduler,
        private readonly TopOfHourClock $topOfHourClock,
        private readonly AiNewsScheduleForecastService $aiNewsForecast,
    ) {
    }

    /**
     * True when the strict lane, not the AutoDJ, plays this playlist.
     */
    public function isPlayedByStrictLane(StationPlaylist $playlist): bool
    {
        if (PlaylistSources::Songs !== $playlist->source || 0 === $playlist->schedule_items->count()) {
            return false;
        }

        foreach ($playlist->schedule_items as $schedule) {
            if (!RigidScheduleWindowResolver::isAiredByStrictLane($playlist, $schedule)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The span a strict show holds the air if something would start at $at, or
     * null when no strict show owns the air then.
     *
     * The show starts when its window opens or, for a window opening on the
     * hour, when the Top-of-Hour ID and news release the air: the strict lane
     * waits for them (rigid_schedule_toh_lane_owns_air). Once it has aired,
     * its real start from song history is used. It holds the
     * air for one pass of its content when its schedule plays once, otherwise
     * until its window closes.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, playlist: StationPlaylist}|null
     */
    public function holdAt(Station $station, DateTimeImmutable $at): ?array
    {
        $at = CarbonImmutable::instance($at);

        $windows = $this->windowResolver->getWindows(
            $station,
            $at->subDay()->toDateTimeImmutable(),
            $at->addSecond()->toDateTimeImmutable(),
        );

        foreach ($windows as $window) {
            $playlist = $window['playlist'];
            if (!$this->isPlayedByStrictLane($playlist)) {
                continue;
            }

            $windowStart = CarbonImmutable::instance($window['start']);
            $windowEnd = CarbonImmutable::instance($window['end']);
            if ($at->lessThan($windowStart) || $at->greaterThanOrEqualTo($windowEnd)) {
                continue;
            }

            $airedAt = $this->firstPlayInWindow($station, $playlist, $windowStart, $windowEnd);
            if (null === $airedAt && $at->lessThan(CarbonImmutable::now('UTC')->subMinutes(2))) {
                // Asked about a moment already past and the show never started:
                // nothing holds the air there.
                continue;
            }

            $start = $airedAt ?? $this->airFreeFrom($station, $windowStart);
            $contentSeconds = $window['schedule']->loop_once
                ? $this->scheduler->loopOnceContentDurationSeconds($window['schedule'])
                : null;
            $end = null !== $contentSeconds
                ? $start->addSeconds((int)ceil($contentSeconds))->min($windowEnd)
                : $windowEnd;

            if ($at->greaterThanOrEqualTo($end) || $start->greaterThanOrEqualTo($windowEnd)) {
                continue;
            }

            return ['start' => $start, 'end' => $end, 'playlist' => $playlist];
        }

        return null;
    }

    /**
     * True when this show's strict window is open now and the show has already
     * aired its hold in it: one pass of a play-once show is over.
     */
    public function hasFinishedInOpenWindow(Station $station, StationPlaylist $playlist): bool
    {
        $now = CarbonImmutable::now('UTC');
        $window = $this->windowResolver->getActiveWindow($station, $now->toDateTimeImmutable());
        if (null === $window || $window['playlist']->id !== $playlist->id) {
            return false;
        }

        $windowStart = CarbonImmutable::instance($window['start']);
        $windowEnd = CarbonImmutable::instance($window['end']);
        if (null === $this->firstPlayInWindow($station, $playlist, $windowStart, $windowEnd)) {
            return false;
        }

        return null === $this->holdAt($station, $now->toDateTimeImmutable());
    }

    /**
     * When a show's first item really starts if it is projected to start just
     * before its own window opens: at the window start, or after the
     * Top-of-Hour ID and news for a window opening on the hour. Null when $at
     * is not in that lead-in.
     *
     * Far-ahead queue times leave out the ID and news, so a show's opener could
     * be projected seconds before its window. Judged there, the schedule guard
     * pulled it as outside its window: Altered Stories, projected at 18:59:58
     * at 18:01, lost its 19:00:37 line, and the songs behind it, now at 19:00
     * inside the show's window, were pulled as well (Wed 2026-10-07).
     */
    public function programmeOpensAt(
        Station $station,
        StationPlaylist $playlist,
        DateTimeImmutable $at,
    ): ?CarbonImmutable {
        $at = CarbonImmutable::instance($at);
        $tz = $station->getTimezoneObject();

        foreach ($playlist->schedule_items as $schedule) {
            $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                $schedule,
                $tz,
                $at->setTimezone($tz),
                $at->setTimezone($tz)->addSeconds(self::OPENER_LEAD_IN_SECONDS + 1),
            );

            foreach ($occurrences as $occurrence) {
                $windowStart = CarbonImmutable::instance($occurrence->start);
                $leadInStart = $windowStart->subSeconds(self::OPENER_LEAD_IN_SECONDS);
                if ($at->lessThan($windowStart) && $at->greaterThanOrEqualTo($leadInStart)) {
                    return $this->airFreeFrom($station, $windowStart);
                }
            }
        }

        return null;
    }

    /**
     * When the strict lane can take the air for a window opening at
     * $windowStart: the window start, or the end of the Top-of-Hour ID and any
     * news bulletin when the window opens on the hour. Faith Horizons' window
     * opens at 17:00; the ID and news held the air until 17:03:25.
     */
    public function airFreeFrom(Station $station, CarbonImmutable $windowStart): CarbonImmutable
    {
        if (!$this->topOfHourClock->isEnabled($station)) {
            return $windowStart;
        }

        $beforeBoundary = $windowStart->subMinutes(2)->toDateTimeImmutable();
        $boundary = CarbonImmutable::instance($this->topOfHourClock->getNextBoundary($station, $beforeBoundary));
        if (!$boundary->equalTo($windowStart)) {
            return $windowStart;
        }

        $plan = $this->topOfHourClock->plan($station, $beforeBoundary);
        if (null === $plan) {
            return $windowStart;
        }

        $target = CarbonImmutable::instance($plan->targetStartAt);
        $release = $target->addSeconds((int)ceil($plan->durationSeconds));

        $news = $this->aiNewsForecast->getAiringTimes(
            $station,
            $target->subMinute()->toDateTimeImmutable(),
            $boundary->addMinutes(3)->toDateTimeImmutable(),
        );
        if ([] !== $news) {
            $newsSeconds = (float)($this->em->getConnection()->fetchOne(
                'SELECT AVG(d) FROM (
                    SELECT duration AS d FROM song_history
                    WHERE station_id = ? AND text = ? AND duration > 30
                    ORDER BY id DESC LIMIT 5
                ) recent',
                [$station->id, 'Eternity Ready - News Hour']
            ) ?: self::DEFAULT_NEWS_SECONDS);
            $release = $release->addSeconds((int)ceil($newsSeconds));
        }

        return $release->max($windowStart);
    }

    private function firstPlayInWindow(
        Station $station,
        StationPlaylist $playlist,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): ?CarbonImmutable {
        $first = $this->em->createQuery(
            <<<'DQL'
                SELECT MIN(sh.timestamp_start) FROM App\Entity\SongHistory sh
                WHERE sh.station = :station AND sh.playlist = :playlist
                AND sh.timestamp_start >= :windowStart AND sh.timestamp_start < :windowEnd
            DQL
        )->setParameter('station', $station)
            ->setParameter('playlist', $playlist)
            ->setParameter('windowStart', $windowStart)
            ->setParameter('windowEnd', $windowEnd)
            ->getSingleScalarResult();

        return null !== $first ? CarbonImmutable::parse((string)$first, 'UTC') : null;
    }
}
