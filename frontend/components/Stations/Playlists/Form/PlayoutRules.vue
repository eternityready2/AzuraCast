<template>
    <div class="playout-settings">
        <section class="behavior-recommendation mb-4">
            <div class="recommendation-copy">
                <span class="recommendation-kicker">{{ $gettext('Playlist Behavior Recommendation') }}</span>
                <strong>{{ detectedBehaviorLabel }}</strong>
                <small>{{ detectedBehavior.reason }}</small>
                <small class="recommendation-safety">
                    {{ $gettext('This is a recommendation only. It never changes a saved playlist unless you click Apply Recommendation.') }}
                </small>
            </div>
            <button
                type="button"
                class="btn btn-sm"
                :class="recommendationApplied ? 'btn-outline-success' : 'btn-primary'"
                :disabled="recommendationApplied"
                @click="applyRecommendation"
            >
                {{ recommendationApplied ? $gettext('Already Applied') : $gettext('Apply Recommendation') }}
            </button>
        </section>

        <section class="behavior-section mb-4">
            <div class="behavior-heading behavior-heading-start">
                <span class="heading-icon heading-icon-start"><icon-ic-play-arrow /></span>
                <span>
                    <span class="heading-title-with-help">
                        <strong>{{ $gettext('2. How should Flexible schedules start?') }}</strong>
                        <span
                            class="info-help"
                            tabindex="0"
                            role="img"
                            :aria-label="startHelp"
                            :title="startHelp"
                        >
                            <icon-ic-info />
                        </span>
                    </span>
                    <small>{{ $gettext('This playlist-wide choice applies to Flexible schedule rows only.') }}</small>
                </span>
            </div>

            <div
                v-if="!hasSchedule"
                class="alert alert-warning py-2 mx-3 mt-3 mb-0"
            >
                {{ $gettext('Add a schedule above first. Start behavior does not create a schedule by itself.') }}
            </div>

            <div class="choice-grid p-3">
                <label
                    v-for="option in startBehaviorOptions"
                    :key="option.value"
                    class="choice-option"
                    :class="{'is-active': startBehavior === option.value}"
                >
                    <input
                        class="form-check-input"
                        type="radio"
                        :value="option.value"
                        :checked="startBehavior === option.value"
                        @change="applyStartBehavior(option.value)"
                    >
                    <span class="option-copy">
                        <span class="option-title-with-help">
                            <strong>{{ option.title }}</strong>
                            <span
                                class="info-help info-help-small"
                                tabindex="0"
                                role="img"
                                :aria-label="option.help"
                                :title="option.help"
                            >
                                <icon-ic-info />
                            </span>
                        </span>
                        <small>{{ option.description }}</small>
                        <span
                            v-if="detectedStartBehavior === option.value"
                            class="recommended-badge"
                        >
                            {{ $gettext('Recommended') }}
                        </span>
                    </span>
                </label>
            </div>

            <div class="behavior-note mx-3 mb-3">
                {{ $gettext('Strict / Exact Time is controlled on each schedule row. A Strict row always gets exact wall-clock authority and is never changed by this Flexible start setting.') }}
            </div>
        </section>

        <section class="behavior-section mb-4">
            <div class="behavior-heading behavior-heading-end">
                <span class="heading-icon heading-icon-end"><icon-ic-stop /></span>
                <span>
                    <span class="heading-title-with-help">
                        <strong>{{ $gettext('3. End behavior') }}</strong>
                        <span
                            class="info-help"
                            tabindex="0"
                            role="img"
                            :aria-label="endHelp"
                            :title="endHelp"
                        >
                            <icon-ic-info />
                        </span>
                    </span>
                    <small>{{ $gettext('The compatible end behavior follows your Flexible start choice automatically.') }}</small>
                </span>
            </div>

            <div class="end-summary p-3">
                <strong>{{ endBehaviorTitle }}</strong>
                <small>{{ endBehaviorDescription }}</small>
            </div>

            <div class="behavior-note mx-3 mb-3">
                {{ $gettext('Strict / Exact Time rows use their exact schedule boundary. There is no disabled or hidden Strict setting here to fight with the schedule row.') }}
            </div>
        </section>

        <details class="advanced-box">
            <summary>
                <span class="advanced-icon"><icon-ic-settings /></span>
                <span>
                    <span class="heading-title-with-help">
                        <strong>{{ $gettext('Special / Advanced Options (Optional)') }}</strong>
                        <span
                            class="info-help"
                            tabindex="0"
                            role="img"
                            :aria-label="advancedHelp"
                            :title="advancedHelp"
                        >
                            <icon-ic-info />
                        </span>
                    </span>
                    <small>{{ $gettext('Additional controls for one-track playback, merging, listener requests, or sponsor tracking.') }}</small>
                </span>
            </summary>

            <div class="advanced-body">
                <label class="behavior-option" :class="{'is-active': singleTrack}">
                    <input v-model="singleTrack" class="form-check-input" type="checkbox">
                    <span>
                        <strong>{{ $gettext('Only Play One Track') }}</strong>
                        <small>{{ $gettext('At each eligible play, use one track from this playlist instead of running the whole playlist block.') }}</small>
                    </span>
                </label>

                <label class="behavior-option" :class="{'is-active': mergeTracks}">
                    <input v-model="mergeTracks" class="form-check-input" type="checkbox">
                    <span>
                        <strong>{{ $gettext('Merge All Tracks') }}</strong>
                        <small>{{ $gettext('Treat all tracks in this playlist as one continuous block. Useful for multi-part programmes and long-form content.') }}</small>
                    </span>
                </label>

                <label class="behavior-option" :class="{'is-active': prioritizeRequests}">
                    <input v-model="prioritizeRequests" class="form-check-input" type="checkbox">
                    <span>
                        <strong>{{ $gettext('Override Listener Requests') }}</strong>
                        <small>{{ $gettext('Give this playlist priority over automatic listener requests.') }}</small>
                    </span>
                </label>

                <label class="behavior-option sponsor-toggle" :class="{'is-active': form.is_sponsor}">
                    <input v-model="form.is_sponsor" class="form-check-input" type="checkbox">
                    <span>
                        <strong>{{ $gettext('Sponsor / Paid Ad Spot') }}</strong>
                        <small>{{ $gettext('Track this playlist in the Sponsor Play Report and optionally guarantee a minimum number of daily plays.') }}</small>
                    </span>
                </label>

                <div v-if="form.is_sponsor" class="row g-3 mt-1">
                    <form-group-field
                        id="edit_form_sponsor_name"
                        class="col-md-6"
                        :field="r$.sponsor_name"
                        :label="$gettext('Sponsor Name')"
                        :description="$gettext('Shown on the Sponsor Play Report. Defaults to the playlist name if left blank.')"
                    />

                    <form-group-field
                        id="edit_form_sponsor_guaranteed_plays_per_day"
                        class="col-md-6"
                        :field="r$.sponsor_guaranteed_plays_per_day"
                        type="number"
                        :label="$gettext('Guaranteed Plays Per Day')"
                        :description="$gettext('Minimum number of times this sponsor spot should air each day. Leave empty to track plays without enforcing a minimum.')"
                    />
                </div>
            </div>
        </details>
    </div>
