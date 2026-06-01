<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationClockDaypart;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelTemplate;
use App\Entity\StationClockWheelTemplateSlot;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Radio\AutoDJ\ClockWheel\ClockWheelInheritanceService;
use App\Radio\AutoDJ\ClockWheel\ClockWheelSeparationSettings;
use App\Radio\AutoDJ\ClockWheel\ClockWheelSlotWriter;
use App\Tests\Module;
use Codeception\Test\Unit;

final class ClockWheelInheritanceServiceTest extends Unit
{
    private ClockWheelInheritanceService $service;

    private Module $testsModule;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
        $this->service = $testsModule->container->get(ClockWheelInheritanceService::class);
    }

    public function testSyncDaypartCreatesHourlyWheels(): void
    {
        $em = $this->testsModule->em;
        $station = $this->persistStation($em);

        $template = new StationClockWheelTemplate($station);
        $template->name = 'Drive Template';

        $templateSlot = new StationClockWheelTemplateSlot($template);
        $templateSlot->type = ClockWheelSlotTypes::Music;
        $templateSlot->position_seconds = 0;
        $template->addSlot($templateSlot);

        $daypart = new StationClockDaypart($station, $template);
        $daypart->name = 'Morning Drive';
        $daypart->start_hour = 6;
        $daypart->end_hour = 8;

        $em->persist($template);
        $em->persist($templateSlot);
        $em->persist($daypart);
        $em->flush();

        try {
            $wheels = $this->service->syncDaypart($daypart);

            self::assertCount(3, $wheels);
            self::assertSame(['Morning Drive 06:00', 'Morning Drive 07:00', 'Morning Drive 08:00'], array_map(
                static fn (StationClockWheel $w) => $w->name,
                $wheels
            ));

            foreach ($wheels as $wheel) {
                self::assertTrue($wheel->inherits_template_slots);
                self::assertSame($template->id, $wheel->template_id);
                self::assertCount(1, $wheel->slots);
            }
        } finally {
            $this->cleanupStation($em, $station);
        }
    }

    public function testTemplateSlotUpdatePropagatesToInheritingWheel(): void
    {
        $em = $this->testsModule->em;
        $station = $this->persistStation($em);

        $template = new StationClockWheelTemplate($station);
        $template->name = 'Shared';

        $wheel = new StationClockWheel($station);
        $wheel->name = 'Instance';
        $wheel->template = $template;
        $wheel->inherits_template_slots = true;

        $em->persist($template);
        $em->persist($wheel);
        $em->flush();

        try {
            $slotWriter = $this->testsModule->container->get(ClockWheelSlotWriter::class);
            $slotWriter->replaceTemplateSlots($template, [
                [
                    'type' => 'id',
                    'position_seconds' => 120,
                    'algorithm' => 'random',
                ],
            ]);
            $em->flush();

            $this->service->propagateTemplateToWheels($template);
            $em->refresh($wheel);

            self::assertCount(1, $wheel->slots);
            $slot = $wheel->slots->getValues()[0];
            self::assertSame(120, $slot->position_seconds);
            self::assertSame(ClockWheelSlotTypes::Id, $slot->type);
        } finally {
            $this->cleanupStation($em, $station);
        }
    }

    public function testSyncDaypartWheelsUseDaypartSeparationOverride(): void
    {
        $em = $this->testsModule->em;
        $station = $this->persistStation($em);

        $template = new StationClockWheelTemplate($station);
        $template->name = 'Drive Template';

        $daypart = new StationClockDaypart($station, $template);
        $daypart->name = 'Drive';
        $daypart->start_hour = 9;
        $daypart->end_hour = 9;
        $daypart->separation_override_enabled = true;
        $daypart->separation_enabled = true;
        $daypart->separation_artist_minutes = 25;
        $daypart->separation_title_minutes = 40;
        $daypart->burn_rate_max_plays_24h = 2;

        $em->persist($template);
        $em->persist($daypart);
        $em->flush();

        try {
            $wheels = $this->service->syncDaypart($daypart);

            self::assertCount(1, $wheels);
            $wheel = $wheels[0];
            self::assertSame($daypart->id, $wheel->daypart_id);

            $settings = ClockWheelSeparationSettings::resolveForWheel($wheel);
            self::assertTrue($settings->enabled);
            self::assertSame(25, $settings->artistMinutes);
            self::assertSame(40, $settings->titleMinutes);
            self::assertSame(2, $settings->burnRateMaxPlays24h);
        } finally {
            $this->cleanupStation($em, $station);
        }
    }

    public function testManualWheelSlotEditBreaksInheritance(): void
    {
        $em = $this->testsModule->em;
        $station = $this->persistStation($em);

        $template = new StationClockWheelTemplate($station);
        $template->name = 'Shared';

        $wheel = new StationClockWheel($station);
        $wheel->template = $template;
        $wheel->inherits_template_slots = true;

        $em->persist($template);
        $em->persist($wheel);
        $em->flush();

        try {
            $slotWriter = $this->testsModule->container->get(ClockWheelSlotWriter::class);
            $slotWriter->replaceWheelSlots($wheel, [
                ['type' => 'music', 'position_seconds' => 0, 'algorithm' => 'random'],
            ], true);
            $em->flush();

            self::assertFalse($wheel->inherits_template_slots);
        } finally {
            $this->cleanupStation($em, $station);
        }
    }

    private function persistStation(\App\Doctrine\ReloadableEntityManagerInterface $em): Station
    {
        $station = new Station();
        $station->name = 'Inheritance Test';
        $station->short_name = 'inherit_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $em->persist($station->media_storage_location);
        $em->persist($station->recordings_storage_location);
        $em->persist($station->podcasts_storage_location);
        $em->persist($station);
        $em->flush();

        return $station;
    }

    private function cleanupStation(\App\Doctrine\ReloadableEntityManagerInterface $em, Station $station): void
    {
        if (!$em->isOpen()) {
            $em->open();
        }

        $em->createQuery('DELETE FROM App\Entity\StationClockWheel w WHERE w.station = :station')
            ->setParameter('station', $station)
            ->execute();
        $em->createQuery('DELETE FROM App\Entity\StationClockDaypart d WHERE d.station = :station')
            ->setParameter('station', $station)
            ->execute();
        $em->createQuery('DELETE FROM App\Entity\StationClockWheelTemplate t WHERE t.station = :station')
            ->setParameter('station', $station)
            ->execute();
        $em->remove($station);
        $em->remove($station->media_storage_location);
        $em->remove($station->recordings_storage_location);
        $em->remove($station->podcasts_storage_location);
        $em->flush();
        $em->clear();
    }
}
