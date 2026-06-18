<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\ClockWheelEvent;
use App\Entity\Enums\ClockWheelEventKind;
use App\Entity\Repository\ClockWheelEventRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records actual on-air play time for mandatory legal_id slots (clock wheel and station-wide top-of-hour).
 */
final class ClockWheelLegalIdPlaybackService
{
    public function __construct(
        private readonly ClockWheelEventRepository $eventRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function recordPlaybackIfLegalId(
        Station $station,
        ?StationQueue $queueRow,
        StationMedia $media,
    ): void {
        if ($queueRow === null) {
            return;
        }

        $isLegalIdPlayback = $media->type === 'legal_id'
            || ($queueRow->clock_wheel_legal_id_substitute ?? false)
            || ($queueRow->top_of_hour_legal_id ?? false);

        if (!$isLegalIdPlayback) {
            return;
        }

        $event = null;
        if ($queueRow->top_of_hour_legal_id) {
            $event = $this->eventRepo->findLatestUnplayedTopOfHourLegalIdQueued($station, $queueRow->id);
        } elseif ($queueRow->clock_wheel !== null) {
            $event = $this->eventRepo->findLatestUnplayedLegalIdQueued($queueRow->clock_wheel, $queueRow->id);
        }

        if (!$event instanceof ClockWheelEvent) {
            return;
        }

        $actualPlayAt = new DateTimeImmutable('now', $station->getTimezoneObject());
        $event->actual_play_at = $actualPlayAt;

        if ($event->expected_play_at instanceof DateTimeImmutable) {
            $event->drift_seconds = $this->computePlaybackDriftSeconds(
                $station,
                $event->expected_play_at,
                $actualPlayAt,
            );
        }

        $this->em->persist($event);
    }

    private function computePlaybackDriftSeconds(
        Station $station,
        DateTimeImmutable $expectedPlayAt,
        DateTimeImmutable $actualPlayAt,
    ): int {
        $tz = $station->getTimezoneObject();
        $expected = CarbonImmutable::instance($expectedPlayAt)->setTimezone($tz);
        $actual = CarbonImmutable::instance($actualPlayAt)->setTimezone($tz);

        return (int)($actual->getTimestamp() - $expected->getTimestamp());
    }
}
