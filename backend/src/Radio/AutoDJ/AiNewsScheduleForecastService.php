<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Song;
use App\Entity\Station;
use App\Entity\StationQueue;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Read-only projection of the AI News times already configured for Liquidsoap.
 *
 * This service never queues or schedules a bulletin. It only mirrors the existing
 * AI News configuration so reporting surfaces can show the bulletin before the
 * Liquidsoap cron callback actually pushes it into its runtime request queue.
 */
final class AiNewsScheduleForecastService
{
    /** @return list<CarbonImmutable> */
    public function getForecast(
        Station $station,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd,
    ): array {
        if ($rangeEnd <= $rangeStart) {
            return [];
        }

        $config = $station->backend_config;
        if (!$config->ai_news_enabled) {
            return [];
        }

        $minutes = [];
        if ($config->ai_news_top_of_hour) {
            $minutes[] = 59;
        }
        if ($config->ai_news_bottom_of_hour) {
            $minutes[] = 29;
        }
        if ([] === $minutes) {
            $minutes[] = 59;
        }

        $timezone = $station->getTimezoneObject();
        $localStart = CarbonImmutable::instance($rangeStart)->setTimezone($timezone);
        $localEnd = CarbonImmutable::instance($rangeEnd)->setTimezone($timezone);
        $cursor = $localStart->startOfHour();
        $items = [];

        while ($cursor < $localEnd) {
            foreach ($minutes as $minute) {
                $candidate = $cursor->addMinutes($minute);
                if ($candidate < $localStart || $candidate >= $localEnd) {
                    continue;
                }
                if (!$this->isActiveDay($candidate, $config->ai_news_active_days)) {
                    continue;
                }
                if (!$this->isWithinActiveHours($candidate, $config->ai_news_active_hours)) {
                    continue;
                }

                $items[] = $candidate->utc();
            }

            $cursor = $cursor->addHour();
        }

        usort(
            $items,
            static fn(CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp(),
        );

        return $items;
    }

    public function toQueueRow(Station $station, DateTimeImmutable $playedAt): StationQueue
    {
        $song = Song::createFromArray([
            'title' => 'News Hour',
            'artist' => 'Eternity Ready',
            'album' => 'News Bulletin',
            'text' => 'Eternity Ready - News Hour',
        ]);

        $row = new StationQueue($station, $song);
        $row->timestamp_cued = $playedAt;
        $row->timestamp_played = $playedAt;
        $row->duration = 0.0;
        $row->sent_to_autodj = true;
        $row->is_visible = true;

        return $row;
    }

    /** @param int[] $activeDays */
    private function isActiveDay(CarbonImmutable $candidate, array $activeDays): bool
    {
        if ([] === $activeDays) {
            return true;
        }

        return in_array((int)$candidate->format('N'), $activeDays, true);
    }

    private function isWithinActiveHours(CarbonImmutable $candidate, ?string $activeHours): bool
    {
        if (null === $activeHours || '' === trim($activeHours)) {
            return true;
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', trim($activeHours), $matches)) {
            return true;
        }

        $start = ((int)$matches[1] * 60) + (int)$matches[2];
        $end = ((int)$matches[3] * 60) + (int)$matches[4];
        $current = ((int)$candidate->format('G') * 60) + (int)$candidate->format('i');

        if ($start <= $end) {
            return $current >= $start && $current < $end;
        }

        return $current >= $start || $current < $end;
    }
}
