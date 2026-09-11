<template>
    <div class="playout-settings">
        <section class="behavior-section mb-4">
            <div class="behavior-heading behavior-heading-start">
                <span class="heading-icon heading-icon-start"><icon-ic-play-arrow /></span>
                <span>
                    <span class="heading-title-with-help">
                        <strong>{{ $gettext('2. How should it start?') }}</strong>
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
                    <small>{{ $gettext('Choose what happens when the scheduled start time arrives.') }}</small>
                </span>
            </div>

            <div
                v-if="!hasSchedule"
                class="alert alert-warning py-2 mx-3 mt-3 mb-0"
            >
                {{ $gettext('Add a schedule above first. Start behavior does not create a schedule by itself.') }}
            </div>

            <div class="choice-grid choice-grid-start p-3">
                <label
                    v-for="option in startBehaviorOptions"
                    :key="option.value"
                    class="choice-option"
                    :class="{'is-active': startBehavior === option.value}"
                >
                    <input
                        v-model="startBehavior"
                        class="form-check-input"
                        type="radio"
                        :value="option.value"
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
                            v-if="option.recommended"
                            class="recommended-badge"
                        >
                            {{ $gettext('Recommended for shows') }}
                        </span>
                    </span>
                </label>
            </div>

            <div class="behavior-note mx-3 mb-3">
                {{ $gettext('Scheduling Mode above is per scheduled time. Flexible leaves this playlist-wide Start Behavior in control. Strict / Exact Time adds an exact-start override for only that schedule row, even if Rotation is selected here.') }}
            </div>
        </section>

        <section class="behavior-section mb-4">
            <div class="behavior-heading behavior-heading-end">
                <span class="heading-icon heading-icon-end"><icon-ic-stop /></span>
                <span>
                    <span class="heading-title-with-help">
                        <strong>{{ $gettext('3. What should happen at the end?') }}</strong>
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
                    <small>{{ $gettext('The end behavior is paired with the selected start style so Liquidsoap follows the setting reliably.') }}</small>
                </span>
            </div>

            <div
                v-if="hasSchedule && unsupportedCombination"
                class="alert alert-warning py-2 mx-3 mt-3 mb-0"
            >
                {{ $gettext('This playlist contains a legacy start/end combination that Liquidsoap cannot honor reliably. Choose a start behavior or Quick Setup preset to normalize it.') }}
            </div>

            <div class="choice-grid choice-grid-end p-3">
                <label
                    v-for="option in endBehaviorOptions"
                    :key="option.value"
                    class="choice-option"
                    :class="{
                        'is-active': endBehavior === option.value,
                        'is-disabled': isEndOptionDisabled(option.value)
                    }"
                >
                    <input
                        v-model="endBehavior"
                        class="form-check-input"
                        type="radio"
                        :value="option.value"
                        :disabled="isEndOptionDisabled(option.value)"
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
                    </span>
                </label>
            </div>

            <div class="behavior-note mx-3 mb-3">
                {{ startBehavior === 'wait'
                    ? $gettext('Rotation waits for the current song at the start, so the current item is also allowed to finish at the end. A Strict / Exact Time schedule row can still override the start time for that row.')
                    : $gettext('Programme and Priority starts use exact playlist boundaries, so they return at the scheduled end time.')
                }}
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
                    <small>{{ $gettext('Additional settings for unusual playlist behavior, listener-request overrides, or sponsor tracking.') }}</small>
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
                        <small>{{ $gettext('Give this playlist priority over automatic listener requests. Priority / News enables this automatically.') }}</small>
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
import {useTranslate} from "~/vendor/gettext";

withDefaults(defineProps<{
    hasSchedule?: boolean,
}>(), {
    hasSchedule: false,
});

const {$gettext} = useTranslate();
const {form, r$} = storeToRefs(useStationsPlaylistsForm());

