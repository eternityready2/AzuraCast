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
    public function testFlexibleScheduleKeepsNominalPlanningAndAddsSixtySecondDeadline(): void
    {
        [$station] = $this->makeScheduledProgram(false);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertSame(60, FlexibleScheduleRuntimeConfiguration::FLEXIBLE_GRACE_SECONDS);
        self::assertStringContainsString('11h1m0s-11h1m59s', $config);
        self::assertStringContainsString('bounded_flexible_current_playlist_id', $config);
        self::assertStringContainsString('bounded_flexible_current_sq_id', $config);
        self::assertStringContainsString('azuracast.live_enabled()', $config);
        self::assertStringContainsString('azuracast.discard_autodj_current_cleanly()', $config);
        self::assertStringContainsString('thread.run.recurrent(delay=0.25', $config);
        self::assertMatchesRegularExpression(
            '/thread\.run\.recurrent\(delay=0\.25, \{.*?\n\s+0\.25\n\s*\}\)/s',
            $config,
            'Liquidsoap recurrent callbacks must return a float delay, not unit.',
        );

        // The bounded layer is intentionally not another source/switch. The
        // existing station graph keeps ownership of source selection, crossfade,
        // Stretch/Squeeze, remote streams and playlist sequence state.
        self::assertStringNotContainsString('bounded_playlist_scheduled_program_', $config);
        self::assertStringNotContainsString('bounded_flexible_schedule_runtime', $config);
    }

    public function testAlreadyPlayingScheduledPlaylistIsNotCutAtDeadline(): void
    {
        [$station] = $this->makeScheduledProgram(false);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('target_playlist =', $config);
        self::assertStringContainsString('current_playlist = bounded_flexible_current_playlist_id()', $config);
        self::assertStringContainsString('if current_playlist == target_playlist then', $config);
    }

    public function testWatchdogOnlyCutsActualAutoDjQueueRows(): void
    {
        [$station] = $this->makeScheduledProgram(false);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('list.assoc(default="", "sq_id", m)', $config);
        self::assertStringContainsString('elsif current_sq != "" then', $config);
        self::assertStringContainsString(
            '# Only cut a real AutoDJ queue request.',
            $config,
        );
    }

    public function testStrictScheduleRemainsOwnedByRigidLane(): void
    {
        [$station] = $this->makeScheduledProgram(true);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringNotContainsString('bounded_flexible_current_playlist_id', $config);
        self::assertStringNotContainsString('discard_autodj_current_cleanly()', $config);
    }

    public function testEmergencyAndInterruptingSchedulesRemainOutsideFlexiblePolicy(): void
    {
        [$station, $playlist, $schedule] = $this->makeScheduledProgram(false);
        $schedule->is_emergency = true;

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        self::assertStringNotContainsString(
            'bounded_flexible_current_playlist_id',
            $event->buildConfiguration(),
        );

        $schedule->is_emergency = false;
        $playlist->backend_options = [StationPlaylist::OPTION_INTERRUPT_OTHER_SONGS];

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        self::assertStringNotContainsString(
            'bounded_flexible_current_playlist_id',
            $event->buildConfiguration(),
        );
    }

    public function testRemoteStreamUsesSameDeadlineWatchdogWithoutOpeningSecondInput(): void
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

        self::assertStringContainsString('13h1m0s-13h1m59s', $config);
        self::assertStringContainsString('current_sq != ""', $config);
        self::assertStringNotContainsString('input.http(', $config);
        self::assertStringNotContainsString('bounded_playlist_remote_program_', $config);
    }

    public function testGraceCrossingMidnightMovesDeadlineToFollowingScheduleDay(): void
    {
        [$station, , $schedule] = $this->makeScheduledProgram(false);
        $schedule->start_time = 2359;
        $schedule->end_time = 30;
        $schedule->days = [1]; // Monday nominal start; 00:00 deadline is Tuesday.

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new FlexibleScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('(2w) and 0h0m0s-0h0m59s', $config);
    }

    public function testRuntimeRunsAfterRigidAndTopOfHourGraphIsFinalized(): void
    {
        $subscriptions = FlexibleScheduleRuntimeConfiguration::getSubscribedEvents();
        self::assertSame(
            ['writeRuntime', 14],
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