</template>

<script setup lang="ts">
import {computed} from "vue";
import {storeToRefs} from "pinia";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import IconIcInfo from "~icons/ic/baseline-info";
import IconIcPlayArrow from "~icons/ic/baseline-play-arrow";
import IconIcSettings from "~icons/ic/baseline-settings";
import IconIcStop from "~icons/ic/baseline-stop";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form";
import {detectPlaylistBehavior} from "~/functions/playlistBehaviorDetector";
import {useTranslate} from "~/vendor/gettext";

type StartBehavior = 'wait' | 'scheduled' | 'priority';

const props = withDefaults(defineProps<{
    hasSchedule?: boolean,
}>(), {
    hasSchedule: false,
});

const {$gettext} = useTranslate();
const {form, r$} = storeToRefs(useStationsPlaylistsForm());

const startHelp = $gettext('Flexible schedule rows use this playlist-wide start behavior. Strict / Exact Time is selected separately on each schedule row and always overrides this setting for that row.');
const endHelp = $gettext('AzuraCast uses compatible start/end pairs. Rotation allows the current item to finish; Programme and Priority return at the scheduled boundary. Strict rows use their exact boundary independently.');
const advancedHelp = $gettext('These options are saved exactly as you select them. No automatic process will change them when you reopen the playlist.');

