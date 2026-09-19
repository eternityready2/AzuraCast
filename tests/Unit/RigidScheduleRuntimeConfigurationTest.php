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
use Plugin\TopOfHour\RigidScheduleRuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/plugins/top_of_hour/src/RigidScheduleRuntimeConfiguration.php';

final class RigidScheduleRuntimeConfigurationTest extends Unit
{
    public function testStrictSongPlaylistGetsDedicatedNativeSourceForecastAndCleanAutoDjCut(): void
    {
        [$station] = $this->makeScheduledProgram(true);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new RigidScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString(
            '# Dedicated native source for a strict scheduled programme.',
            $config,
        );
        self::assertStringContainsString('rigid_playlist_scheduled_program_', $config);
        self::assertStringContainsString('mode="normal"', $config);
        self::assertStringContainsString('.register_command(', $config);
        self::assertStringContainsString('"forecast"', $config);
        self::assertStringContainsString('.remaining()', $config);
        self::assertStringContainsString('.remaining_files()', $config);
        self::assertStringContainsString('playlist.files(mime_type="audio/x-mpegurl"', $config);
        self::assertStringContainsString('11h0m-12h0m', $config);

        // The wall-clock switch owns the handoff. It arms the shared one-shot
        // cross clean-cut and skips exactly one real AutoDJ request; there is no
        // nested availability/fallback gate that can wake up afterward.
        self::assertStringContainsString('def rigid_schedule_enter(_, new)', $config);
        self::assertStringContainsString('azuracast.discard_autodj_current_cleanly()', $config);
        self::assertStringContainsString('id="rigid_schedule_runtime"', $config);

        self::assertStringNotContainsString('broadcast_clock_retire_outgoing(', $config);
        self::assertStringNotContainsString('broadcast_clock_autodj_gate', $config);
        self::assertStringNotContainsString('broadcast_clock_autodj_blocked', $config);
        self::assertStringNotContainsString('fallback.skip(', $config);
        self::assertStringNotContainsString('source.available(', $config);
        self::assertStringNotContainsString('broadcast_clock_release_when_fresh', $config);
        self::assertStringNotContainsString('azuracast.discard_autodj_current()', $config);
        self::assertStringNotContainsString('source.skip(source.effective(', $config);
    }

    public function testConfiguredStrictSongPlaylistStillUsesIsolatedRigidSource(): void
    {
        [$station, $playlist] = $this->makeScheduledProgram(true);
        $station->backend_config->write_playlists_to_liquidsoap = true;
        $playlist->backend_options = [StationPlaylist::OPTION_MERGE];

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new RigidScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        // Even when ConfigWriter will also create a normal native source, the
        // outer rigid lane must have its own source object. Reusing the normal
        // source above stretch()/cross() is what creates Liquidsoap Error 11.
        self::assertStringContainsString(
            '# Dedicated native source for a strict scheduled programme.',
            $config,
        );
        self::assertStringContainsString('rigid_playlist_scheduled_program_', $config);
        self::assertStringContainsString('merge_tracks(id="merge_rigid_playlist_scheduled_program_', $config);
        self::assertStringContainsString('id="rigid_schedule_runtime"', $config);
    }

    public function testStrictRemoteStreamGetsIndependentClockSource(): void
    {
        $station = new Station();
        $station->name = 'Rigid Remote Runtime Test';
        $station->short_name = 'rigid_remote_runtime_test';
        $station->timezone = 'UTC';
        $station->radio_base_dir = '/tmp/rigid_remote_runtime_test';

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Remote Program';
        $playlist->source = PlaylistSources::RemoteUrl;
        $playlist->type = PlaylistTypes::Standard;
        $playlist->remote_type = PlaylistRemoteTypes::Stream;
        $playlist->remote_url = 'https://example.test/program.mp3';
        $playlist->remote_buffer = 20;
        $playlist->is_enabled = true;

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = 1300;
        $schedule->end_time = 1400;
        $schedule->days = [];
        $schedule->strict_start = true;

        $station->playlists->add($playlist);
        $playlist->schedule_items->add($schedule);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new RigidScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('rigid_playlist_remote_program_', $config);
        self::assertStringContainsString(
            'mksafe(buffer(buffer=20., input.http("https://example.test/program.mp3")))',
            $config,
        );
        self::assertStringContainsString('13h0m-14h0m', $config);
        self::assertStringContainsString('id="rigid_schedule_runtime"', $config);
    }

    public function testFlexibleScheduleDoesNotWrapOrdinaryRadioPath(): void
    {
        [$station] = $this->makeScheduledProgram(false);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new RigidScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('rigid_schedule_active = ref(false)', $config);
        self::assertStringNotContainsString('id="rigid_schedule_runtime"', $config);
        self::assertStringNotContainsString('azuracast.discard_autodj_current_cleanly()', $config);
        self::assertStringNotContainsString('broadcast_clock_autodj_gate', $config);
        self::assertStringNotContainsString('fallback.skip(', $config);
        self::assertStringNotContainsString('source.available(', $config);
    }

    public function testProgrammePlaylistOptionDoesNotConvertFlexibleRowToStrictLane(): void
    {
        [$station, $playlist] = $this->makeScheduledProgram(false);
        $playlist->backend_options = [StationPlaylist::OPTION_INTERRUPT_OTHER_SONGS];

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new RigidScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringNotContainsString('id="rigid_schedule_runtime"', $config);
        self::assertStringNotContainsString('rigid_playlist_scheduled_program_', $config);
    }

    /** @return array{Station, StationPlaylist, StationSchedule} */
    private function makeScheduledProgram(bool $strictStart): array
    {
        $station = new Station();
        $station->name = 'Rigid Runtime Test';
        $station->short_name = 'rigid_runtime_test';
        $station->timezone = 'UTC';
        $station->radio_base_dir = '/tmp/rigid_runtime_test';
        $station->backend_config->write_playlists_to_liquidsoap = false;
        $station->backend_config->use_manual_autodj = false;

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Scheduled Program';
        $playlist->source = PlaylistSources::Songs;
        $playlist->type = PlaylistTypes::Standard;
        $playlist->is_enabled = true;

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = 1100;
        $schedule->end_time = 1200;
        $schedule->days = [];
        $schedule->strict_start = $strictStart;

        $station->playlists->add($playlist);
        $playlist->schedule_items->add($schedule);

        return [$station, $playlist, $schedule];
    }
}
