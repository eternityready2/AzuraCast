<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\PlaylistRemoteTypes;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use Codeception\Test\Unit;
use Plugin\TopOfHour\FlexibleScheduleRuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/plugins/top_of_hour/src/FlexibleScheduleRuntimeConfiguration.php';

final class FlexibleScheduleRuntimeConfigurationTest extends Unit
{
    public function testFlexibleScheduleKeepsNaturalWindowThenEnforcesSixtySecondDeadline(): void
    {
        [$station] = $this->makeScheduledProgram(false);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertSame(60, FlexibleScheduleRuntimeConfiguration::FLEXIBLE_GRACE_SECONDS);
        self::assertStringContainsString(
            '# Dedicated native source for bounded flexible deadline enforcement.',
            $config,
        );
        self::assertStringContainsString('bounded_playlist_scheduled_program_', $config);
        self::assertStringContainsString('11h1m0s-12h0m0s', $config);
        self::assertStringContainsString('bounded_flexible_current_playlist_id', $config);
        self::assertStringContainsString('azuracast.live_enabled()', $config);
        self::assertStringContainsString('azuracast.discard_autodj_current_cleanly()', $config);
        self::assertStringContainsString('id="bounded_flexible_schedule_runtime"', $config);
        self::assertStringContainsString('track_sensitive=false', $config);
    }

    public function testAlreadyPlayingScheduledPlaylistIsNotForcedAgainAtDeadline(): void
    {
        [$station] = $this->makeScheduledProgram(false);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('list.assoc(default="", "playlist_id", m)', $config);
        self::assertStringContainsString('bounded_flexible_current_playlist_id() !=', $config);
    }

    public function testStrictScheduleRemainsOwnedByRigidLane(): void
    {
        [$station] = $this->makeScheduledProgram(true);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringNotContainsString('bounded_flexible_schedule_runtime', $config);
        self::assertStringNotContainsString('discard_autodj_current_cleanly()', $config);
    }

    public function testEmergencyAndInterruptingSchedulesRemainOutsideFlexibleLane(): void
    {
        [$station, $playlist, $schedule] = $this->makeScheduledProgram(false);
        $schedule->is_emergency = true;

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        self::assertStringNotContainsString(
            'bounded_flexible_schedule_runtime',
            $event->buildConfiguration(),
        );

        $schedule->is_emergency = false;
        $playlist->backend_options = [StationPlaylist::OPTION_INTERRUPT_OTHER_SONGS];

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        self::assertStringNotContainsString(
            'bounded_flexible_schedule_runtime',
            $event->buildConfiguration(),
        );
    }

    public function testRemoteStreamIsNotReopenedByDeadlineBackstop(): void
    {
        $station = $this->makeStation();

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Remote Program';
        $playlist->source = PlaylistSources::RemoteUrl;
        $playlist->type = PlaylistTypes::Standard;
        $playlist->remote_type = PlaylistRemoteTypes::Stream;
        $playlist->remote_url = 'https://example.test/live.mp3';
        $playlist->is_enabled = true;

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = 1300;
        $schedule->end_time = 1400;
        $schedule->days = [];

        $station->playlists->add($playlist);
        $playlist->schedule_items->add($schedule);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringNotContainsString('bounded_playlist_remote_program_', $config);
        self::assertStringNotContainsString('bounded_flexible_schedule_runtime', $config);
    }

    public function testGraceCrossingMidnightMovesDeadlineToFollowingScheduleDay(): void
    {
        [$station, , $schedule] = $this->makeScheduledProgram(false);
        $schedule->start_time = 2359;
        $schedule->end_time = 30;
        $schedule->days = [1]; // Monday start; 00:00 deadline is Tuesday.

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('(2w) and 0h0m0s-0h30m0s', $config);
    }

    public function testRuntimePriorityKeepsStrictAndTopOfHourAboveFlexibleDeadline(): void
    {
        $subscriptions = FlexibleScheduleRuntimeConfiguration::getSubscribedEvents();
        self::assertSame(
            ['writeRuntime', 17],
            $subscriptions[WriteLiquidsoapConfiguration::class],
        );
    }

    /** @return array{Station, StationPlaylist, StationSchedule} */
    private function makeScheduledProgram(bool $strict): array
    {
        $station = $this->makeStation();

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Scheduled Program';
        $playlist->source = PlaylistSources::Songs;
        $playlist->type = PlaylistTypes::Standard;
        $playlist->is_enabled = true;

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = 1100;
        $schedule->end_time = 1200;
        $schedule->days = [];
        $schedule->strict_start = $strict;

        $station->playlists->add($playlist);
        $playlist->schedule_items->add($schedule);

        return [$station, $playlist, $schedule];
    }

    private function makeStation(): Station
    {
        $station = new Station();
        $station->name = 'Bounded Flexible Test';
        $station->short_name = 'bounded_flexible_test';
        $station->timezone = 'UTC';
        $station->radio_base_dir = '/tmp/bounded_flexible_test';
        $station->backend_config->write_playlists_to_liquidsoap = false;
        $station->backend_config->use_manual_autodj = false;

        return $station;
    }
}
