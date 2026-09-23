<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use App\Radio\Enums\LiquidsoapQueues;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Exact on-air enforcement for automatic Top-of-Hour Station IDs.
 *
 * PHP resolves the station-local :59:ss target into an absolute epoch and
 * pre-stages the request. Liquidsoap owns the actual wall-clock switch.
 *
 * The TOH switch owns the deadline. While the ID is on air the processed
 * station underlay stays clocked at zero gain so the inner cross operator can
 * consume a release-time clean cut instead of being parked and replaying its
 * buffered old tail. At release the interrupted request is discarded and the
 * underlay is held muted until the fresh track is genuinely on air.
 */
final class TopOfHourRuntimeConfiguration implements EventSubscriberInterface
{
    public function __construct(
        private readonly TopOfHourClock $clock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => ['writeRuntime', 14],
        ];
    }

    public function writeRuntime(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $config = $event->getBackendConfig();
        $queueName = LiquidsoapQueues::TopOfHour->value;
        $fadeSeconds = ConfigWriter::toFloat($this->clock->getIdFadeSeconds($station), 1);

        $newsEnabled = ($config->ai_news_enabled && $config->ai_news_top_of_hour);

        $newsStaging = $newsEnabled
            ? <<<'LIQ'
                if not top_of_hour_id_hard_boundary() then
                    if is_within_active_hours() then
                        top_of_hour_news.push(request.create(news_bulletin_request))
                        log("Top-of-Hour ID: staged top-hour AI News to lead the new hour.")
                    else
                        log("Top-of-Hour ID: AI News skipped - outside active hours window.")
                    end
                end
                LIQ
            : '# Top-hour AI News is disabled; nothing is staged after the ID.';

        $event->appendBlock(
            <<<LIQ
            # Top-of-Hour Station ID exact wall-clock lane (plugin owned).
            # `top_of_hour_id_enabled` is created earlier by the AI News/TOH
            # coordination subscriber so both systems share one live runtime ref.
            top_of_hour_id = request.queue(
                id="{$queueName}",
                timeout=settings.azuracast.request_timeout()
            )

            top_of_hour_id_fade_seconds = ref({$fadeSeconds})
            top_of_hour_id_target_epoch = ref(0.0)
            top_of_hour_id_boundary_epoch = ref(0.0)
            top_of_hour_id_release_epoch = ref(0.0)

            # How long the underlay stays hard-muted after the ID lane releases.
            # source.skip() only takes effect on the next fill, so without a hold
            # the switch can pull real frames of the interrupted song at :00.
            # Sized from the station's own crossfade duration, never below 0.75s.
            top_of_hour_id_release_hold_seconds = ref(
                if settings.azuracast.default_cross() + 0.25 > 0.75 then
                    settings.azuracast.default_cross() + 0.25
                else
                    0.75
                end
            )

            top_of_hour_id_active = ref(false)
            top_of_hour_id_hard_boundary = ref(false)

            # Self-contained hook for dropping an interrupted STRICT-lane item
            # at release. Defaults to a no-op so this NEVER crashes, regardless
            # of whether this station has any rigid schedule configured at all
            # (RigidScheduleRuntimeConfiguration only writes its own
            # rigid_schedule_drop_interrupted ref when the station actually has
            # one; on a station with none, that identifier is never defined and
            # calling it directly is a compile-time "Undefined variable" error,
            # not something try/catch can rescue).
            #
            # If this station's plugin config later grows a rigid schedule,
            # wiring the real function in is a one-line addition to
            # RigidScheduleRuntimeConfiguration:
            #   top_of_hour_id_rigid_drop_hook := rigid_schedule_drop_interrupted_item
            # Until then this safely does nothing, which is correct: there is
            # no rigid lane item to drop.
            top_of_hour_id_rigid_drop_hook = ref(fun () -> ())
            def top_of_hour_id_rigid_drop() =
                top_of_hour_id_rigid_drop_hook()()
            end

            def top_of_hour_id_on_track(_) =
                top_of_hour_id_active := true
                # request.queue removes the active request from its waiting queue;
                # purge any remaining staged tail so one deadline cannot double-ID.
                top_of_hour_id.set_queue([])
                log("Top-of-Hour ID: Station ID is on air; cleared duplicate staged tail.")
            end
            source.methods(top_of_hour_id).on_track(synchronous=false, top_of_hour_id_on_track)

            # Top-hour AI News lane queue (plugin owned). Filled at ID takeover,
            # played by the lane straight after the ID.
            top_of_hour_news = request.queue(
                id="top_of_hour_news",
                timeout=settings.azuracast.request_timeout()
            )

            # The ID may be shorter than the remaining time to :00. Keep the TOH
            # lane continuously ready with silence after the ID so ordinary music
            # can never sneak back in before the hour boundary. When top-hour AI
            # News is enabled the bulletin is a member of this lane and follows the
            # ID directly, so it can neither start under the ID nor wait behind a
            # song after it.
            top_of_hour_hard_hold = blank(id="top_of_hour_hard_hold")
            top_of_hour_lane = fallback(
                id="top_of_hour_lane",
                track_sensitive=false,
                transition_length=0.0,
                [top_of_hour_id, top_of_hour_news, top_of_hour_hard_hold]
            )

            # Fade the complete underlying station graph before the deadline.
            # At the target it has reached zero, so the ID itself starts exactly
            # on time rather than waiting for a fade or normal track boundary.
            #
            # After release, hold at zero until the fresh track is genuinely on
            # air. This is a plain bounded time window and is deliberately NOT
            # gated on autodj_clean_cut_pending(): that marker is only armed when
            # autodj_transport_ready() is true, so whenever it was not armed the
            # old condition muted NOTHING and the tail of the cut song played
            # straight through at :00.
            def top_of_hour_underlying_gain() =
                now = time()
                target = top_of_hour_id_target_epoch()
                id_fade_len = top_of_hour_id_fade_seconds()
                release = top_of_hour_id_release_epoch()

                if
                    top_of_hour_id_enabled()
                    and top_of_hour_id.is_ready()
                    and target > 0.0
                    and id_fade_len > 0.0
                    and now >= target - id_fade_len
                    and now < target
                then
                    remaining = (target - now) / id_fade_len
                    if remaining < 0.0 then
                        0.0
                    elsif remaining > 1.0 then
                        1.0
                    else
                        remaining
                    end
                elsif
                    release > 0.0
                    and now < release + top_of_hour_id_release_hold_seconds()
                then
                    0.0
                else
                    1.0
                end
            end

            radio_before_top_of_hour = amplify(
                id="top_of_hour_prefade",
                override=null,
                {top_of_hour_underlying_gain()},
                radio
            )

            # Keep the processed underlay alive at zero gain while the ID owns the
            # air, so the inner crossfade operator stays clocked and can consume
            # the release-time clean cut instead of stranding a buffer that would
            # replay the interrupted song. Only the audio track is retained, then
            # hard-muted; metadata and track marks cannot leak through the ID.
            let {
                audio=top_of_hour_underlay_audio,
                ...top_of_hour_underlay_non_audio
            } = source.tracks(radio_before_top_of_hour)
            ignore(top_of_hour_underlay_non_audio)
            top_of_hour_clocked_underlay = source(
                id="top_of_hour_clocked_underlay",
                {audio=top_of_hour_underlay_audio}
            )
            top_of_hour_clocked_underlay = amplify(
                id="top_of_hour_clocked_underlay_gain",
                override=null,
                0.0,
                top_of_hour_clocked_underlay
            )

            top_of_hour_clocked_lane = add(
                id="top_of_hour_clocked_lane",
                normalize=false,
                [top_of_hour_lane, top_of_hour_clocked_underlay]
            )

            def top_of_hour_id_should_play() =
                now = time()
                target = top_of_hour_id_target_epoch()
                boundary = top_of_hour_id_boundary_epoch()

                if not top_of_hour_id_enabled() then
                    false
                elsif top_of_hour_id_active() then
                    # Own every frame until the :00 boundary, on open hours as
                    # well as HARD ones, so nothing airs between the ID and the
                    # new hour. A short ID is followed by silence, not music.
                    # An ID that overruns :00 on an open hour is still allowed to
                    # finish.
                    (boundary > 0.0 and now < boundary)
                    or (not top_of_hour_id_hard_boundary() and top_of_hour_id.is_ready())
                    or top_of_hour_news.is_ready()
                else
                    target > 0.0
                    and boundary > target
                    and now >= target
                    and now < boundary
                    and top_of_hour_id.is_ready()
                end
            end

            def top_of_hour_id_enter(_, new) =
                # Mark ownership synchronously; the request.queue on_track callback
                # also sets this when metadata arrives, but must not be the timing
                # primitive for a frame-accurate hold.
                top_of_hour_id_active := true
                top_of_hour_id_release_epoch := 0.0

                # Do NOT discard the AutoDJ here. The muted underlay keeps whatever
                # is on air clocked (and inaudible) through the ID; discarding now
                # would make the crossfade fetch the next song and advance it under
                # the ID so it surfaced mid-song at :00. The discard happens at
                # release instead.
                {$newsStaging}

                if not azuracast.live_enabled() then
                    # Drain the AutoDJ transport so nothing can start underneath
                    # the ID, and so nothing resolved BEFORE the ID can surface
                    # after it. The first skip ends the interrupted song; each
                    # later one consumes another request that was already
                    # resolved and buffered ahead of it.
                    #
                    # This used to be exactly two skips, on the assumption that
                    # request.dynamic holds exactly one request in reserve. It
                    # holds more than that in practice. Observed on air: at the
                    # 20:00 boundary two tracks were resolved-but-unaired when
                    # the ID took over; two skips consumed only the interrupted
                    # song and one of them, and the survivor ("Phil Wickham -
                    # The Jesus Way", resolved 19:54:31) surfaced when the lane
                    # released and played for 6.0 seconds before the freshly
                    # prefetched track replaced it -- audible on air as a song
                    # starting after the ID and then being cut and swapped for
                    # a different one.
                    #
                    # Extra skips are safe precisely because the nextsong API
                    # refuses every request for the whole ID window (see
                    # NextSongCommand): a skip can only ever drain the buffer,
                    # never pull a fresh track in to burn, and once the
                    # transport is dry each further skip is a no-op. Each row
                    # drained this way is handed back to the queue by
                    # StationQueueRepository::releaseUnairedSentRow() so nothing
                    # is lost from rotation.
                    source.skip(azuracast.autodj_transport())
                    thread.run(
                        delay=0.5,
                        { source.skip(azuracast.autodj_transport()) }
                    )
                    thread.run(
                        delay=1.5,
                        { source.skip(azuracast.autodj_transport()) }
                    )
                    thread.run(
                        delay=3.0,
                        { source.skip(azuracast.autodj_transport()) }
                    )
                    thread.run(
                        delay=6.0,
                        { source.skip(azuracast.autodj_transport()) }
                    )
                    log("Top-of-Hour ID: drained the AutoDJ transport for the ID window.")
                end

                log("Top-of-Hour ID: took wall-clock authority; underlay clocked at zero gain.")
                new
            end

            def top_of_hour_id_exit(_, new) =
                was_hard = top_of_hour_id_hard_boundary()

                # Arm the post-release mute FIRST. Everything after this point is
                # asynchronous; this ref is the only thing that is not, so it must
                # win the race. source.skip() takes effect on the next fill, and
                # until then the switch can still pull real frames of the
                # interrupted song.
                #
                # Only on an OPEN hour. `amplify` wraps the ENTIRE underlying
                # graph, rigid lane included, so muting after a HARD release would
                # mute the opening of the rigid programme that owns :00 rather
                # than an AutoDJ tail.
                if was_hard then
                    top_of_hour_id_release_epoch := 0.0
                else
                    top_of_hour_id_release_epoch := time()
                end

                # Drop the interrupted STRICT-lane item, on every hour.
                #
                # This used to be gated on `not was_hard`, which meant it never
                # ran during an overnight rigid block -- because there every :00
                # is hard. The item on air inside a strict programme is not an
                # AutoDJ request, so discard_autodj_current_cleanly() below does
                # not touch it, and the hymn the ID cut came straight back at
                # :00. Confirmed in the timeline: hours 01:00-04:00 (inside the
                # rigid overnight block) all resumed the cut song; 05:00-10:00
                # (open hours, drop was running) all opened cleanly.
                #
                # Done FIRST, before the lane releases, so the item being dropped
                # is the old interrupted one rather than whatever the rigid lane
                # is about to start at :00.
                if not azuracast.live_enabled() then
                    top_of_hour_id_rigid_drop()
                end

                # HARD :00 may cut a long/mis-timed ID. Discard any current/tail
                # request and empty the waiting queue so it cannot reappear.
                top_of_hour_id.skip()
                top_of_hour_id.set_queue([])
                top_of_hour_news.skip()
                top_of_hour_news.set_queue([])

                if not azuracast.live_enabled() then
                    # discard_autodj_current_cleanly() refuses to do anything while
                    # a clean-cut marker armed by another broadcast-clock owner is
                    # still live (see autodj_clean_cut_window). When that happens
                    # the interrupted request is never skipped and it resumes after
                    # the ID. The call is silent in that case, so say so out loud.
                    # Clear any stale clean-cut marker before discarding.
                    #
                    # discard_autodj_current_cleanly() silently REFUSES to do
                    # anything while a marker armed by another broadcast-clock
                    # owner is still live, and when it refuses the interrupted
                    # request is never skipped -- so it resumes after the ID.
                    # That is the daytime half of the resumption bug: 15 of 219
                    # daytime hours in the timeline came back, on open hours
                    # where the rigid drop above was already running.
                    #
                    # At the top of the hour the TOH lane is the authoritative
                    # owner of the transition, so it takes the marker rather than
                    # deferring to whoever armed it seconds earlier.
                    # Arm the crossfade to reject its buffered old tail, then skip
                    # the real request.dynamic leaf. Because the underlay kept the
                    # crossfade clocked through the ID, this boundary is consumed
                    # immediately and the new hour opens on a fresh request.
                    #
                    # ONLY when this lane is actually releasing at :00, though.
                    # The discard exists to destroy the song the ID interrupted,
                    # which is only still in the transport at the boundary
                    # itself. When top-of-hour AI News is enabled the lane keeps
                    # the air well past :00 (top_of_hour_id_should_play() stays
                    # true while top_of_hour_news.is_ready()), and meanwhile the
                    # nextsong window has already closed at :00 -- so by the time
                    # the news ends the transport has legitimately resolved FRESH
                    # tracks for the new hour. Discarding then destroys one of
                    # those instead, which is audible as a song starting after
                    # the news and being cut seconds later. Measured on air:
                    # 21:02:37 Joel Jackson started and was cut 2.9s into a
                    # 224s track, replaced by We The Kingdom.
                    #
                    # So: at the boundary, discard (the interrupted song is what
                    # is held). Well past it, leave the transport alone -- what
                    # it holds is the new hour's music, and the enter-time drain
                    # already destroyed the interrupted song.
                    seconds_past_boundary = time() - top_of_hour_id_boundary_epoch()
                    releasing_at_boundary =
                        top_of_hour_id_boundary_epoch() <= 0.0
                        or seconds_past_boundary < 5.0

                    if releasing_at_boundary then
                        azuracast.discard_autodj_current_cleanly()
                        log("Top-of-Hour ID: armed clean cross boundary and discarded interrupted AutoDJ request.")
                    else
                        log(
                            "Top-of-Hour ID: lane released #{seconds_past_boundary}s after :00 "
                            ^ "(news ran long); keeping the already-resolved fresh request "
                            ^ "instead of discarding it."
                        )
                    end

                    # Fetch the request that opens the new hour NOW, so :00 does
                    # not wait out request.dynamic's 10s retry delay after the
                    # nextsong window closes. This moved here from the enter
                    # transition, where it was what caused a fresh track to be
                    # resolved and started under the ID.
                    if releasing_at_boundary then
                        azuracast.prefetch_autodj_next()
                    end

                end

                top_of_hour_id_active := false
                top_of_hour_id_hard_boundary := false
                top_of_hour_id_target_epoch := 0.0
                top_of_hour_id_boundary_epoch := 0.0

                if was_hard then
                    log("Top-of-Hour ID: HARD lane released exactly at the :00 boundary to rigid authority.")
                else
                    log("Top-of-Hour ID: open-hour lane released at the :00 boundary to a clean fresh AutoDJ start.")
                end

                new
            end

            radio = switch(
                id="top_of_hour_station_id",
                track_sensitive=false,
                replay_metadata=true,
                transition_length=0.0,
                transitions=[top_of_hour_id_enter, top_of_hour_id_exit],
                [
                    ({top_of_hour_id_should_play()}, top_of_hour_clocked_lane),
                    ({true}, radio_before_top_of_hour)
                ]
            )

            # Runtime controls use absolute epochs to avoid timezone/DST ambiguity.
            def top_of_hour_set_enabled(value) =
                top_of_hour_id_enabled := string.trim(value) == "true"
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="enabled true|false",
                description="Enable or disable automatic Top-of-Hour ID playout.",
                "enabled",
                top_of_hour_set_enabled
            )

            def top_of_hour_set_fade_seconds(value) =
                top_of_hour_id_fade_seconds := float_of_string(string.trim(value))
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="fade_seconds 1..10",
                description="Set the pre-ID fade duration.",
                "fade_seconds",
                top_of_hour_set_fade_seconds
            )

            def top_of_hour_set_release_hold(value) =
                top_of_hour_id_release_hold_seconds := float_of_string(string.trim(value))
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="release_hold 0.5..5",
                description="Seconds the underlay stays muted after the ID lane releases.",
                "release_hold",
                top_of_hour_set_release_hold
            )

            def top_of_hour_set_target_epoch(value) =
                top_of_hour_id_target_epoch := float_of_string(string.trim(value))
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="target_epoch unix_seconds",
                description="Set the exact absolute ID start deadline.",
                "target_epoch",
                top_of_hour_set_target_epoch
            )

            def top_of_hour_set_boundary_epoch(value) =
                top_of_hour_id_boundary_epoch := float_of_string(string.trim(value))
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="boundary_epoch unix_seconds",
                description="Set the absolute following hour boundary.",
                "boundary_epoch",
                top_of_hour_set_boundary_epoch
            )

            def top_of_hour_set_hard_boundary(value) =
                top_of_hour_id_hard_boundary := string.trim(value) == "true"
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="hard true|false",
                description="Set whether a rigid programme owns the following :00.",
                "hard",
                top_of_hour_set_hard_boundary
            )

            # Live authority check for NextSongCommand. The PHP-computed refusal
            # window (target..boundary) assumes the ID+news block always finishes
            # by :00, but an AI news bulletin genuinely can run past it -- see
            # top_of_hour_id_should_play() above, which keeps the lane active past
            # `boundary` for exactly that case. When PHP sees wall-clock has passed
            # `boundary` it cannot tell, on its own, whether the lane already
            # released or is still genuinely holding, so it asks here instead of
            # guessing a fixed pad (too short races the release; too long strands
            # the underlay dry with every legitimate post-release request refused).
            def top_of_hour_get_active(_) =
                if top_of_hour_id_should_play() then "true" else "false" end
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="active",
                description="Whether the Top-of-Hour ID/news lane currently owns the air.",
                "active",
                top_of_hour_get_active
            )

            def top_of_hour_clear_queue(_) =
                top_of_hour_id.skip()
                top_of_hour_id.set_queue([])
                top_of_hour_news.skip()
                top_of_hour_news.set_queue([])
                top_of_hour_id_active := false
                top_of_hour_id_hard_boundary := false
                top_of_hour_id_target_epoch := 0.0
                top_of_hour_id_boundary_epoch := 0.0
                top_of_hour_id_release_epoch := 0.0
                "Done!"
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="clear",
                description="Clear staged Top-of-Hour ID requests and timing state.",
                "clear",
                top_of_hour_clear_queue
            )
            LIQ
        );
    }
}
