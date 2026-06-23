<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\AiDj;
use App\Entity\AiDjSchedule;
use App\Entity\Repository\AiDjScheduleRepository;
use App\Entity\Station;
use App\Tests\Module;
use Codeception\Test\Unit;
use DateTimeImmutable;

final class AiDjScheduleRepositoryTest extends Unit
{
    private AiDjScheduleRepository $repository;

    private ReloadableEntityManagerInterface $em;

    protected function _inject(Module $testsModule): void
    {
        $this->repository = $testsModule->container->get(AiDjScheduleRepository::class);
        $this->em = $testsModule->em;
    }

    protected function _before(): void
    {
        if (!$this->em->isOpen()) {
            $this->em->open();
        }
    }

    protected function _after(): void
    {
        if (!$this->em->isOpen()) {
            $this->em->open();
        }
    }

    public function testFindActiveForTimeSlotReturnsNullWhenNoSchedule(): void
    {
        $station = $this->persistStation();

        try {
            $result = $this->repository->findActiveForTimeSlot($station->getId(), 1, '10:00:00');

            self::assertNull($result);
        } finally {
            $this->removeStation($station);
        }
    }

    public function testFindActiveForTimeSlotReturnsMatchingSchedule(): void
    {
        $station = $this->persistStation();

        $dj = new AiDj($station);
        $dj->setName('Test DJ');
        $dj->setEnabled(true);

        $schedule = new AiDjSchedule($dj);
        $schedule->setLoopDays([1, 2, 3, 4, 5]);
        $schedule->setStartTime(new DateTimeImmutable('09:00'));
        $schedule->setEndTime(new DateTimeImmutable('17:00'));
        $schedule->setEnabled(true);

        $this->em->persist($dj);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            $result = $this->repository->findActiveForTimeSlot($station->getId(), 3, '10:30:00');

            self::assertInstanceOf(AiDjSchedule::class, $result);
            self::assertSame($schedule->getId(), $result->getId());
        } finally {
            $this->removeStation($station);
        }
    }

    public function testFindActiveForTimeSlotReturnsNullWhenOutsideTimeRange(): void
    {
        $station = $this->persistStation();

        $dj = new AiDj($station);
        $dj->setName('Test DJ');
        $dj->setEnabled(true);

        $schedule = new AiDjSchedule($dj);
        $schedule->setLoopDays([1, 2, 3, 4, 5]);
        $schedule->setStartTime(new DateTimeImmutable('09:00'));
        $schedule->setEndTime(new DateTimeImmutable('17:00'));
        $schedule->setEnabled(true);

        $this->em->persist($dj);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            $result = $this->repository->findActiveForTimeSlot($station->getId(), 3, '18:00:00');

            self::assertNull($result);
        } finally {
            $this->removeStation($station);
        }
    }

    public function testFindActiveForTimeSlotReturnsNullWhenWrongDay(): void
    {
        $station = $this->persistStation();

        $dj = new AiDj($station);
        $dj->setName('Weekday DJ');
        $dj->setEnabled(true);

        $schedule = new AiDjSchedule($dj);
        $schedule->setLoopDays([1, 2, 3, 4, 5]);
        $schedule->setStartTime(new DateTimeImmutable('00:00'));
        $schedule->setEndTime(new DateTimeImmutable('23:59'));
        $schedule->setEnabled(true);

        $this->em->persist($dj);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            $result = $this->repository->findActiveForTimeSlot($station->getId(), 6, '10:00:00');

            self::assertNull($result);
        } finally {
            $this->removeStation($station);
        }
    }

    private function persistStation(): Station
    {
        $station = new Station();
        $station->name = 'Schedule Repo Test';
        $station->short_name = 'sched_repo_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $this->em->persist($station->media_storage_location);
        $this->em->persist($station->recordings_storage_location);
        $this->em->persist($station->podcasts_storage_location);
        $this->em->persist($station);
        $this->em->flush();

        return $station;
    }

    private function removeStation(Station $station): void
    {
        if (!$this->em->isOpen()) {
            $this->em->open();
        }

        $this->em->createQuery('DELETE FROM App\Entity\AiDjSchedule s WHERE s.ai_dj IN (SELECT d FROM App\Entity\AiDj d WHERE d.station = :station)')
            ->setParameter('station', $station)
            ->execute();

        $this->em->createQuery('DELETE FROM App\Entity\AiDj d WHERE d.station = :station')
            ->setParameter('station', $station)
            ->execute();

        $this->em->remove($station);
        $this->em->remove($station->media_storage_location);
        $this->em->remove($station->recordings_storage_location);
        $this->em->remove($station->podcasts_storage_location);
        $this->em->flush();
        $this->em->clear();
    }
}
