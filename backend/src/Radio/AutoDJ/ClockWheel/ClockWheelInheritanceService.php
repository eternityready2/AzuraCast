<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\ClockWheelScheduleMode;
use App\Entity\StationClockDaypart;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelTemplate;
use App\Entity\StationSchedule;

/**
 * Propagates template slots to wheels and materializes daypart hour instances (PR10).
 */
final class ClockWheelInheritanceService
{
    public function __construct(
        private readonly ReloadableEntityManagerInterface $em,
        private readonly ClockWheelSlotWriter $slotWriter,
    ) {
    }

    /**
     * @param array<mixed> $slotsData
     */
    public function saveTemplateSlotsAndPropagate(
        StationClockWheelTemplate $template,
        array $slotsData,
    ): void {
        $this->ensureTemplatePersisted($template);
        $this->slotWriter->replaceTemplateSlots($template, $slotsData);
        $this->propagateTemplateToWheels($template);
    }

    public function propagateTemplateToWheels(StationClockWheelTemplate $template): void
    {
        $this->ensureTemplatePersisted($template);

        $wheels = $this->em->createQuery(
            <<<'DQL'
                SELECT w
                FROM App\Entity\StationClockWheel w
                WHERE w.template = :template
                AND w.inherits_template_slots = 1
            DQL
        )->setParameter('template', $template)
            ->execute();

        foreach ($wheels as $wheel) {
            if (!$wheel instanceof StationClockWheel) {
                continue;
            }

            $this->slotWriter->copyTemplateSlotsToWheel($template, $wheel);
        }

        $this->em->flush();
    }

    /**
     * Create or update hourly clock wheel instances for a daypart.
     *
     * @return StationClockWheel[]
     */
    public function syncDaypart(StationClockDaypart $daypart): array
    {
        $station = $daypart->station;
        $template = $daypart->template;
        $targetHours = $this->hoursInRange($daypart->start_hour, $daypart->end_hour);
        $synced = [];

        foreach ($targetHours as $hour) {
            $wheel = $this->findOrCreateDaypartWheel($daypart, $hour);
            $this->applyDaypartMetadata($daypart, $template, $wheel, $hour);
            $this->em->persist($wheel);
            $this->ensureWheelPersisted($wheel);

            if ($wheel->inherits_template_slots) {
                $this->slotWriter->copyTemplateSlotsToWheel($template, $wheel);
            }

            $this->scheduleDaypartWheel($daypart, $wheel, $hour);

            $synced[] = $wheel;
        }

        $this->removeOrphanDaypartWheels($daypart, $targetHours);
        $this->em->flush();

        foreach ($synced as $wheel) {
            $wheel->syncReadOnlyForeignKeys();
            foreach ($wheel->slots as $slot) {
                $slot->syncReadOnlyForeignKeys();
            }
        }

        return $synced;
    }

    /**
     * A daypart's hourly wheel airs in its own hour on the daypart's days.
     *
     * Generated wheels used to get no schedule row, so a daypart never reached
     * the air (or the linear log) unless each wheel was scheduled by hand. The
     * daypart owns this row: one per wheel, kept in step on every save.
     * Scheduled shows still win over a wheel during their own window.
     */
    private function scheduleDaypartWheel(StationClockDaypart $daypart, StationClockWheel $wheel, int $hour): void
    {
        $schedule = null;
        foreach ($wheel->schedule_items as $item) {
            if (null === $schedule) {
                $schedule = $item;
            } else {
                $this->em->remove($item);
            }
        }

        $schedule ??= new StationSchedule($wheel);
        $schedule->start_time = $hour * 100;
        $schedule->end_time = (($hour + 1) % 24) * 100;
        $schedule->days = $daypart->days;
        $schedule->clock_wheel_mode ??= ClockWheelScheduleMode::Flexible;
        $this->em->persist($schedule);
    }

    /**
     * Template rows and DQL :template binding require a persisted identifier.
     */
    private function ensureTemplatePersisted(StationClockWheelTemplate $template): void
    {
        if (!$this->em->contains($template)) {
            $this->em->persist($template);
        }

        if ($this->em->getUnitOfWork()->isScheduledForInsert($template)) {
            $this->em->flush();
        }
    }

    /**
     * Wheel slots require a persisted parent row (clock_wheel_id FK).
     */
    private function ensureWheelPersisted(StationClockWheel $wheel): void
    {
        if ($this->em->getUnitOfWork()->isScheduledForInsert($wheel)) {
            $this->em->flush();
        }
    }

    private function findOrCreateDaypartWheel(StationClockDaypart $daypart, int $hour): StationClockWheel
    {
        $existing = $this->em->getRepository(StationClockWheel::class)->findOneBy([
            'daypart' => $daypart,
            'hour_of_day' => $hour,
        ]);

        if ($existing instanceof StationClockWheel) {
            return $existing;
        }

        return new StationClockWheel($daypart->station);
    }

    private function applyDaypartMetadata(
        StationClockDaypart $daypart,
        StationClockWheelTemplate $template,
        StationClockWheel $wheel,
        int $hour,
    ): void {
        $wheel->name = sprintf('%s %02d:00', $daypart->name, $hour);
        $wheel->color = $daypart->color ?? $template->color;
        $wheel->template = $template;
        $wheel->daypart = $daypart;
        $wheel->hour_of_day = $hour;
        $wheel->inherits_template_slots = true;
        $wheel->is_active = $daypart->is_active;
    }

    /**
     * @param int[] $targetHours
     */
    private function removeOrphanDaypartWheels(StationClockDaypart $daypart, array $targetHours): void
    {
        $wheels = $this->em->createQuery(
            <<<'DQL'
                SELECT w
                FROM App\Entity\StationClockWheel w
                WHERE w.daypart = :daypart
            DQL
        )->setParameter('daypart', $daypart)
            ->execute();

        foreach ($wheels as $wheel) {
            if (!$wheel instanceof StationClockWheel) {
                continue;
            }

            if ($wheel->hour_of_day === null || !in_array($wheel->hour_of_day, $targetHours, true)) {
                $this->em->remove($wheel);
            }
        }
    }

    /**
     * @return int[]
     */
    private function hoursInRange(int $startHour, int $endHour): array
    {
        $hours = [];
        $hour = $startHour;

        while (true) {
            $hours[] = $hour;
            if ($hour === $endHour) {
                break;
            }

            $hour = ($hour + 1) % 24;

            if (count($hours) > 24) {
                break;
            }
        }

        return $hours;
    }
}
