<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Captures the exact processed post-cross AutoDJ source used by the live-proven
 * PR #160 clean-cut path.
 *
 * The common AzuraCast cross callback remains untouched. TOH only needs an early
 * HARD-handoff token and a reference to the post-cross source; the outer TOH
 * runtime decides how long that source is clocked. This avoids competing with
 * the common cross implementation or trying to freeze cross.new.source inside
 * the cross operator itself.
 */
final class TopOfHourCrossfadeConfiguration implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => [
                ['installBoundaryState', 26],
                ['captureCrossSource', 24],
            ],
        ];
    }

    public function installBoundaryState(WriteLiquidsoapConfiguration $event): void
    {
        $event->appendBlock(
            <<<'LIQ'
            # One-shot HARD TOH -> rigid-schedule handoff token. This is defined
            # before the rigid runtime is written so both wrappers share it.
            azuracast.autodj_hard_handoff_epoch = ref(0.0)
            LIQ
        );
    }

    public function captureCrossSource(WriteLiquidsoapConfiguration $event): void
    {
        $event->appendBlock(
            <<<'LIQ'
            # Exact processed post-cross AutoDJ source. The common PR #160
            # clean-cut callback owns OLD-tail retirement; TOH only clocks this
            # source until that callback has consumed clean_cut_pending.
            #
            # Capture happens before harbor/live and before the rigid-schedule
            # wrapper, so TOH maintenance can never advance a scheduled programme
            # or live-source wrapper underneath the legal ID.
            azuracast.broadcast_clock_cross_source = radio
            LIQ
        );
    }
}