const startHelp = $gettext('This is a playlist-wide AutoDJ behavior. Flexible schedule rows follow this choice. Strict / Exact Time rows add a per-row exact-start override without removing this setting.');
const endHelp = $gettext('End behavior is playlist-wide. It controls whether the current scheduled item stops at the boundary or is allowed to finish naturally.');
const advancedHelp = $gettext('These options remain independent. Scheduling Mode does not remove Only Play One Track, Merge, request priority, or sponsor controls.');

const hasOption = (option: string) => form.value.backend_options.includes(option);

const setOption = (option: string, enabled: boolean) => {
    const options = form.value.backend_options.filter((item) => item !== option);

    if (enabled) {
        options.push(option);
    }

    form.value.backend_options = options;
};

const startBehavior = computed({
    get: (): 'wait' | 'scheduled' | 'priority' => {
        if (hasOption('interrupt') && hasOption('prioritize')) {
            return 'priority';
        }

        if (hasOption('interrupt')) {
            return 'scheduled';
        }

        return 'wait';
    },
    set: (value: 'wait' | 'scheduled' | 'priority') => {
        setOption('interrupt', value !== 'wait');
        setOption('prioritize', value === 'priority');

        // Liquidsoap's native schedule switch uses one track-sensitivity mode
        // for both entry and exit. Keep the UI on combinations the backend can
        // honor deterministically: Rotation = natural boundaries; Programme /
        // Priority = hard boundaries.
        setOption('allow_overrun', value === 'wait');
    },
});

const endBehavior = computed({
    get: (): 'boundary' | 'finish' => hasOption('allow_overrun') ? 'finish' : 'boundary',
    set: (value: 'boundary' | 'finish') => {
        if (!isEndOptionDisabled(value)) {
            setOption('allow_overrun', value === 'finish');
        }
    },
});

const unsupportedCombination = computed(() => (
    hasOption('interrupt') === hasOption('allow_overrun')
));

const isEndOptionDisabled = (value: 'boundary' | 'finish'): boolean => {
    return startBehavior.value === 'wait'
        ? value === 'boundary'
        : value === 'finish';
};

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

const startBehaviorOptions = [
    {
        value: 'scheduled',
        title: $gettext('Start at scheduled time (Programme)'),
        description: $gettext('Interrupt normal rotation when the schedule begins. Best for regular shows and prerecorded programmes.'),
        help: $gettext('Programme is the playlist-wide interrupt option. It starts this playlist when an active schedule begins. Strict / Exact Time is a separate per-schedule override.'),
        recommended: true,
    },
    {
        value: 'wait',
        title: $gettext('Wait for current song (Rotation)'),
        description: $gettext('Do not interrupt normal playback. Start after the current song finishes. Best for music rotation blocks.'),
        help: $gettext('Rotation is the normal non-interrupting start. If an individual schedule row is set to Strict / Exact Time, that row can still force an exact start.'),
        recommended: false,
    },
    {
        value: 'priority',
        title: $gettext('Priority Start (News / Alert)'),
        description: $gettext('Start on schedule and also override listener requests. Best for news, alerts and time-sensitive content.'),
        help: $gettext('Priority combines an interrupting scheduled start with priority over automatic listener requests.'),
        recommended: false,
    },
];

const endBehaviorOptions: Array<{
    value: 'boundary' | 'finish',
    title: string,
    description: string,
    help: string,
}> = [
    {
        value: 'boundary',
        title: $gettext('Stop at scheduled time'),
        description: $gettext('Return to normal programming at the scheduled end boundary. Used with Programme and Priority starts.'),
        help: $gettext('This is the firm-end behavior. Liquidsoap returns to normal programming when the schedule window ends.'),
    },
    {
        value: 'finish',
        title: $gettext('Let current item finish (Allow Overrun)'),
        description: $gettext('Let the current track finish naturally before returning to normal programming. Used with Rotation starts.'),
        help: $gettext('Allow Overrun prevents the schedule boundary from cutting the current item at the end of the window.'),
    },
];
</script>

<style scoped>
.playout-settings {
    padding-top: .25rem;
}

.behavior-section {
    overflow: hidden;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: var(--bs-body-bg);
}

.behavior-heading {
    display: flex;
    align-items: center;
    gap: .85rem;
    padding: .9rem 1rem;
}

