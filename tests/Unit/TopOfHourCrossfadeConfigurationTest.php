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
    public function testCapturesPostCrossSourceWithoutOverridingCommonCrossCallback(): void
    {
        $station = new Station();
        $station->name = 'TOH Crossfade Test';
        $station->short_name = 'toh_crossfade_test';
        $station->timezone = 'UTC';

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        $subscriber = new TopOfHourCrossfadeConfiguration();
        $subscriber->installBoundaryState($event);
        $subscriber->captureCrossSource($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('azuracast.autodj_hard_handoff_epoch = ref(0.0)', $config);
        self::assertStringContainsString('azuracast.broadcast_clock_cross_source = radio', $config);

        // The plugin must not replace or wrap the common PR #160 cross callback.
        // OLD-tail retirement remains owned by util/docker/.../azuracast.liq.
        self::assertStringNotContainsString('def azuracast.live_aware_crossfade_impl(old, new)', $config);
        self::assertStringNotContainsString('azuracast.autodj_fresh_hold', $config);
        self::assertStringNotContainsString('fresh_source =', $config);
        self::assertStringNotContainsString('hold_clean_cut_fresh_source', $config);
    }
}
