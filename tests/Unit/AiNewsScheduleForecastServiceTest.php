<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Radio\AutoDJ\AiNewsScheduleForecastService;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

final class AiNewsScheduleForecastServiceTest extends Unit
{
    public function testForecastMirrorsConfiguredTopAndBottomOfHourTimes(): void
    {
        $station = new Station();
        $station->timezone = 'America/Chicago';

        $config = $station->backend_config;
        $config->ai_news_enabled = true;
        $config->ai_news_top_of_hour = true;
        $config->ai_news_bottom_of_hour = true;
        $config->ai_news_active_days = [3];
        $config->ai_news_active_hours = '12:00-18:00';
        $station->backend_config = $config;

        $timezone = new DateTimeZone('America/Chicago');
        $start = new DateTimeImmutable('2026-09-16 13:01:00', $timezone);
        $end = new DateTimeImmutable('2026-09-16 14:00:00', $timezone);

        $forecast = (new AiNewsScheduleForecastService())->getForecast($station, $start, $end);

        self::assertSame(
            ['2026-09-16 13:29', '2026-09-16 13:59'],
            array_map(
                static fn($time): string => $time->setTimezone($timezone)->format('Y-m-d H:i'),
                $forecast,
            ),
        );
    }

    public function testForecastDoesNotInventNewsOutsideConfiguredWindow(): void
    {
        $station = new Station();
        $station->timezone = 'America/Chicago';

        $config = $station->backend_config;
        $config->ai_news_enabled = true;
        $config->ai_news_top_of_hour = true;
        $config->ai_news_bottom_of_hour = false;
        $config->ai_news_active_days = [3];
        $config->ai_news_active_hours = '12:00-13:30';
        $station->backend_config = $config;

        $timezone = new DateTimeZone('America/Chicago');
        $start = new DateTimeImmutable('2026-09-16 13:31:00', $timezone);
        $end = new DateTimeImmutable('2026-09-16 14:00:00', $timezone);

        self::assertSame(
            [],
            (new AiNewsScheduleForecastService())->getForecast($station, $start, $end),
        );
    }
}
