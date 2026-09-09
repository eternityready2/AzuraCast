<?php

declare(strict_types=1);

namespace Unit;

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
    public function testStrictSongPlaylistGetsDedicatedNativeSourceAndSingleBoundaryCut(): void
    {
        [$station] = $this->makeScheduledProgram(true);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new RigidScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString(
            '# Dedicated native source for an AutoDJ-only rigid scheduled programme.',
            $config,
        );
        self::assertStringContainsString('rigid_playlist_scheduled_program_', $config);
        self::assertStringContainsString('mode="randomize"', $config);
        self::assertStringContainsString('11h0m-12h0m', $config);

        self::assertStringContainsString('def rigid_schedule_enter(_, new)', $config);
        self::assertStringContainsString('azuracast.autodj_hard_handoff_epoch()', $config);
        self::assertStringContainsString('handoff_epoch - 1.0', $config);
        self::assertStringContainsString('handoff_epoch + 2.0', $config);
        self::assertStringContainsString(
            'Rigid Schedule: consumed HARD TOH handoff; no second AutoDJ cut.',
            $config,
        );
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

    public function testStaleTohHandoffCannotSuppressLaterRigidCut(): void
    {
        [$station] = $this->makeScheduledProgram(true);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new RigidScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('azuracast.autodj_hard_handoff_epoch := 0.0', $config);
        self::assertStringContainsString(
            'Rigid Schedule: armed clean cross boundary for interrupted AutoDJ request.',
            $config,
        );
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
