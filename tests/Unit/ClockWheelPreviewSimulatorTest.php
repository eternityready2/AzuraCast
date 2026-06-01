<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\ClockWheelFillStrategy;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Radio\AutoDJ\ClockWheel\ClockWheelPreviewSimulator;
use App\Tests\Module;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

final class ClockWheelPreviewSimulatorTest extends Unit
{
    private Module $testsModule;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
    }

    public function testSimulateHourWithNoSlotsAddsWarning(): void
    {
        $station = $this->persistStation($this->testsModule->em);
        $wheel = new StationClockWheel($station);
        $wheel->name = 'Empty Wheel';

        $simulator = new ClockWheelPreviewSimulator($this->createMock(EntityManagerInterface::class));
        $preview = $simulator->simulateHour(
            $wheel,
            new DateTimeImmutable('2026-05-30 14:00:00', new DateTimeZone('UTC'))
        );

        self::assertSame([], $preview->items);
        self::assertNotEmpty($preview->warnings);

        $this->removeStation($this->testsModule->em, $station);
    }

    public function testConservativeFillDefersWhenWindowTooSmall(): void
    {
        $station = $this->persistStation($this->testsModule->em);
        $wheel = new StationClockWheel($station);
        $wheel->name = 'Tight Wheel';
        $wheel->fill_strategy = ClockWheelFillStrategy::Conservative;

        $slotA = new StationClockWheelSlot($wheel);
        $slotA->type = ClockWheelSlotTypes::Music;
        $slotA->position_seconds = 0;

        $slotB = new StationClockWheelSlot($wheel);
        $slotB->type = ClockWheelSlotTypes::Music;
        $slotB->position_seconds = 20;

        $wheel->addSlot($slotA);
        $wheel->addSlot($slotB);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturnCallback(
            static function () {
                $query = new class {
                    public function setParameters(): self
                    {
                        return $this;
                    }

                    public function setMaxResults(): self
                    {
                        return $this;
                    }

                    public function getResult(): array
                    {
                        return [];
                    }
                };

                return $query;
            }
        );

        $simulator = new ClockWheelPreviewSimulator($em);
        $preview = $simulator->simulateHour(
            $wheel,
            new DateTimeImmutable('2026-05-30 14:00:00', new DateTimeZone('UTC'))
        );

        self::assertCount(1, $preview->items);
        self::assertStringContainsString('defer', strtolower($preview->items[0]->warnings[0] ?? ''));

        $this->removeStation($this->testsModule->em, $station);
    }

    private function persistStation(\App\Doctrine\ReloadableEntityManagerInterface $em): Station
    {
        $station = new Station();
        $station->name = 'Preview Sim Test';
        $station->short_name = 'prev_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $em->persist($station->media_storage_location);
        $em->persist($station->recordings_storage_location);
        $em->persist($station->podcasts_storage_location);
        $em->persist($station);
        $em->flush();

        return $station;
    }

    private function removeStation(\App\Doctrine\ReloadableEntityManagerInterface $em, Station $station): void
    {
        if (!$em->isOpen()) {
            $em->open();
        }

        $em->remove($station);
        $em->remove($station->media_storage_location);
        $em->remove($station->recordings_storage_location);
        $em->remove($station->podcasts_storage_location);
        $em->flush();
    }
}
