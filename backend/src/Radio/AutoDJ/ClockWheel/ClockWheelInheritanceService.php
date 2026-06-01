<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\StationClockDaypart;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelTemplate;

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
        $this->slotWriter->replaceTemplateSlots($template, $slotsData);
        $this->propagateTemplateToWheels($template);
    }

    public function propagateTemplateToWheels(StationClockWheelTemplate $template): void
    {
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

            if ($wheel->inherits_template_slots) {
                $this->slotWriter->copyTemplateSlotsToWheel($template, $wheel);
            }

            $this->em->persist($wheel);
            $synced[] = $wheel;
        }

        $this->removeOrphanDaypartWheels($daypart, $targetHours);
        $this->em->flush();

        return $synced;
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
