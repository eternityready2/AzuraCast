<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\AiDj;
use App\Entity\Repository\AiDjRepository;
use App\Entity\Station;
use App\Tests\Module;
use Codeception\Test\Unit;

final class AiDjRepositoryTest extends Unit
{
    private AiDjRepository $repository;

    private ReloadableEntityManagerInterface $em;

    private Station $station;

    protected function _inject(Module $testsModule): void
    {
        $this->repository = $testsModule->container->get(AiDjRepository::class);
        $this->em = $testsModule->em;
    }

    protected function _before(): void
    {
        if (!$this->em->isOpen()) {
            $this->em->open();
        }

        $this->station = new Station();
        $this->station->name = 'AI DJ Repo Test';
        $this->station->short_name = 'ai_dj_repo';
        $this->station->timezone = 'UTC';
    }

    protected function _after(): void
    {
        if (!$this->em->isOpen()) {
            $this->em->open();
        }
    }

    public function testFindByStationReturnsEmptyArrayWhenNoDjs(): void
    {
        $station = $this->persistStation();

        try {
            $result = $this->repository->findByStation($station->getId());

            self::assertSame([], $result);
        } finally {
            $this->removeStation($station);
        }
    }

    public function testFindByStationReturnsDjsForStation(): void
    {
        $station = $this->persistStation();

        $dj1 = new AiDj($station);
        $dj1->setName('DJ One');
        $dj1->setEnabled(true);

        $dj2 = new AiDj($station);
        $dj2->setName('DJ Two');
        $dj2->setEnabled(false);

        $this->em->persist($dj1);
        $this->em->persist($dj2);
        $this->em->flush();

        try {
            $result = $this->repository->findByStation($station->getId());

            self::assertCount(2, $result);
            self::assertSame('DJ One', $result[0]->getName());
            self::assertSame('DJ Two', $result[1]->getName());
        } finally {
            $this->removeStation($station);
        }
    }

    public function testFindByStationDoesNotReturnOtherStationDjs(): void
    {
        $station1 = $this->persistStation();
        $station2 = $this->persistStation();

        $dj1 = new AiDj($station1);
        $dj1->setName('Station 1 DJ');
        $dj1->setEnabled(true);

        $dj2 = new AiDj($station2);
        $dj2->setName('Station 2 DJ');
        $dj2->setEnabled(true);

        $this->em->persist($dj1);
        $this->em->persist($dj2);
        $this->em->flush();

        try {
            $result = $this->repository->findByStation($station1->getId());

            self::assertCount(1, $result);
            self::assertSame('Station 1 DJ', $result[0]->getName());
        } finally {
            $this->removeStation($station1);
            $this->removeStation($station2);
        }
    }

    private function persistStation(): Station
    {
        $station = new Station();
        $station->name = 'AI DJ Repo DB Test';
        $station->short_name = 'ai_dj_repo_' . substr(uniqid('', true), -8);
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
