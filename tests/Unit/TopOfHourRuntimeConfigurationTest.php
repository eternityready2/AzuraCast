<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use Codeception\Test\Unit;
use Plugin\TopOfHour\TopOfHourRuntimeConfiguration;
use ReflectionClass;

require_once dirname(__DIR__, 2) . '/plugins/top_of_hour/src/TopOfHourRuntimeConfiguration.php';

final class TopOfHourRuntimeConfigurationTest extends Unit
{
    public function testTohClocksPostCrossOnlyUntilCommonCleanCutIsConsumed(): void
    {
        $station = $this->makeStation();

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('Top-of-Hour Station ID exact wall-clock lane (plugin owned)', $config);
        self::assertStringContainsString('def top_of_hour_id_enter(_, new)', $config);
        self::assertStringContainsString('top_of_hour_id_active := true', $config);
        self::assertStringContainsString('azuracast.discard_autodj_current_cleanly()', $config);
        self::assertStringContainsString(
            'armed #160 clean cross boundary and discarded interrupted AutoDJ request.',
            $config,
        );

        // Exact post-cross source is clocked at zero only while the common
        // clean-cut marker is pending. Once the callback clears pending, this
        // OUTER gate switches to generated blank and parks the whole cross graph.
        self::assertStringContainsString(
            'source.tracks(azuracast.broadcast_clock_cross_source)',
            $config,
        );
        self::assertStringContainsString('top_of_hour_cleanup_gate = switch(', $config);
        self::assertStringContainsString(
            '({azuracast.autodj_clean_cut_pending()}, top_of_hour_cleanup_audio)',
            $config,
        );
        self::assertStringContainsString('({true}, top_of_hour_cleanup_idle)', $config);
        self::assertStringNotContainsString('source.tracks(radio_before_top_of_hour)', $config);

        // Open-hour listener release must never depend on cleanup state.
        self::assertStringContainsString(
            '# Open hour: the ID file itself is the ONLY hold condition.',
            $config,
        );
        self::assertStringNotContainsString('top_of_hour_id.is_ready() or azuracast.autodj_clean_cut_pending()', $config);

        // Failed experimental architectures must stay gone.
        self::assertStringNotContainsString('azuracast.autodj_fresh_hold', $config);
        self::assertStringNotContainsString('discard_autodj_current_cleanly_and_hold', $config);
        self::assertStringNotContainsString('top_of_hour_cleanup_driver', $config);
        self::assertStringNotContainsString('server.execute("top_of_hour_cleanup_driver', $config);
        self::assertStringNotContainsString('source.skip(azuracast.broadcast_clock_cross_source)', $config);
    }

    public function testHardTohOwnsEveryFrameUntilExactBoundaryAndPublishesOneHandoff(): void
    {
        $station = $this->makeStation();

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('top_of_hour_hard_hold = blank(id="top_of_hour_hard_hold")', $config);
        self::assertStringContainsString('top_of_hour_lane = fallback(', $config);
        self::assertStringContainsString('[top_of_hour_id, top_of_hour_hard_hold]', $config);
        self::assertStringContainsString('boundary > 0.0 and now < boundary', $config);
        self::assertStringContainsString(
            'azuracast.autodj_hard_handoff_epoch := top_of_hour_id_boundary_epoch()',
            $config,
        );
        self::assertStringContainsString(
            'HARD lane released exactly at the :00 boundary to rigid authority.',
            $config,
        );
    }

    public function testClearResetsHandoffAndTohTimingState(): void
    {
        $station = $this->makeStation();

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('def top_of_hour_clear_queue(_)', $config);
        self::assertStringContainsString('azuracast.autodj_hard_handoff_epoch := 0.0', $config);
        self::assertStringContainsString('top_of_hour_id_active := false', $config);
        self::assertStringNotContainsString('azuracast.release_autodj_fresh_hold()', $config);
    }

    public function testDisabledPredicateLeavesNormalSourceSelected(): void
    {
        $station = $this->makeStation();
        $station->backend_config->top_of_hour_id_enabled = false;

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('if not top_of_hour_id_enabled() then', $config);
        self::assertStringContainsString('false', $config);
        self::assertStringContainsString('({true}, radio_before_top_of_hour)', $config);
        self::assertStringNotContainsString('autodj_retired_song_id', $config);
        self::assertStringNotContainsString('exclude_song_id', $config);
    }

    public function testQueueRepeatGuardPreservesInterruptedMusicAcrossTohMetadata(): void
    {
        $queueSource = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/Queue.php',
        );

        self::assertIsString($queueSource);
        self::assertStringContainsString(
            '$recentPlayedMusic = $this->queueRepo->getPlayedMusicHistoryByTimeRange(',
            $queueSource,
        );
        self::assertStringContainsString(
            '$lastSongId = $recentPlayedMusic[0][\'song_id\'] ?? null;',
            $queueSource,
        );
        self::assertStringContainsString(
            '$nextSongs[0]->song_id === $lastSongId',
            $queueSource,
        );
    }

    private function makeStation(): Station
    {
        $station = new Station();
        $station->name = 'TOH Runtime Test';
        $station->short_name = 'toh_runtime_test';
        $station->timezone = 'UTC';
        $station->radio_base_dir = '/tmp/toh_runtime_test';
        $station->backend_config->ai_news_enabled = false;
        $station->backend_config->ai_news_top_of_hour = false;

        return $station;
    }
}
