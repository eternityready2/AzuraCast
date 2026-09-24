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
    public const float EARLY_ID_WINDOW_SECONDS = 30.0;

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

    private function earlyWindowSeconds(): string
    {
        return ConfigWriter::toFloat(self::EARLY_ID_WINDOW_SECONDS, 1);
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
                # Also on a HARD hour: a scheduled programme waits for the news
                # to finish (the strict lane is gated on the TOH lane).
                if is_within_active_hours() then
                    top_of_hour_news.push(request.create(news_bulletin_request))
                    log("Top-of-Hour ID: staged top-hour AI News to lead the new hour.")
                else
                    log("Top-of-Hour ID: AI News skipped - outside active hours window.")
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

            # Nothing may START in the final seconds before the ID only to be cut
            # by it. If the underlay begins an item that cannot finish before the
            # target, or runs dry, inside this window, the ID takes the air now.
            top_of_hour_id_early_window_seconds = ref({$this->earlyWindowSeconds()})
            top_of_hour_id_early = ref(false)
            # Title handling after the ID: the song that ended/was cut must not have
            # its title replayed; a held item that already began keeps its own.
            top_of_hour_last_sq = ref("")
            top_of_hour_stale_sq = ref("")
            top_of_hour_held_started = ref(false)

            def top_of_hour_id_in_early_window() =
                target = top_of_hour_id_target_epoch()
                remaining = target - time()
                top_of_hour_id_enabled()
                and not top_of_hour_id_active()
                and not azuracast.live_enabled()
                and target > 0.0
                and remaining > 0.0
                and remaining <= top_of_hour_id_early_window_seconds()
                and top_of_hour_id.is_ready()
            end

            def top_of_hour_id_guard_on_track(m) =
                if top_of_hour_id_in_early_window() then
                    remaining = top_of_hour_id_target_epoch() - time()
                    natural = float_of_string(default=0.0, m["natural_length"])
                    is_jingle = m["jingle_mode"] == "true"
                    # `duration` may be a wall-clock cap, not the real length, so only
                    # a short-form item may fall back to it; music without a known
                    # real length never starts inside the window.
                    length =
                        if natural > 0.0 then
                            natural
                        elsif is_jingle then
                            float_of_string(default=0.0, m["duration"])
                        else
                            0.0
                        end
                    if length <= 0.0 or length > remaining + 1.0 then
                        top_of_hour_id_early := true
                        top_of_hour_held_started := true
                        azuracast.autodj_hold := true
                        item = m["artist"] ^ " - " ^ m["title"]
                        log(
                            "Top-of-Hour ID: '#{item}' (#{length}s) started "
                            ^ "#{remaining}s before the ID and cannot finish; starting the ID now."
                        )
                    end
                end
            end
            source.methods(radio).on_track(synchronous=true, top_of_hour_id_guard_on_track)

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
                rigid_schedule_toh_lane_owns_air := true
                # request.queue removes the active request from its waiting queue;
                # purge any remaining staged tail so one deadline cannot double-ID.
                top_of_hour_id.set_queue([])
                log("Top-of-Hour ID: Station ID is on air; cleared duplicate staged tail.")
            end
            source.methods(top_of_hour_id).on_track(synchronous=false, top_of_hour_id_on_track)

            # Resolve a request without playing it so AutoCue is computed and cached
            # ahead of time; the item that opens the hour then starts instantly.
            def top_of_hour_autocue_warm(uri) =
                def warm() =
                    r = request.create(uri)
                    if request.resolve(timeout=60., r) then
                        log("Top-of-Hour ID: pre-analysed the next item for an instant hour start.")
                    else
                        log("Top-of-Hour ID: could not pre-analyse the next item.")
                    end
                    request.destroy(r)
                end
                thread.run(fast=false, warm)
                "OK"
            end
            server.register(
                description="Pre-compute AutoCue for a request URI without playing it.",
                usage="autocue_warm <uri>",
                "autocue_warm",
                top_of_hour_autocue_warm
            )

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
                # Safety net: the AutoDJ hold only ever lives while the ID owns (or is
                # about to own) the air, and never over a live DJ.
                if
                    azuracast.autodj_hold()
                    and (azuracast.live_enabled() or (not top_of_hour_id_active() and not top_of_hour_id_early()))
                then
                    azuracast.autodj_hold := false
                end

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
            source.methods(radio_before_top_of_hour).on_metadata(
                synchronous=true,
                fun (m) -> top_of_hour_last_sq := m["sq_id"]
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
                elsif
                    top_of_hour_id_in_early_window()
                    and (
                        top_of_hour_id_early()
                        or (
                            azuracast.autodj_transport_ready()
                            and not source.methods(azuracast.autodj_transport()).is_ready()
                        )
                    )
                then
                    boundary > target
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
                started_early = top_of_hour_id_early()
                top_of_hour_id_active := true
                rigid_schedule_toh_lane_owns_air := true
                top_of_hour_id_early := false
                top_of_hour_id_release_epoch := 0.0

                # Do NOT discard the AutoDJ here. The muted underlay keeps whatever
                # is on air clocked (and inaudible) through the ID; discarding now
                # would make the crossfade fetch the next song and advance it under
                # the ID so it surfaced mid-song at :00. The discard happens at
                # release instead.
                {$newsStaging}

                if not azuracast.live_enabled() then
                    # Hold AutoDJ: nothing it has loaded may play (not even muted)
                    # until the ID/news releases. Only the song the ID interrupts is
                    # ended; if the ID was started early because a new item just
                    # began, that item is simply held at its start instead.
                    azuracast.autodj_hold := true
                    if not started_early and azuracast.autodj_fresh_ready() then
                        azuracast.discard_autodj_current_cleanly()
                    end
                    log("Top-of-Hour ID: AutoDJ held; the next item waits unplayed until the ID/news ends.")
                end

                # nextsong is refused while this lane owns the air; retry fast so
                # the new hour starts within ~1s of release, not up to 10s later.
                azuracast.autodj_retry_delay := 1.

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
                # AutoDJ was held (not clocked) through the lane, so there is no
                # interrupted tail to hide; muting here would clip the held item.
                top_of_hour_id_release_epoch := 0.0

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

                # Release the hold: the item that waited (the one shown as Playing
                # Next) now starts from its beginning with its own metadata.
                if not top_of_hour_held_started() then
                    top_of_hour_stale_sq := top_of_hour_last_sq()
                end
                top_of_hour_held_started := false
                azuracast.autodj_hold := false
                if not azuracast.live_enabled() and not azuracast.autodj_fresh_ready() then
                    azuracast.prefetch_autodj_next()
                end

                top_of_hour_id_active := false

                rigid_schedule_toh_lane_owns_air := false
                top_of_hour_id_early := false
                top_of_hour_id_hard_boundary := false
                top_of_hour_id_target_epoch := 0.0
                top_of_hour_id_boundary_epoch := 0.0

                if was_hard then
                    log("Top-of-Hour ID: HARD lane released exactly at the :00 boundary to rigid authority.")
                else
                    log("Top-of-Hour ID: open-hour lane released; held AutoDJ item resumes from its start.")
                end

                azuracast.autodj_retry_delay := 10.

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

            def top_of_hour_drop_stale_title(m) =
                stale = top_of_hour_stale_sq()
                if stale != "" and m["sq_id"] == stale then
                    top_of_hour_stale_sq := ""
                    log("Top-of-Hour ID: suppressed the replayed title of the song that ended before the ID.")
                    []
                else
                    m
                end
            end
            radio = metadata.map(
                id="top_of_hour_stale_title",
                update=false,
                strip=true,
                top_of_hour_drop_stale_title,
                radio
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
            def top_of_hour_get_held(_) =
                if azuracast.autodj_hold() then "true" else "false" end
            end
            server.register(
                namespace="top_of_hour_id_control",
                usage="held",
                description="Whether AutoDJ is held (not pulled) under the Top-of-Hour lane.",
                "held",
                top_of_hour_get_held
            )

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
                rigid_schedule_toh_lane_owns_air := false
                top_of_hour_id_early := false
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
