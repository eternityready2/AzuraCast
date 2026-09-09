<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Installs the TOH-specific clean-cut hold immediately before AzuraCast builds
 * the station crossfade, then captures that exact processed cross source
 * immediately afterward.
 *
 * This keeps the live-proven PR #160 invariant: the real cross operator remains
 * continuously clocked while the legal ID owns the air. The difference is that
 * after the cross has permanently rejected old.source, the fresh new.source is
 * parked behind a source switch and is not consumed until TOH releases it.
 */
final class TopOfHourCrossfadeConfiguration implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => [
                ['installHoldAwareCrossfade', 26],
                ['captureCrossSource', 24],
            ],
        ];
    }

    public function installHoldAwareCrossfade(WriteLiquidsoapConfiguration $event): void
    {
        $event->appendBlock(
            <<<'LIQ'
            # Top-of-Hour hold-aware clean-cut state (plugin owned).
            azuracast.autodj_fresh_hold = ref(false)
            azuracast.autodj_hard_handoff_epoch = ref(0.0)

            # Arm the same destructive request.dynamic cut used by PR #160, but
            # also park the fresh cross successor until the TOH owner releases it.
            # A non-zero handoff epoch marks a HARD :00 boundary so the rigid
            # schedule can consume that one retirement instead of cutting again.
            def azuracast.discard_autodj_current_cleanly_and_hold(handoff_epoch) =
                if azuracast.autodj_transport_ready() and not azuracast.autodj_clean_cut_pending() then
                    azuracast.autodj_fresh_hold := true
                    azuracast.autodj_hard_handoff_epoch := handoff_epoch
                    azuracast.autodj_clean_cut_pending := true
                    source.skip(azuracast.autodj_transport())
                    log(
                        level=2,
                        label="azuracast.autodj",
                        "Armed held clean cross boundary and discarded current AutoDJ transport request."
                    )
                end
            end

            def azuracast.release_autodj_fresh_hold() =
                azuracast.autodj_fresh_hold := false
            end

            # The cross callback returns this source after permanently rejecting
            # old.source. While the hold is true only the generated blank is
            # clocked; new.source itself remains parked at its opening frame.
            def azuracast.hold_clean_cut_fresh_source(s) =
                hold_blank = blank()
                switch(
                    track_sensitive=false,
                    replay_metadata=true,
                    transition_length=0.0,
                    [
                        ({ not azuracast.autodj_fresh_hold() }, s),
                        ({ true }, hold_blank)
                    ]
                )
            end

            # Override only the station cross callback before ConfigWriter applies
            # the cross operator. Normal/live crossfade behavior remains identical
            # to the common runtime; only the one-shot broadcast-clock branch is
            # changed from `new.source` to the parked fresh-source switch above.
            def azuracast.live_aware_crossfade_impl(old, new) =
                log.info(label="azuracast.crossfade", "Crossfading")
                list.iter(
                    fun (m) -> log.info(label="azuracast.crossfade", "Old metadata: #{fst(m)} -> #{snd(m)}"),
                    metadata.cover.remove(old.metadata)
                )
                list.iter(
                    fun (m) -> log.info(label="azuracast.crossfade", "New metadata: #{fst(m)} -> #{snd(m)}"),
                    metadata.cover.remove(new.metadata)
                )

                if azuracast.autodj_clean_cut_pending() then
                    azuracast.autodj_clean_cut_pending := false
                    log.info(
                        label="azuracast.crossfade",
                        "Broadcast-clock clean cut: discarded buffered old crossfade tail and parked fresh successor."
                    )
                    azuracast.hold_clean_cut_fresh_source(new.source)
                elsif azuracast.to_live() then
                    log.info(label="azuracast.crossfade", "Fading to live...")
                    sequence([
                        fade.out(duration=settings.azuracast.default_fade(), old.source),
                        fade.in(duration=settings.azuracast.default_fade(), new.source)
                    ])
                elsif settings.azuracast.enable_crossfade() then
                    if settings.azuracast.crossfade_type() == "smart" then
                        log.info(label="azuracast.crossfade", "Smart crossfade...")
                        cross.smart(
                            old,
                            new,
                            high=settings.azuracast.crossfade_smart_high(),
                            medium=settings.azuracast.crossfade_smart_medium(),
                            margin=settings.azuracast.crossfade_smart_margin(),
                            fade_in=settings.azuracast.default_fade(),
                            fade_out=settings.azuracast.default_fade()
                        )
                    else
                        log.info(label="azuracast.crossfade", "Simple crossfade...")
                        cross.simple(
                            old.source,
                            new.source,
                            fade_in=settings.azuracast.default_fade(),
                            fade_out=settings.azuracast.default_fade()
                        )
                    end
                else
                    log.info(label="azuracast.crossfade", "Add crossfade...")
                    add(normalize=false, [
                        fade.in(
                            initial_metadata=new.metadata,
                            duration=settings.azuracast.default_fade(),
                            new.source
                        ),
                        fade.out(
                            initial_metadata=old.metadata,
                            duration=settings.azuracast.default_fade(),
                            old.source
                        )
                    ])
                end
            end
            LIQ
        );
    }

    public function captureCrossSource(WriteLiquidsoapConfiguration $event): void
    {
        $event->appendBlock(
            <<<'LIQ'
            # Exact processed post-cross source used only for TOH clean-cut
            # continuity. This capture happens before harbor/live and before the
            # rigid-schedule wrapper, so TOH can never clock a scheduled programme
            # underneath the legal ID.
            azuracast.broadcast_clock_cross_source = radio
            LIQ
        );
    }
}