const hasOption = (option: string) => form.value.backend_options.includes(option);

const setOption = (option: string, enabled: boolean) => {
    const options = form.value.backend_options.filter((item) => item !== option);
    if (enabled) {
        options.push(option);
    }
    form.value.backend_options = options;
};

const applyStartBehavior = (value: StartBehavior) => {
    setOption('interrupt', value !== 'wait');
    setOption('prioritize', value === 'priority');
    setOption('allow_overrun', value === 'wait');
};

const startBehavior = computed<StartBehavior>(() => {
    if (hasOption('interrupt') && hasOption('prioritize')) {
        return 'priority';
    }
    if (hasOption('interrupt')) {
        return 'scheduled';
    }
    return 'wait';
});

const detectedBehavior = computed(() => detectPlaylistBehavior({
    name: form.value.name,
    description: form.value.description,
    hasSchedule: props.hasSchedule,
    scheduleItems: form.value.schedule_items,
}));

const detectedStartBehavior = computed<StartBehavior>(() => {
    switch (detectedBehavior.value.behavior) {
        case 'priority':
            return 'priority';
        case 'programme':
            return 'scheduled';
        default:
            return 'wait';
    }
});

const detectedBehaviorLabel = computed(() => {
    switch (detectedBehavior.value.behavior) {
        case 'priority':
            return $gettext('News / Alert / Priority');
        case 'programme':
            return $gettext('Scheduled Show / Programme');
        default:
            return $gettext('Music Rotation Block');
    }
});

const recommendationApplied = computed(() => startBehavior.value === detectedStartBehavior.value);
const applyRecommendation = () => applyStartBehavior(detectedStartBehavior.value);

const endBehaviorTitle = computed(() => startBehavior.value === 'wait'
    ? $gettext('Let the current item finish')
    : $gettext('Return at the scheduled boundary'));
const endBehaviorDescription = computed(() => startBehavior.value === 'wait'
    ? $gettext('Rotation keeps natural song boundaries for Flexible schedule rows.')
    : $gettext('Programme and Priority use a firm end boundary for Flexible schedule rows.'));

const prioritizeRequests = computed({
    get: () => hasOption('prioritize'),
    set: (value: boolean) => setOption('prioritize', value),
});

const singleTrack = computed({
    get: () => hasOption('single_track'),
    set: (value: boolean) => setOption('single_track', value),
});

const mergeTracks = computed({
    get: () => hasOption('merge'),
    set: (value: boolean) => setOption('merge', value),
});

const startBehaviorOptions: Array<{
    value: StartBehavior;
    title: string;
    description: string;
    help: string;
}> = [
    {
        value: 'scheduled',
        title: $gettext('Start at scheduled time (Programme)'),
        description: $gettext('Interrupt normal rotation when a Flexible schedule begins. Best for regular shows and prerecorded programmes.'),
        help: $gettext('Programme is a playlist-wide behavior for Flexible rows. It does not turn a Flexible row into Strict / Exact Time.'),
    },
    {
        value: 'wait',
        title: $gettext('Wait for current song (Rotation)'),
        description: $gettext('Do not interrupt normal playback on Flexible rows. Start after the current song finishes. Best for music blocks.'),
        help: $gettext('If an individual schedule row is Strict / Exact Time, that row still starts exactly on time even when Rotation is selected here.'),
    },
    {
        value: 'priority',
        title: $gettext('Priority Start (News / Alert)'),
        description: $gettext('Interrupt on a Flexible schedule and also override listener requests. Best for time-sensitive content.'),
        help: $gettext('Priority combines the Programme start with priority over automatic listener requests for Flexible rows.'),
    },
];
</script>

