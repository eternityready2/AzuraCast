<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiDj;
use App\Entity\Repository\AiDjScheduleRepository;
use App\Entity\Repository\StationRepository;
use DateTimeImmutable;
use DateTimeInterface;

final class AiDjScheduler
{
    public function __construct(
        private readonly AiDjScheduleRepository $scheduleRepo,
        private readonly StationRepository $stationRepo,
    ) {
    }

    public function findActiveDj(int $stationId, ?DateTimeInterface $timestamp = null): ?AiDj
    {
        $station = $this->stationRepo->find($stationId);
        if (null === $station) {
            return null;
        }

        $now = null !== $timestamp
            ? DateTimeImmutable::createFromInterface($timestamp)
            : new DateTimeImmutable('now');

        $stationTime = $now->setTimezone($station->getTimezoneObject());
        $dayOfWeek = (int) $stationTime->format('N');
        $timeString = $stationTime->format('H:i:s');

        $schedule = $this->scheduleRepo->findActiveForTimeSlot(
            $stationId,
            $dayOfWeek,
            $timeString
        );

        return $schedule?->getAiDj();
    }
}
