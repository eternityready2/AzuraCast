<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationClockDaypart;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelTemplate;
use App\Radio\AutoDJ\ClockWheel\ClockWheelSeparationSettings;
use Codeception\Test\Unit;

final class ClockWheelSeparationSettingsTest extends Unit
{
    public function testUsesWheelSettingsWhenDaypartOverrideDisabled(): void
    {
        $station = new Station();
        $template = new StationClockWheelTemplate($station);
        $daypart = new StationClockDaypart($station, $template);
        $daypart->separation_override_enabled = true;
        $daypart->separation_enabled = true;
        $daypart->separation_artist_minutes = 10;
        $daypart->separation_override_enabled = false;

        $wheel = new StationClockWheel($station);
        $wheel->daypart = $daypart;
        $wheel->separation_enabled = true;
        $wheel->separation_artist_minutes = 55;
        $wheel->separation_title_minutes = 100;
        $wheel->burn_rate_max_plays_24h = 4;

        $settings = ClockWheelSeparationSettings::resolveForWheel($wheel);

        self::assertTrue($settings->enabled);
        self::assertSame(55, $settings->artistMinutes);
        self::assertSame(100, $settings->titleMinutes);
        self::assertSame(4, $settings->burnRateMaxPlays24h);
    }

    public function testUsesDaypartSettingsWhenOverrideEnabled(): void
    {
        $station = new Station();
        $template = new StationClockWheelTemplate($station);
        $daypart = new StationClockDaypart($station, $template);
        $daypart->separation_override_enabled = true;
        $daypart->separation_enabled = true;
        $daypart->separation_artist_minutes = 20;
        $daypart->separation_title_minutes = 30;
        $daypart->burn_rate_max_plays_24h = 2;

        $wheel = new StationClockWheel($station);
        $wheel->daypart = $daypart;
        $wheel->separation_enabled = false;
        $wheel->separation_artist_minutes = 45;
        $wheel->separation_title_minutes = 90;

        $settings = ClockWheelSeparationSettings::resolveForWheel($wheel);

        self::assertTrue($settings->enabled);
        self::assertSame(20, $settings->artistMinutes);
        self::assertSame(30, $settings->titleMinutes);
        self::assertSame(2, $settings->burnRateMaxPlays24h);
    }

    public function testDaypartOverrideCanForceSeparationOff(): void
    {
        $station = new Station();
        $template = new StationClockWheelTemplate($station);
        $daypart = new StationClockDaypart($station, $template);
        $daypart->separation_override_enabled = true;
        $daypart->separation_enabled = false;

        $wheel = new StationClockWheel($station);
        $wheel->daypart = $daypart;
        $wheel->separation_enabled = true;

        $settings = ClockWheelSeparationSettings::resolveForWheel($wheel);

        self::assertFalse($settings->enabled);
    }

    public function testUsesTemplateDefaultsWhenWheelSeparationDisabled(): void
    {
        $station = new Station();
        $template = new StationClockWheelTemplate($station);
        $template->separation_enabled = true;
        $template->separation_artist_minutes = 33;
        $template->separation_title_minutes = 66;
        $template->burn_rate_max_plays_24h = 5;

        $wheel = new StationClockWheel($station);
        $wheel->template = $template;
        $wheel->separation_enabled = false;
        $wheel->burn_rate_max_plays_24h = null;

        $settings = ClockWheelSeparationSettings::resolveForWheel($wheel);

        self::assertTrue($settings->enabled);
        self::assertSame(33, $settings->artistMinutes);
        self::assertSame(66, $settings->titleMinutes);
        self::assertSame(5, $settings->burnRateMaxPlays24h);
    }

    public function testSlotOverrideWinsOverWheel(): void
    {
        $station = new Station();
        $wheel = new StationClockWheel($station);
        $wheel->separation_enabled = true;
        $wheel->separation_artist_minutes = 45;

        $slot = new \App\Entity\StationClockWheelSlot($wheel);
        $slot->separation_override_enabled = true;
        $slot->separation_artist_minutes = 120;
        $slot->separation_title_minutes = 60;

        $settings = ClockWheelSeparationSettings::resolveForSlot($slot, $wheel);

        self::assertTrue($settings->enabled);
        self::assertSame(120, $settings->artistMinutes);
        self::assertSame(60, $settings->titleMinutes);
    }
}
