<template>
    <div class="playout-settings">
        <div class="section-heading mb-3">
            <span class="step-number">2</span>
            <span class="section-copy">
                <strong>{{ $gettext('How should it start?') }}</strong>
                <small>{{ $gettext('Choose what happens when a scheduled start time arrives. This replaces the old Playout Priority setting with plain-language choices.') }}</small>
            </span>
        </div>

        <div
            v-if="!hasSchedule"
            class="alert alert-warning py-2 mb-3"
        >
            {{ $gettext('This playlist has no schedule. Start behavior does not create a schedule; an enabled unscheduled playlist may still be selected all day.') }}
        </div>

        <div class="choice-options mb-4">
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

        <div class="section-heading mb-3">
            <span class="step-number">3</span>
            <span class="section-copy">
                <strong>{{ $gettext('What should happen at the end?') }}</strong>
                <small>{{ $gettext('Choose whether the schedule boundary is firm or whether the current item may finish naturally.') }}</small>
            </span>
        </div>

        <div class="choice-options mb-4">
            <label
                v-for="option in endBehaviorOptions"
                :key="option.value"
                class="choice-option"
                :class="{'is-active': endBehavior === option.value}"
            >
                <input
                    v-model="endBehavior"
                    class="form-check-input"
                    type="radio"
                    :value="option.value"
                >
                <span class="option-copy">
                    <strong>{{ option.title }}</strong>
                    <small>{{ option.description }}</small>
                </span>
            </label>
        </div>

        <details class="advanced-box">
            <summary>
                <strong>{{ $gettext('Special / Advanced Options') }}</strong>
                <small>{{ $gettext('Only needed for unusual playlist behavior, listener-request overrides, or sponsor tracking.') }}</small>
            </summary>

            <div class="advanced-body">
                <label class="behavior-option" :class="{'is-active': singleTrack}">
                    <input v-model="singleTrack" class="form-check-input" type="checkbox">
                    <span>
                        <strong>{{ $gettext('Only Play One Track') }}</strong>
                        <small>{{ $gettext('At each scheduled start, play one track from this playlist instead of running the whole playlist block.') }}</small>
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
                        <small>{{ $gettext('Give this playlist priority over automatic listener requests. Selecting Priority / News above turns this on automatically.') }}</small>
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
    },
});

const endBehavior = computed({
    get: (): 'boundary' | 'finish' => hasOption('allow_overrun') ? 'finish' : 'boundary',
    set: (value: 'boundary' | 'finish') => setOption('allow_overrun', value === 'finish'),
});

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
        value: 'wait',
        title: $gettext('Wait for the current song'),
        description: $gettext('Do not interrupt normal playback. Start this playlist after the current song finishes. Best for music blocks and background rotation.'),
        recommended: false,
    },
    {
        value: 'scheduled',
        title: $gettext('Start at the scheduled time'),
        description: $gettext('Interrupt normal rotation when the scheduled time arrives. Best for regular shows and prerecorded programmes.'),
        recommended: true,
    },
    {
        value: 'priority',
        title: $gettext('Priority / News'),
        description: $gettext('Start at the scheduled time and also take priority over listener requests. Best for news, alerts and time-sensitive content.'),
        recommended: false,
    },
];

const endBehaviorOptions = [
    {
        value: 'boundary',
        title: $gettext('Return at the schedule boundary'),
        description: $gettext('Keep the scheduled window firm and return to normal rotation when the block ends.'),
    },
    {
        value: 'finish',
        title: $gettext('Let the current item finish'),
        description: $gettext('If content is still playing at the end time, let it finish before returning to normal rotation.'),
    },
];
</script>

<style scoped>
.playout-settings {
    border-top: 1px solid var(--bs-border-color);
    padding-top: 1.25rem;
}

.section-heading {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
}

.step-number {
    display: inline-flex;
    width: 1.8rem;
    height: 1.8rem;
    align-items: center;
    justify-content: center;
    flex: 0 0 1.8rem;
    border-radius: 50%;
    background: #2688ff;
    color: #fff;
    font-weight: 700;
    font-size: .82rem;
}

.section-copy strong,
.section-copy small,
.option-copy strong,
.option-copy small,
.behavior-option strong,
.behavior-option small,
.advanced-box summary strong,
.advanced-box summary small {
    display: block;
}

.section-copy strong {
    font-size: 1rem;
}

.section-copy small {
    margin-top: .15rem;
    color: var(--bs-secondary-color);
    line-height: 1.45;
}

.choice-options,
.advanced-body {
    display: grid;
    gap: .65rem;
}

.choice-option,
.behavior-option {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    padding: .85rem .9rem;
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

.choice-option input,
.behavior-option input {
    margin-top: .2rem;
    accent-color: #2688ff;
}

.option-copy strong,
.behavior-option strong {
    font-size: .86rem;
}

.option-copy small,
.behavior-option small {
    margin-top: .18rem;
    color: var(--bs-secondary-color);
    line-height: 1.45;
}

.recommended-badge {
    display: inline-block;
    margin-top: .45rem;
    padding: .15rem .45rem;
    border-radius: 999px;
    background: rgba(38, 136, 255, .16);
    color: #72adff;
    font-size: .68rem;
    font-weight: 700;
}

.advanced-box {
    border: 1px solid var(--bs-border-color);
    border-radius: .7rem;
    background: var(--bs-tertiary-bg);
    overflow: hidden;
}

.advanced-box summary {
    padding: .9rem 1rem;
    cursor: pointer;
}

.advanced-box summary small {
    margin-top: .18rem;
    color: var(--bs-secondary-color);
    font-weight: 400;
}

.advanced-body {
    padding: 0 1rem 1rem;
}

.sponsor-toggle {
    margin-top: .2rem;
}
</style>
