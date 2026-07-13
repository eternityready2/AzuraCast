<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Repository\ClockWheelEventRepository;
use App\Entity\Station;
use App\Entity\StationHolidayOverride;
use App\Entity\StationClockWheel;
use App\Entity\StationPlaylist;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves holiday programming overrides for a station calendar date (Phase E).
 */
final class HolidayOverrideService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getActiveOverride(Station $station, DateTimeImmutable $when): ?StationHolidayOverride
    {
        $tz = $station->getTimezoneObject();
        $localDate = CarbonImmutable::instance($when)->setTimezone($tz)->startOfDay();

        $override = $this->em->createQuery(
            <<<'DQL'
                SELECT h
                FROM App\Entity\StationHolidayOverride h
                WHERE h.station = :station
                AND h.override_date = :date
                AND h.is_active = 1
            DQL
        )->setParameter('station', $station)
            ->setParameter('date', $localDate->toDateTimeImmutable())
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $override instanceof StationHolidayOverride ? $override : null;
    }

    public function getHolidayClockWheel(Station $station, DateTimeImmutable $when): ?StationClockWheel
    {
        $override = $this->getActiveOverride($station, $when);
        if ($override === null || $override->clock_wheel === null) {
            return null;
        }

        $wheel = $override->clock_wheel;

        return $wheel->is_active ? $wheel : null;
    }

    public function getHolidayPlaylist(Station $station, DateTimeImmutable $when): ?StationPlaylist
    {
        $override = $this->getActiveOverride($station, $when);
        if ($override === null || $override->playlist === null) {
            return null;
        }

        return $override->playlist->is_enabled ? $override->playlist : null;
    }
}
