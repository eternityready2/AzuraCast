<template>
    <div class="playout-settings">
        <section class="behavior-section mb-4">
            <div class="behavior-heading behavior-heading-start">
                <span class="heading-icon">▶</span>
                <span>
                    <strong>{{ $gettext('2. How should it start?') }}</strong>
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
                        <strong>{{ option.title }}</strong>
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
                {{ $gettext('Scheduling Mode above is set per scheduled time. Start Behavior here applies to this playlist whenever one of its schedules becomes active.') }}
            </div>
        </section>

        <section class="behavior-section mb-4">
            <div class="behavior-heading behavior-heading-end">
                <span class="heading-icon">■</span>
                <span>
                    <strong>{{ $gettext('3. What should happen at the end?') }}</strong>
                    <small>{{ $gettext('The end behavior is paired with the selected start style so Liquidsoap follows the setting reliably.') }}</small>
                </span>
            </div>

            <div
                v-if="unsupportedCombination"
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
                        <strong>{{ option.title }}</strong>
                        <small>{{ option.description }}</small>
                    </span>
                </label>
            </div>

            <div class="behavior-note mx-3 mb-3">
                {{ startBehavior === 'wait'
                    ? $gettext('Rotation waits for the current song at the start, so the current item is also allowed to finish at the end.')
                    : $gettext('Programme and Priority starts use exact schedule boundaries, so they return at the scheduled end time.')
                }}
            </div>
        </section>

        <details class="advanced-box">
            <summary>
                <span class="advanced-icon">⚙</span>
                <span>
                    <strong>{{ $gettext('Special / Advanced Options (Optional)') }}</strong>
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
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form";
import {useTranslate} from "~/vendor/gettext";

withDefaults(defineProps<{
    hasSchedule?: boolean,
}>(), {
    hasSchedule: false,
});

const {$gettext} = useTranslate();
const {form, r$} = storeToRefs(useStationsPlaylistsForm());

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
        recommended: true,
    },
    {
        value: 'wait',
        title: $gettext('Wait for current song (Rotation)'),
        description: $gettext('Do not interrupt normal playback. Start after the current song finishes. Best for music rotation blocks.'),
        recommended: false,
    },
    {
        value: 'priority',
        title: $gettext('Priority Start (News / Alert)'),
        description: $gettext('Start on schedule and also override listener requests. Best for news, alerts and time-sensitive content.'),
        recommended: false,
    },
];

const endBehaviorOptions: Array<{
    value: 'boundary' | 'finish',
    title: string,
    description: string,
}> = [
    {
        value: 'boundary',
        title: $gettext('Stop at scheduled time'),
        description: $gettext('Return to normal programming at the scheduled end boundary. Used with Programme and Priority starts.'),
    },
    {
        value: 'finish',
        title: $gettext('Let current item finish (Allow Overrun)'),
        description: $gettext('Let the current track finish naturally before returning to normal programming. Used with Rotation starts.'),
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
    gap: .75rem;
    padding: .8rem 1rem;
}

.behavior-heading-start {
    background: rgba(25, 135, 84, .1);
    color: var(--bs-success-text-emphasis);
}

.behavior-heading-end {
    background: rgba(220, 53, 69, .1);
    color: var(--bs-danger-text-emphasis);
}

.heading-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    flex: 0 0 2rem;
    border-radius: .5rem;
    background: currentColor;
    color: var(--bs-body-bg);
    font-size: .75rem;
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
    font-size: 1rem;
}

.behavior-heading small {
    margin-top: .15rem;
    color: var(--bs-secondary-color);
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
    padding: .9rem;
    margin: 0;
    border: 1px solid var(--bs-border-color);
    border-radius: .65rem;
    background: var(--bs-tertiary-bg);
    cursor: pointer;
}

.choice-option.is-active,
.behavior-option.is-active {
    border-color: #2688ff;
    background: rgba(38, 136, 255, .08);
    box-shadow: 0 0 0 .1rem rgba(38, 136, 255, .12);
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
    font-size: .86rem;
}

.option-copy small,
.behavior-option small {
    margin-top: .2rem;
    color: var(--bs-secondary-color);
    line-height: 1.45;
}

.recommended-badge {
    display: inline-block;
    margin-top: .45rem;
    padding: .16rem .48rem;
    border-radius: 999px;
    background: rgba(25, 135, 84, .16);
    color: var(--bs-success-text-emphasis);
    font-size: .68rem;
    font-weight: 700;
}

.behavior-note {
    color: var(--bs-secondary-color);
    font-size: .75rem;
    line-height: 1.4;
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
    gap: .75rem;
    padding: .9rem 1rem;
    cursor: pointer;
}

.advanced-icon {
    font-size: 1.25rem;
}

.advanced-box summary small {
    margin-top: .15rem;
    color: var(--bs-secondary-color);
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