<style scoped>
.playout-settings{padding-top:.25rem}
.behavior-recommendation{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem;border:1px solid rgba(38,136,255,.35);border-radius:.75rem;background:rgba(38,136,255,.08)}
.recommendation-copy{min-width:0}.recommendation-copy strong,.recommendation-copy small,.recommendation-kicker{display:block}.recommendation-kicker{margin-bottom:.15rem;color:#2688ff;font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}.recommendation-copy strong{font-size:1rem}.recommendation-copy small{margin-top:.2rem;color:var(--bs-secondary-color);line-height:1.4}.recommendation-safety{font-weight:600}
.behavior-section{overflow:hidden;border:1px solid var(--bs-border-color);border-radius:.75rem;background:var(--bs-body-bg)}
.behavior-heading{display:flex;align-items:center;gap:.85rem;padding:.9rem 1rem}.behavior-heading-start{border-bottom:1px solid rgba(25,135,84,.18);background:rgba(25,135,84,.12);color:#21a45f}.behavior-heading-end{border-bottom:1px solid rgba(220,53,69,.18);background:rgba(220,53,69,.12);color:#e54859}
.heading-icon{display:inline-flex;align-items:center;justify-content:center;width:2.7rem;height:2.7rem;flex:0 0 2.7rem;border-radius:.55rem;color:#fff;font-size:1.55rem}.heading-icon-start{background:#198754}.heading-icon-end{background:#dc3545}
.heading-title-with-help,.option-title-with-help{display:inline-flex;align-items:center;gap:.4rem}.info-help{display:inline-flex;align-items:center;justify-content:center;width:1.3rem;height:1.3rem;flex:0 0 1.3rem;border-radius:50%;color:#2688ff;cursor:help;font-size:1rem;line-height:1}.info-help-small{width:1.15rem;height:1.15rem;flex-basis:1.15rem;font-size:.9rem}.info-help:focus{outline:2px solid rgba(38,136,255,.45);outline-offset:2px}
.behavior-heading strong,.behavior-heading small,.option-copy strong,.option-copy small,.behavior-option strong,.behavior-option small,.advanced-box summary strong,.advanced-box summary small,.end-summary strong,.end-summary small{display:block}.behavior-heading strong{font-size:1.08rem}.behavior-heading small{margin-top:.15rem;color:var(--bs-secondary-color);font-size:.84rem}
.choice-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem}.choice-option,.behavior-option{display:flex;align-items:flex-start;gap:.75rem;padding:.95rem;margin:0;border:1px solid var(--bs-border-color);border-radius:.65rem;background:var(--bs-tertiary-bg);cursor:pointer}.choice-option.is-active,.behavior-option.is-active{border-color:#2688ff;background:rgba(38,136,255,.10);box-shadow:0 0 0 .1rem rgba(38,136,255,.14)}.choice-option input,.behavior-option input{margin-top:.2rem}.option-copy strong,.behavior-option strong{font-size:.92rem}.option-copy small,.behavior-option small{margin-top:.2rem;color:var(--bs-secondary-color);font-size:.82rem;line-height:1.45}
.recommended-badge{display:inline-block;margin-top:.45rem;padding:.18rem .5rem;border-radius:999px;background:rgba(25,135,84,.18);color:#24ab65;font-size:.7rem;font-weight:700}.behavior-note{color:var(--bs-secondary-color);font-size:.8rem;line-height:1.45}.end-summary strong{font-size:.95rem}.end-summary small{margin-top:.25rem;color:var(--bs-secondary-color)}
.advanced-box{border:1px solid var(--bs-border-color);border-radius:.75rem;background:var(--bs-tertiary-bg);overflow:hidden}.advanced-box summary{display:flex;align-items:center;gap:.8rem;padding:.95rem 1rem;cursor:pointer}.advanced-icon{display:inline-flex;align-items:center;justify-content:center;width:2.5rem;height:2.5rem;flex:0 0 2.5rem;border-radius:50%;background:rgba(108,117,125,.16);color:var(--bs-secondary-color);font-size:1.45rem}.advanced-box summary strong{font-size:.96rem}.advanced-box summary small{margin-top:.15rem;color:var(--bs-secondary-color);font-size:.8rem;font-weight:400}.advanced-body{display:grid;gap:.65rem;padding:0 1rem 1rem}.sponsor-toggle{margin-top:.2rem}
@media(max-width:1199.98px){.choice-grid{grid-template-columns:1fr}}@media(max-width:767.98px){.behavior-recommendation{align-items:flex-start;flex-direction:column}}
</style>