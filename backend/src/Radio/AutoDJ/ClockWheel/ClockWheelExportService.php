<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Api\ClockWheel\ClockWheelExport;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;

final class ClockWheelExportService
{
    public function __construct(
        private readonly ReloadableEntityManagerInterface $em,
        private readonly ClockWheelSlotWriter $slotWriter,
    ) {
    }

    public function exportWheel(StationClockWheel $wheel): ClockWheelExport
    {
        $export = new ClockWheelExport();
        $export->name = $wheel->name;
        $export->color = $wheel->color ?? '#e87722';
        $export->fill_strategy = $wheel->fill_strategy->value;
        $export->separation_enabled = $wheel->separation_enabled;
        $export->separation_artist_minutes = $wheel->separation_artist_minutes;
        $export->separation_title_minutes = $wheel->separation_title_minutes;
        $export->burn_rate_max_plays_24h = $wheel->burn_rate_max_plays_24h;

        $slots = $wheel->slots->toArray();
        usort(
            $slots,
            static fn (StationClockWheelSlot $a, StationClockWheelSlot $b): int =>
                $a->position_seconds <=> $b->position_seconds
                ?: $a->slot_order <=> $b->slot_order
        );

        foreach ($slots as $slot) {
            $export->slots[] = [
                'type' => $slot->type?->value ?? 'music',
                'algorithm' => $slot->algorithm->value,
                'position_seconds' => $slot->position_seconds,
                'duration_seconds' => $slot->duration_seconds,
                'category_id' => $slot->category_id,
                'playlist_id' => $slot->playlist_id,
                'pool_mode' => $slot->pool_mode->value,
                'separation_override_enabled' => $slot->separation_override_enabled,
                'separation_artist_minutes' => $slot->separation_artist_minutes,
                'separation_title_minutes' => $slot->separation_title_minutes,
                'is_hard_anchor' => $slot->is_hard_anchor,
                'research_score' => $slot->research_score,
                'sound_code' => $slot->sound_code,
            ];
        }

        return $export;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function importWheel(Station $station, array $payload): StationClockWheel
    {
        $wheel = new StationClockWheel($station);
        $wheel->name = isset($payload['name']) ? trim((string)$payload['name']) : 'Imported Wheel';
        if ($wheel->name === '') {
            $wheel->name = 'Imported Wheel';
        }

        $wheel->color = isset($payload['color']) ? (string)$payload['color'] : '#e87722';
        $wheel->is_active = true;

        $fillRaw = isset($payload['fill_strategy']) ? (string)$payload['fill_strategy'] : 'conservative';
        $fillEnum = \App\Entity\Enums\ClockWheelFillStrategy::tryFrom($fillRaw);
        if ($fillEnum !== null) {
            $wheel->fill_strategy = $fillEnum;
        }

        $wheel->separation_enabled = filter_var($payload['separation_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $wheel->separation_artist_minutes = is_numeric($payload['separation_artist_minutes'] ?? null)
            ? (int)$payload['separation_artist_minutes']
            : 45;
        $wheel->separation_title_minutes = is_numeric($payload['separation_title_minutes'] ?? null)
            ? (int)$payload['separation_title_minutes']
            : 90;
        $wheel->burn_rate_max_plays_24h = is_numeric($payload['burn_rate_max_plays_24h'] ?? null)
            ? (int)$payload['burn_rate_max_plays_24h']
            : null;

        $this->em->persist($wheel);
        $this->em->flush();

        $slots = is_array($payload['slots'] ?? null) ? $payload['slots'] : [];
        $this->slotWriter->replaceWheelSlots($wheel, $slots, false);
        $this->em->flush();

        return $wheel;
    }
}
