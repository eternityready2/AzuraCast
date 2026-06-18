<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\ClockWheelSlotAlgorithms;
use App\Entity\Enums\ClockWheelSlotPoolModes;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationClockWheelTemplate;
use App\Entity\StationClockWheelTemplateSlot;
use App\Entity\StationMediaCategory;
use App\Entity\StationPlaylist;

/**
 * Shared slot replace/copy logic for wheels and templates (PR10).
 */
final class ClockWheelSlotWriter
{
    public function __construct(
        private readonly ReloadableEntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<mixed> $slotsData
     */
    public function replaceWheelSlots(
        StationClockWheel $wheel,
        array $slotsData,
        bool $breakTemplateInheritance = true,
    ): void {
        if ($breakTemplateInheritance && $wheel->template !== null) {
            $wheel->inherits_template_slots = false;
        }

        $wheel->slots->clear();
        $this->buildWheelSlots($wheel, $slotsData);
    }

    /**
     * @param array<mixed> $slotsData
     */
    public function replaceTemplateSlots(StationClockWheelTemplate $template, array $slotsData): void
    {
        $template->slots->clear();
        $normalized = $this->normalizeSlotPayload($slotsData);

        $order = 0;
        foreach ($normalized as $datum) {
            $slot = new StationClockWheelTemplateSlot($template);
            $slot->slot_order = $order++;
            $this->applyCommonSlotFields($template->station, $datum, $slot);
            $template->addSlot($slot);
            $this->em->persist($slot);
        }
    }

    public function copyTemplateSlotsToWheel(
        StationClockWheelTemplate $template,
        StationClockWheel $wheel,
    ): void {
        $wheel->slots->clear();

        foreach ($template->slots as $templateSlot) {
            $slot = new StationClockWheelSlot($wheel);
            $slot->slot_order = $templateSlot->slot_order;
            $slot->position_seconds = $templateSlot->position_seconds;
            $slot->type = $templateSlot->type;
            $slot->category = $templateSlot->category;
            $slot->algorithm = $templateSlot->algorithm;
            $slot->playlist = $templateSlot->playlist;
            $slot->pool_mode = $templateSlot->pool_mode;
            $slot->separation_override_enabled = $templateSlot->separation_override_enabled;
            $slot->separation_artist_minutes = $templateSlot->separation_artist_minutes;
            $slot->separation_title_minutes = $templateSlot->separation_title_minutes;
            $slot->duration_seconds = $templateSlot->duration_seconds;
            $wheel->addSlot($slot);
            $this->em->persist($slot);
        }
    }

    /**
     * @param array<mixed> $slotsData
     */
    private function buildWheelSlots(StationClockWheel $wheel, array $slotsData): void
    {
        $normalized = $this->normalizeSlotPayload($slotsData);
        $order = 0;

        foreach ($normalized as $datum) {
            $slot = new StationClockWheelSlot($wheel);
            $slot->slot_order = $order++;
            $this->applyCommonSlotFields($wheel->station, $datum, $slot);
            $wheel->addSlot($slot);
            $this->em->persist($slot);
        }
    }

    /**
     * @param array<string, mixed> $datum
     */
    private function applyCommonSlotFields(
        \App\Entity\Station $station,
        array $datum,
        StationClockWheelSlot|StationClockWheelTemplateSlot $slot,
    ): void {
        $posRaw = $datum['position_seconds'] ?? null;
        $slot->position_seconds = (is_numeric($posRaw) && (int)$posRaw >= 0)
            ? min(3599, (int)$posRaw)
            : 0;

        $typeRaw = (array_key_exists('type', $datum) && $datum['type'] !== null && $datum['type'] !== '')
            ? (string)$datum['type']
            : 'music';
        $slot->type = ClockWheelSlotTypes::tryFrom($typeRaw) ?? ClockWheelSlotTypes::Music;

        $categoryId = array_key_exists('category_id', $datum) && is_numeric($datum['category_id'])
            ? (int)$datum['category_id']
            : null;

        if ($categoryId !== null) {
            $category = $this->em->find(StationMediaCategory::class, $categoryId);
            $slot->category = ($category !== null && $category->station->id === $station->id)
                ? $category
                : null;
        } else {
            $slot->category = null;
        }

        $algoRaw = isset($datum['algorithm']) ? (string)$datum['algorithm'] : 'random';
        $slot->algorithm = ClockWheelSlotAlgorithms::tryFrom($algoRaw) ?? ClockWheelSlotAlgorithms::Random;

        $poolRaw = isset($datum['pool_mode']) ? (string)$datum['pool_mode'] : 'restrict_pool';
        $slot->pool_mode = ClockWheelSlotPoolModes::tryFrom($poolRaw) ?? ClockWheelSlotPoolModes::RestrictPool;

        $slot->separation_override_enabled = filter_var(
            $datum['separation_override_enabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $artistMinutes = $datum['separation_artist_minutes'] ?? null;
        $slot->separation_artist_minutes = is_numeric($artistMinutes) && (int)$artistMinutes > 0
            ? (int)$artistMinutes
            : null;

        $titleMinutes = $datum['separation_title_minutes'] ?? null;
        $slot->separation_title_minutes = is_numeric($titleMinutes) && (int)$titleMinutes > 0
            ? (int)$titleMinutes
            : null;

        if (!$slot->separation_override_enabled) {
            $slot->separation_artist_minutes = null;
            $slot->separation_title_minutes = null;
        }

        $playlistId = isset($datum['playlist_id']) && is_numeric($datum['playlist_id'])
            ? (int)$datum['playlist_id']
            : null;

        if ($playlistId !== null && $playlistId > 0) {
            $playlist = $this->em->find(StationPlaylist::class, $playlistId);
            $slot->playlist = ($playlist !== null && $playlist->station->id === $station->id)
                ? $playlist
                : null;
        } else {
            $slot->playlist = null;
        }

        $durRaw = $datum['duration_seconds'] ?? null;
        $slot->duration_seconds = (is_numeric($durRaw) && (int)$durRaw > 0)
            ? (int)$durRaw
            : null;
    }

    /**
     * @param array<mixed> $slotsData
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSlotPayload(array $slotsData): array
    {
        $normalized = [];
        foreach ($slotsData as $datum) {
            if (is_array($datum)) {
                $normalized[] = $datum;
            }
        }

        usort(
            $normalized,
            static function (array $a, array $b): int {
                $posA = isset($a['position_seconds']) && is_numeric($a['position_seconds'])
                    ? (int)$a['position_seconds']
                    : 0;
                $posB = isset($b['position_seconds']) && is_numeric($b['position_seconds'])
                    ? (int)$b['position_seconds']
                    : 0;

                return $posA <=> $posB;
            }
        );

        return $normalized;
    }
}
