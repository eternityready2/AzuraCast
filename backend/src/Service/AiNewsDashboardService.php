<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\Api\Stations\AiNews\BulletinGetAction;
use App\Entity\Station;
use DateTimeImmutable;

/**
 * Builds the AI News "dashboard" payload (latest bulletin info, next scheduled
 * run, audio availability) shared by the settings GET endpoint and the manual
 * test-generation endpoint.
 */
final class AiNewsDashboardService
{
    public function buildDashboardPayload(Station $station): array
    {
        $backendConfig = $station->backend_config;
        $bulletinPath = $station->getRadioTempDir() . '/' . AiNewsGenerator::OUTPUT_FILENAME;
        $fileExists = file_exists($bulletinPath);
        $latestBulletin = is_array($station->ai_news_latest_bulletin)
            ? $station->ai_news_latest_bulletin
            : [];

        $fileInfo = null;
        if ($fileExists) {
            $fileInfo = [
                'exists' => true,
                'size' => filesize($bulletinPath),
                'modified_at' => gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($bulletinPath)),
            ];
        }

        return [
            'latest_bulletin' => [
                'generated_at' => $latestBulletin['generated_at'] ?? null,
                'story_count' => $latestBulletin['story_count'] ?? null,
                'source_urls' => $latestBulletin['source_urls'] ?? [],
                'source_results' => $latestBulletin['source_results'] ?? [],
                'elapsed_seconds' => $latestBulletin['elapsed_seconds'] ?? null,
                'output_filename' => $latestBulletin['output_filename'] ?? null,
                'headline_preview' => $latestBulletin['headline_preview'] ?? [],
            ],
            'file_info' => $fileInfo,
            'next_bulletin_time' => $this->computeNextBulletinTime(
                $backendConfig->ai_news_active_hours,
                $backendConfig->ai_news_active_days,
                $station,
                $backendConfig->ai_news_top_of_hour,
                $backendConfig->ai_news_bottom_of_hour
            ),
            'current_time_station' => (new DateTimeImmutable('now', $station->getTimezoneObject()))->format(DATE_ATOM),
            'tts_engine' => 'piper',
            'audio_available' => $fileExists,
            'bulletin_url' => BulletinGetAction::getBulletinUrl($station),
        ];
    }

    private function computeNextBulletinTime(
        ?string $activeHours,
        array $activeDays,
        Station $station,
        bool $topOfHour,
        bool $bottomOfHour
    ): ?string {
        if (!$topOfHour && !$bottomOfHour) {
            return null;
        }

        $activeDays = $this->normalizeActiveDays($activeDays);
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $scheduleMinutes = $this->getScheduleMinutes($topOfHour, $bottomOfHour);

        $activeHours = trim($activeHours ?? '');
        if ('' === $activeHours) {
            return $this->findNextScheduledTime($now, $scheduleMinutes, $activeDays)?->format(DATE_ATOM);
        }

        // HH:MM-HH:MM format (preferred, UI default)
        if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $activeHours, $matches)) {
            $startMinutes = ((int) $matches[1]) * 60 + (int) $matches[2];
            $endMinutes = ((int) $matches[3]) * 60 + (int) $matches[4];

            return $this->findNextScheduledTimeInWindow($now, $scheduleMinutes, $startMinutes, $endMinutes, $activeDays)
                ?->format(DATE_ATOM);
        }

        // H-H format (legacy)
        if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $activeHours, $matches)) {
            $startMinutes = ((int) $matches[1]) * 60;
            $endMinutes = ((int) $matches[2]) * 60;

            return $this->findNextScheduledTimeInWindow($now, $scheduleMinutes, $startMinutes, $endMinutes, $activeDays)
                ?->format(DATE_ATOM);
        }

        return $this->findNextScheduledTime($now, $scheduleMinutes, $activeDays)?->format(DATE_ATOM);
    }

    /** @return int[] */
    private function getScheduleMinutes(bool $topOfHour, bool $bottomOfHour): array
    {
        $scheduleMinutes = [];

        if ($topOfHour) {
            $scheduleMinutes[] = 0;
        }

        if ($bottomOfHour) {
            $scheduleMinutes[] = 30;
        }

        sort($scheduleMinutes);

        return $scheduleMinutes;
    }

    /** @return int[] */
    private function normalizeActiveDays(array $activeDays): array
    {
        $normalizedDays = array_map(
            static fn(mixed $day): int => (int) $day,
            $activeDays
        );
        $normalizedDays = array_values(array_unique(array_filter(
            $normalizedDays,
            static fn(int $day): bool => $day >= 1 && $day <= 7
        )));
        sort($normalizedDays);

        return $normalizedDays;
    }

    private function isWeekdayAllowed(DateTimeImmutable $candidate, array $activeDays): bool
    {
        if ([] === $activeDays) {
            return true;
        }

        return in_array((int) $candidate->format('N'), $activeDays, true);
    }

    private function findNextScheduledTime(
        DateTimeImmutable $now,
        array $scheduleMinutes,
        array $activeDays
    ): ?DateTimeImmutable {
        for ($hourOffset = 0; $hourOffset <= 168; $hourOffset++) {
            $candidateHour = $now->modify(sprintf('+%d hour', $hourOffset));

            foreach ($scheduleMinutes as $minute) {
                $candidate = $candidateHour->setTime((int) $candidateHour->format('G'), $minute);
                if (!$this->isWeekdayAllowed($candidate, $activeDays)) {
                    continue;
                }

                if ($candidate > $now) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function findNextScheduledTimeInWindow(
        DateTimeImmutable $now,
        array $scheduleMinutes,
        int $startMinutes,
        int $endMinutes,
        array $activeDays
    ): ?DateTimeImmutable {
        for ($hourOffset = 0; $hourOffset <= 168; $hourOffset++) {
            $candidateHour = $now->modify(sprintf('+%d hour', $hourOffset));
            $hour = (int) $candidateHour->format('G');

            foreach ($scheduleMinutes as $minute) {
                $candidate = $candidateHour->setTime($hour, $minute);
                $candidateMinutes = $hour * 60 + $minute;

                if (!$this->isWeekdayAllowed($candidate, $activeDays)) {
                    continue;
                }

                if (!$this->isMinuteWithinWindow($candidateMinutes, $startMinutes, $endMinutes)) {
                    continue;
                }

                if ($candidate > $now) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function isMinuteWithinWindow(int $candidateMinutes, int $startMinutes, int $endMinutes): bool
    {
        if ($startMinutes <= $endMinutes) {
            return $candidateMinutes >= $startMinutes && $candidateMinutes < $endMinutes;
        }

        return $candidateMinutes >= $startMinutes || $candidateMinutes < $endMinutes;
    }
}
