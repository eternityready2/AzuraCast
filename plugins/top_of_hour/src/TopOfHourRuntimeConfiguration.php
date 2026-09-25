<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Entity\Station;
use App\Entity\StationMedia;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use App\Radio\Enums\LiquidsoapQueues;
use Doctrine\ORM\EntityManagerInterface;
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

    /** Largest pitch-preserving tempo change used to land the ID on its target. */
    public const float FIT_MAX_TEMPO_ADJUST = 0.03;

    public function __construct(
        private readonly TopOfHourClock $clock,
        private readonly EntityManagerInterface $em,
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

    /**
     * Short promos/jingles (5-30s) that are in an enabled playlist, written as a
     * Liquidsoap list of (length, request URI) for the Top-of-Hour fit.
     */
    private function getFitPromoList(Station $station): string
    {
        /** @var StationMedia[] $media */
        $media = $this->em->createQuery(
            <<<'DQL'
                SELECT sm FROM App\Entity\StationMedia sm
                WHERE sm.storage_location = :storageLocation
                AND sm.type IN (:types)
                AND sm.length >= 5 AND sm.length <= 30
                AND EXISTS (
                    SELECT spm.id FROM App\Entity\StationPlaylistMedia spm
                    JOIN spm.playlist sp
                    WHERE spm.media = sm AND sp.station = :station AND sp.is_enabled = true
                )
                ORDER BY sm.length ASC
            DQL
        )->setParameter('storageLocation', $station->media_storage_location)
            ->setParameter('types', ['promo', 'jingle'])
            ->setParameter('station', $station)
            ->setMaxResults(50)
            ->getResult();

        $items = [];
        foreach ($media as $row) {
            $annotations = ConfigWriter::annotateArray([
                'title' => $row->title ?? '',
                'artist' => $row->artist ?? '',
                'duration' => $row->length,
                'natural_length' => $row->length,
            ]);
            $items[] = '(' . ConfigWriter::toFloat($row->length, 2) . ', '
                . ConfigWriter::toRawString('annotate:' . $annotations . ':media:' . $row->path) . ')';
        }

        return implode(', ', $items);
    }

    public function writeRuntime(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $config = $event->getBackendConfig();
        $queueName = LiquidsoapQueues::TopOfHour->value;
        $fadeSeconds = ConfigWriter::toFloat($this->clock->getIdFadeSeconds($station), 1);

        $newsEnabled = ($config->ai_news_enabled && $config->ai_news_top_of_hour);
        $fitPromos = $this->getFitPromoList($station);
        $fitMaxAdjust = ConfigWriter::toFloat(self::FIT_MAX_TEMPO_ADJUST, 3);

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
            # Set by the fit on the final song: the air is covered (the song's own
            # ending, or one fill promo) until this epoch, so an idle AutoDJ
            # transport before it is expected, not a reason to start the ID early.
            top_of_hour_air_covered_until = ref(0.0)
            # The final song (or its fill) ends on the target by itself, so the
            # emergency pre-fade is not applied to it.
            top_of_hour_clean_landing = ref(false)
            # Title handling after the ID: the song that ended/was cut must not have
            # its title replayed; a held item that already began keeps its own.
            top_of_hour_last_sq = ref("")
            top_of_hour_stale_sq = ref("")
            # The stale marker is only valid for the handful of seconds around
            # the release in which the old title can be replayed (crossfade and
            # switch). Leaving it armed until the next ID meant a single armed
            # marker could still swallow a metadata packet minutes later, and a
            # swallowed packet sends no feedback at all -- now-playing then sat
            # on the previous title until some later track happened to refresh
            # it. Bound the window so suppression can never outlive the release.
            top_of_hour_stale_until = ref(0.)
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

            # Land the ID on its target (:59:59) instead of starting it early when
            # the last song before it ends short. Decided when that song starts,
            # the only moment the real remaining time is known: a pitch-preserving
            # tempo change within +/-3%, plus at most ONE short promo when the gap
            # is larger than that. Promos are never stacked. If nothing fits, the
            # ID starts early as before.
            top_of_hour_fit_tempo = ref(1.0)
            top_of_hour_fit_promos = [{$fitPromos}]

            # The final song's OVERALL tempo (upstream stretch/squeeze included),
            # held within +/-{$fitMaxAdjust} so adjustments never stack past it.
            def top_of_hour_fit_set(desired) =
                lo = 1.0 - {$fitMaxAdjust}
                hi = 1.0 + {$fitMaxAdjust}
                overall = if desired < lo then lo elsif desired > hi then hi else desired end
                upstream = clock_wheel_stretch_ratio()
                top_of_hour_fit_tempo := if upstream > 0.0 then overall / upstream else overall end
            end

            def top_of_hour_fit_on_track(m) =
                top_of_hour_fit_tempo := 1.0
                target = top_of_hour_id_target_epoch()
                cue_in = float_of_string(default=0.0, m["liq_cue_in"])
                cue_out = float_of_string(default=0.0, m["liq_cue_out"])
                cross_end = float_of_string(default=0.0, m["liq_cross_end_duration"])
                natural = float_of_string(default=0.0, m["natural_length"])
                listed = float_of_string(default=0.0, m["duration"])
                full =
                    if cue_out > cue_in then
                        cue_out - cue_in
                    elsif natural > 0.0 then
                        natural
                    else
                        listed
                    end

                # Trace every entry check while an ID target is set, so a fit
                # that does not happen says why.
                fit_remaining = target - time()
                if target > 0.0 and fit_remaining > 0.0 then
                    fit_item = m["artist"] ^ " - " ^ m["title"]
                    fit_sq = m["sq_id"] ^ "/" ^ m["media_id"]
                    fit_jingle = m["jingle_mode"]
                    fit_enabled = top_of_hour_id_enabled()
                    fit_active = top_of_hour_id_active()
                    fit_live = azuracast.live_enabled()
                    fit_ready = top_of_hour_id.is_ready()
                    log("Top-of-Hour fit check: '#{fit_item}' remaining=#{fit_remaining} full=#{full} cross_end=#{cross_end} enabled=#{fit_enabled} active=#{fit_active} live=#{fit_live} id_ready=#{fit_ready} sq_id='#{fit_sq}' jingle='#{fit_jingle}'")
                end

                # Ordinary AutoDJ songs only (a queue row with a real length).
                if
                    top_of_hour_id_enabled()
                    and not top_of_hour_id_active()
                    and not azuracast.live_enabled()
                    and target > 0.0
                    and (m["sq_id"] != "" or m["media_id"] != "")
                    and m["jingle_mode"] != "true"
                    and m["media_type"] == "music"
                    and full > 60.0
                then
                    # The song finishes (last sample, after its own fade-out) on the
                    # target, so it is never faded or cut by the ID. Transitions
                    # here play the outgoing tail in full before the next item
                    # (each track airs cue_out - cue_in, measured 2026-09-24), so a
                    # fill promo also starts at the song's last sample.
                    len = full
                    avail = target - time()
                    gap = avail - len
                    max_adj = len * {$fitMaxAdjust}
                    item = m["artist"] ^ " - " ^ m["title"]

                    # Only the final song before the ID: nothing else fits after it.
                    # The item after it is held back until the ID (PHP hand-off),
                    # so whatever gap is left here would be silence: always fill it
                    # as closely as the tempo limit and one promo allow.
                    if gap < 45.0 and gap > 0.0 - max_adj then
                        if gap <= max_adj then
                            if abs(gap) > 0.5 then
                                top_of_hour_fit_set(len / avail)
                                log("Top-of-Hour fit: '#{item}' tempo #{top_of_hour_fit_tempo()} so it ends on the ID target (gap #{gap}s).")
                            else
                                log("Top-of-Hour fit: '#{item}' already ends on the ID target (gap #{gap}s).")
                            end
                            top_of_hour_air_covered_until := target
                            top_of_hour_clean_landing := true
                        elsif list.length(requests.queue()) > 0 then
                            log("Top-of-Hour fit: #{gap}s gap after '#{item}'; the requests queue already has an item to play in it.")
                        else
                            best_len = ref(0.0)
                            best_uri = ref("")
                            list.iter(
                                fun (promo) -> begin
                                    let (promo_len, promo_uri) = promo
                                    rest = gap - promo_len
                                    if
                                        rest >= 0.0 - max_adj
                                        and rest <= max_adj
                                        and (best_len() <= 0.0 or abs(rest) < abs(gap - best_len()))
                                    then
                                        best_len := promo_len
                                        best_uri := promo_uri
                                    end
                                end,
                                top_of_hour_fit_promos
                            )
                            exact = best_len() > 0.0
                            if not exact then
                                # No exact fit: the longest promo that still fits,
                                # with the song slowed as far as allowed.
                                list.iter(
                                    fun (promo) -> begin
                                        let (promo_len, promo_uri) = promo
                                        if promo_len <= gap + max_adj and promo_len > best_len() then
                                            best_len := promo_len
                                            best_uri := promo_uri
                                        end
                                    end,
                                    top_of_hour_fit_promos
                                )
                            end
                            if best_len() > 0.0 then
                                requests.push(request.create(best_uri()))
                                song_avail = avail - best_len()
                                top_of_hour_fit_set(len / song_avail)
                                top_of_hour_air_covered_until := target
                                top_of_hour_clean_landing := exact
                                log("Top-of-Hour fit: #{gap}s gap after '#{item}'; one #{best_len()}s promo queued (exact=#{exact}), song tempo #{top_of_hour_fit_tempo()}.")
                            else
                                top_of_hour_fit_set(1.0 - {$fitMaxAdjust})
                                top_of_hour_air_covered_until := time() + len / (1.0 - {$fitMaxAdjust})
                                log("Top-of-Hour fit: #{gap}s gap after '#{item}' and no promo fits; song slowed to #{top_of_hour_fit_tempo()}.")
                            end
                        end
                    elsif gap <= 0.0 - max_adj and gap > -60.0 then
                        # Runs over the ID: speed up as far as allowed so the ID
                        # takes as little of the song's outro as possible. This is
                        # the emergency case the pre-fade exists for.
                        top_of_hour_fit_set(1.0 + {$fitMaxAdjust})
                        top_of_hour_air_covered_until := target
                        top_of_hour_clean_landing := false
                        log("Top-of-Hour fit: '#{item}' runs #{0.0 - gap}s past the ID; tempo #{top_of_hour_fit_tempo()} to shorten the overrun.")
                    end
                end
            end
            # on_track never fires on this source (not once in any hour of
            # 2026-09-24), so the fit never ran. Metadata does arrive here at
            # each track start; act once per track.
            top_of_hour_fit_seen = ref("")
            def top_of_hour_fit_on_metadata(m) =
                key = m["sq_id"] ^ "|" ^ m["media_id"] ^ "|" ^ m["title"]
                if key != "||" and key != top_of_hour_fit_seen() then
                    top_of_hour_fit_seen := key
                    top_of_hour_fit_on_track(m)
                end
            end
            source.methods(radio).on_metadata(synchronous=true, top_of_hour_fit_on_metadata)
            radio = soundtouch(id="top_of_hour_fit", tempo={top_of_hour_fit_tempo()}, radio)

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
                    and not top_of_hour_clean_landing()
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
                        # The air has actually gone quiet: start the ID rather than
                        # let the station fallback file play.
                        or not source.is_ready(radio_before_top_of_hour)
                        # AutoDJ has nothing next and nothing is covering the air.
                        # During the final approach the transport is idle by
                        # design (the next item is held for the new hour) while
                        # the song's own ending or a fill promo plays; starting the
                        # ID then cut them (8pm 2026-09-24: ID 21s early, promo
                        # muted underneath).
                        or (
                            azuracast.autodj_transport_ready()
                            and not source.methods(azuracast.autodj_transport()).is_ready()
                            and now >= top_of_hour_air_covered_until() - 0.25
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
                top_of_hour_air_covered_until := 0.0
                top_of_hour_clean_landing := false

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
                    # The song on air now is the one the ID ends. Remember it here,
                    # before the next song's metadata can arrive, so only ITS
                    # replayed title is suppressed at release (2pm 2026-09-24: it
                    # was captured at release, after the new song's metadata, so
                    # the new title was hidden and the old one was reported).
                    if not started_early then
                        top_of_hour_stale_sq := top_of_hour_last_sq()
                    end
                    azuracast.autodj_hold := true
                    if not started_early and azuracast.autodj_fresh_ready() then
                        # A clean-cut marker left armed by an earlier hour turns the
                        # discard into a no-op and the cut song carries on after the
                        # ID (2:59am 2026-09-24). This cut starts fresh.
                        azuracast.autodj_clean_cut_pending := false
                        azuracast.discard_autodj_current_cleanly()
                    elsif not azuracast.autodj_fresh_ready() then
                        # Nothing is loaded (the last song ended on its own). A
                        # held request.dynamic is not pulled, so it never asks for
                        # the next item by itself; load it now so the new hour
                        # opens at release instead of after a fetch + AutoCue
                        # (1am 2026-09-24: 5s of silence after the ID).
                        azuracast.prefetch_autodj_next()
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
                # The song to keep off the air/title was recorded at ID start.
                top_of_hour_held_started := false
                azuracast.autodj_hold := false
                if not azuracast.live_enabled() and not azuracast.autodj_fresh_ready() then
                    azuracast.prefetch_autodj_next()
                end

                top_of_hour_id_active := false
                top_of_hour_air_covered_until := 0.0
                top_of_hour_clean_landing := false

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

                # The replayed title arrives with the release (crossfade, then
                # switch). Open the suppression window here rather than at the
                # ID start, so it covers those replays and nothing later.
                top_of_hour_stale_until := time() + 20.

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
                # Armed only for the short window that opens at release: the old
                # title can be replayed more than once there (crossfade and
                # switch). Past that window the marker is dropped -- a dropped
                # metadata packet also drops its now-playing feedback, so an
                # indefinitely-armed marker could strand the overview on a stale
                # title until some later track happened to refresh it.
                if stale != "" and time() >= top_of_hour_stale_until() then
                    top_of_hour_stale_sq := ""
                    m
                elsif stale != "" and m["sq_id"] == stale then
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
