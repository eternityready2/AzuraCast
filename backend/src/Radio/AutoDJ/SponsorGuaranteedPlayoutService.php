<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;
use App\Entity\StationPlaylist;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tracks whether sponsor-guaranteed playlists are on pace to hit their
 * required daily play count. If a sponsor is falling behind (e.g. half the
 * day has passed but only a quarter of the guaranteed plays have aired), this
 * flags it so QueueInterruptingTracks can force a play in, the same way the
 * Advanced-tab "Interrupt other songs" mechanism already works -- a real,
 * enforced guarantee, not just a best-effort rotation preference.
 */
final class SponsorGuaranteedPlayoutService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return StationPlaylist[] Sponsor playlists currently behind pace and
     *         needing a forced play to catch up before the day ends.
     */
    public function getPlaylistsBehindPace(Station $station, ?DateTimeImmutable $now = null): array
    {
        $tz = $station->getTimezoneObject();
        $localNow = CarbonImmutable::instance($now ?? new DateTimeImmutable('now'))->setTimezone($tz);

        $behindPace = [];

        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_sponsor || null === $playlist->sponsor_guaranteed_plays_per_day) {
                continue;
            }

            if (!$this->isWithinContractWindow($playlist, $localNow)) {
                continue;
            }

            $playedToday = $this->countPlaysToday($playlist, $localNow, $tz);
            $expectedByNow = $this->expectedPlaysByNow($playlist, $localNow);

            if ($playedToday < $expectedByNow) {
                $behindPace[] = $playlist;
            }
        }

        return $behindPace;
    }

    private function isWithinContractWindow(StationPlaylist $playlist, CarbonImmutable $now): bool
    {
        if (null !== $playlist->sponsor_contract_start && $now < $playlist->sponsor_contract_start) {
            return false;
        }

        if (null !== $playlist->sponsor_contract_end && $now > $playlist->sponsor_contract_end) {
            return false;
        }

        return true;
    }

    private function countPlaysToday(
        StationPlaylist $playlist,
        CarbonImmutable $localNow,
        DateTimeZone $tz,
    ): int {
        $dayStart = $localNow->startOfDay()->setTimezone(new DateTimeZone('UTC'));

        // The (string) cast drops explicit tz info, and DateTimeImmutable's
        // constructor then parses it using PHP's default timezone. This is
        // only safe because AppFactory::boot() calls
        // date_default_timezone_set('UTC') globally, matching the UTC
        // conversion above. If that global ever changes, this needs an
        // explicit `new DateTimeZone('UTC')` second constructor argument.
        return (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(sh.id)
                FROM App\Entity\SongHistory sh
                WHERE sh.playlist = :playlist
                AND sh.timestamp_start >= :dayStart
            DQL
        )->setParameter('playlist', $playlist)
            ->setParameter('dayStart', new DateTimeImmutable((string)$dayStart))
            ->getSingleScalarResult();
    }

    private function expectedPlaysByNow(StationPlaylist $playlist, CarbonImmutable $localNow): float
    {
        $secondsIntoDay = ($localNow->hour * 3600) + ($localNow->minute * 60) + $localNow->second;
        $fractionOfDayElapsed = $secondsIntoDay / 86400;

        // Round down slightly (0.9x) so a sponsor isn't flagged "behind" over
        // ordinary minute-to-minute timing noise -- only genuine, meaningful
        // pace gaps trigger a forced play.
        //
        // NOTE: because this multiplies the full-day fraction (which only
        // reaches ~0.99999 at 23:59:59) by 0.9, expectedPlaysByNow never
        // actually reaches the full sponsor_guaranteed_plays_per_day value,
        // even one second before midnight. A sponsor sitting at exactly 90%
        // of their guaranteed plays will never be flagged "behind pace" and
        // the last ~10% of a day's guarantee is never force-enforced by this
        // mechanism. That's a real gap against the "real, enforced guarantee"
        // description above -- intentional slack for noise tolerance, but
        // worth a deliberate decision (e.g. a separate end-of-day hard check)
        // if sponsors are contractually expecting the full 100%.
        return $playlist->sponsor_guaranteed_plays_per_day * $fractionOfDayElapsed * 0.9;
    }
}
