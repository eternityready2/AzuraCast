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
        return $playlist->sponsor_guaranteed_plays_per_day * $fractionOfDayElapsed * 0.9;
    }
}