.behavior-heading-start {
    border-bottom: 1px solid rgba(25, 135, 84, .18);
    background: rgba(25, 135, 84, .12);
    color: #21a45f;
}

.behavior-heading-end {
    border-bottom: 1px solid rgba(220, 53, 69, .18);
    background: rgba(220, 53, 69, .12);
    color: #e54859;
}

.heading-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.7rem;
    height: 2.7rem;
    flex: 0 0 2.7rem;
    border-radius: .55rem;
    color: #fff;
    font-size: 1.55rem;
}

.heading-icon-start {
    background: #198754;
}

.heading-icon-end {
    background: #dc3545;
}

.heading-title-with-help,
.option-title-with-help {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
}

.info-help {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.3rem;
    height: 1.3rem;
    flex: 0 0 1.3rem;
    border-radius: 50%;
    color: #2688ff;
    cursor: help;
    font-size: 1rem;
    line-height: 1;
}

.info-help-small {
    width: 1.15rem;
    height: 1.15rem;
    flex-basis: 1.15rem;
    font-size: .9rem;
}

.info-help:focus {
    outline: 2px solid rgba(38, 136, 255, .45);
    outline-offset: 2px;
}

.behavior-heading strong,
.behavior-heading small,
.option-copy strong,
.option-copy small,
.behavior-option strong,
.behavior-option small,
.advanced-box summary strong,
.advanced-box summary small {
    display: block;
}

.behavior-heading strong {
    font-size: 1.08rem;
}

.behavior-heading small {
    margin-top: .15rem;
    color: var(--bs-secondary-color);
    font-size: .84rem;
}

.choice-grid {
    display: grid;
    gap: .75rem;
}

.choice-grid-start {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.choice-grid-end {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.choice-option,
.behavior-option {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    padding: .95rem;
    margin: 0;
    border: 1px solid var(--bs-border-color);
    border-radius: .65rem;
    background: var(--bs-tertiary-bg);
    cursor: pointer;
}

.choice-option.is-active,
.behavior-option.is-active {
    border-color: #2688ff;
    background: rgba(38, 136, 255, .10);
    box-shadow: 0 0 0 .1rem rgba(38, 136, 255, .14);
}

.choice-option.is-disabled {
    opacity: .5;
    cursor: not-allowed;
}

.choice-option input,
.behavior-option input {
    margin-top: .2rem;
}

.option-copy strong,
.behavior-option strong {
    font-size: .92rem;
}

.option-copy small,
.behavior-option small {
    margin-top: .2rem;
    color: var(--bs-secondary-color);
    font-size: .82rem;
    line-height: 1.45;
}

.recommended-badge {
    display: inline-block;
    margin-top: .45rem;
    padding: .18rem .5rem;
    border-radius: 999px;
    background: rgba(25, 135, 84, .18);
    color: #24ab65;
    font-size: .7rem;
    font-weight: 700;
}

.behavior-note {
    color: var(--bs-secondary-color);
    font-size: .8rem;
    line-height: 1.45;
}

.advanced-box {
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: var(--bs-tertiary-bg);
    overflow: hidden;
}

.advanced-box summary {
    display: flex;
    align-items: center;
    gap: .8rem;
    padding: .95rem 1rem;
    cursor: pointer;
}

.advanced-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    flex: 0 0 2.5rem;
    border-radius: 50%;
    background: rgba(108, 117, 125, .16);
    color: var(--bs-secondary-color);
    font-size: 1.45rem;
}

.advanced-box summary strong {
    font-size: .96rem;
}

.advanced-box summary small {
    margin-top: .15rem;
    color: var(--bs-secondary-color);
    font-size: .8rem;
    font-weight: 400;
}

.advanced-body {
    display: grid;
    gap: .65rem;
    padding: 0 1rem 1rem;
}

.sponsor-toggle {
    margin-top: .2rem;
}

@media (max-width: 1199.98px) {
    .choice-grid-start,
    .choice-grid-end {
        grid-template-columns: 1fr;
    }
}
</style>