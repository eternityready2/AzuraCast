<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use Codeception\Test\Unit;
use Plugin\TopOfHour\TopOfHourCrossfadeConfiguration;

require_once dirname(__DIR__, 2) . '/plugins/top_of_hour/src/TopOfHourCrossfadeConfiguration.php';

final class TopOfHourCrossfadeConfigurationTest extends Unit
{
    public function testCleanCutParksFreshSourceAndCapturesOnlyPostCrossGraph(): void
    {
        $station = new Station();
        $station->name = 'TOH Crossfade Test';
        $station->short_name = 'toh_crossfade_test';
        $station->timezone = 'UTC';

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        $subscriber = new TopOfHourCrossfadeConfiguration();
        $subscriber->installHoldAwareCrossfade($event);
        $subscriber->captureCrossSource($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('azuracast.autodj_fresh_hold = ref(false)', $config);
        self::assertStringContainsString('azuracast.autodj_hard_handoff_epoch = ref(0.0)', $config);
        self::assertStringContainsString('def azuracast.discard_autodj_current_cleanly_and_hold(handoff_epoch)', $config);
        self::assertStringContainsString('source.skip(azuracast.autodj_transport())', $config);
        self::assertStringContainsString('def azuracast.hold_clean_cut_fresh_source(s)', $config);
        self::assertStringContainsString('({ not azuracast.autodj_fresh_hold() }, s)', $config);
        self::assertStringContainsString('azuracast.hold_clean_cut_fresh_source(new.source)', $config);
        self::assertStringContainsString('discarded buffered old crossfade tail and parked fresh successor.', $config);
        self::assertStringContainsString('azuracast.broadcast_clock_cross_source = radio', $config);

        // Normal/live paths are intentionally copied from the common runtime so
        // TOH changes only the forced clean-cut branch.
        self::assertStringContainsString('elsif azuracast.to_live() then', $config);
        self::assertStringContainsString('cross.smart(', $config);
        self::assertStringContainsString('cross.simple(', $config);
        self::assertStringContainsString('add(normalize=false, [', $config);
    }
}
