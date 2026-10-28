<template>
    <div class="advanced-panel">
        <div class="advanced-heading">
            <span class="advanced-icon"><icon-ic-settings /></span>
            <span>
                <span class="title-with-help">
                    <strong>{{ $gettext('Advanced playlist settings') }}</strong>
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
                <small>{{ summary }}</small>
            </span>
        </div>

        <div
            v-if="isPlaylist"
            class="form-check form-switch option-switch"
        >
            <input
                id="edit_form_single_track"
                v-model="singleTrack"
                class="form-check-input"
                type="checkbox"
                role="switch"
            >
            <label
                class="form-check-label"
                for="edit_form_single_track"
            >
                <strong>{{ $gettext('Only play one track') }}</strong>
                <small>{{ $gettext('Each time it comes up, play one track from this playlist instead of the whole block.') }}</small>
            </label>
        </div>

        <div
            v-if="isPlaylist"
            class="form-check form-switch option-switch"
        >
            <input
                id="edit_form_merge"
                v-model="mergeTracks"
                class="form-check-input"
                type="checkbox"
                role="switch"
            >
            <label
                class="form-check-label"
                for="edit_form_merge"
            >
                <strong>{{ $gettext('Merge all tracks') }}</strong>
                <small>{{ $gettext('Treat every track as one continuous block. Good for multi-part shows and long-form content.') }}</small>
            </label>
        </div>

        <div class="form-check form-switch option-switch">
            <input
                id="edit_form_prioritize"
                v-model="prioritizeRequests"
                class="form-check-input"
                type="checkbox"
                role="switch"
            >
            <label
                class="form-check-label"
                for="edit_form_prioritize"
            >
                <strong>{{ $gettext('Play ahead of listener requests') }}</strong>
                <small>{{ $gettext('This playlist always goes before queued listener requests.') }}</small>
            </label>
        </div>

        <template v-if="isPlaylist">
            <div class="form-check form-switch option-switch">
                <input
                    id="edit_form_is_sponsor"
                    v-model="form.is_sponsor"
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                >
                <label
                    class="form-check-label"
                    for="edit_form_is_sponsor"
                >
                    <strong>{{ $gettext('Sponsor / paid ad spot') }}</strong>
                    <small>{{ $gettext('Track plays in the Sponsor Play Report and optionally guarantee a minimum number of daily plays.') }}</small>
                </label>
            </div>

            <div
                v-if="form.is_sponsor"
                class="row g-2 sponsor-fields"
            >
                <form-group-field
                    id="edit_form_sponsor_name"
                    class="col-sm-6"
                    :field="r$.sponsor_name"
                    :label="$gettext('Sponsor Name')"
                    :description="$gettext('Defaults to the playlist name if left blank.')"
                />

                <form-group-field
                    id="edit_form_sponsor_guaranteed_plays_per_day"
                    class="col-sm-6"
                    :field="r$.sponsor_guaranteed_plays_per_day"
                    type="number"
                    :label="$gettext('Guaranteed Plays Per Day')"
                    :description="$gettext('Leave empty to track plays without a minimum.')"
                />
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
// Playlist-wide options shown beside the first scheduled time on the calendar.
// They apply every time the playlist plays, not just one time slot. Web streams
// only use "Play ahead of listener requests"; one-track, merge and sponsor
// tracking work on song playlists.
import {computed} from "vue";
import {storeToRefs} from "pinia";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import IconIcInfo from "~icons/ic/baseline-info";
import IconIcSettings from "~icons/ic/baseline-settings";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form";
import {useTranslate} from "~/vendor/gettext";

const props = defineProps<{
    source: 'playlist' | 'web_stream',
}>();

const {$gettext} = useTranslate();
const {form, r$} = storeToRefs(useStationsPlaylistsForm());

const isPlaylist = computed(() => props.source === 'playlist');

const summary = computed(() => isPlaylist.value
    ? $gettext('Applies every time this playlist plays, not just this time slot: one track, merge tracks, request priority, sponsor tracking.')
    : $gettext('Applies every time this stream plays, not just this time slot: request priority.'));

const advancedHelp = $gettext('These options remain independent. Scheduling Mode does not remove Only Play One Track, Merge, request priority, or sponsor controls.');

const hasOption = (option: string) => form.value.backend_options.includes(option);

const setOption = (option: string, enabled: boolean) => {
    const options = form.value.backend_options.filter((item) => item !== option);
    if (enabled) {
        options.push(option);
    }
    form.value.backend_options = options;
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
</script>

<style scoped>
.advanced-panel {
    min-width: 0;
    padding: .95rem 1rem 1rem;
    border: 1px solid rgb(108, 117, 125);
    border-radius: 9.1px;
    background: rgb(43, 48, 53);
}

.advanced-heading {
    display: flex;
    align-items: center;
    gap: .7rem;
    margin-bottom: .75rem;
}

.advanced-heading strong,
.advanced-heading small {
    display: block;
}

.advanced-heading strong {
    color: #fff;
    font-size: 13.4px;
}

.advanced-heading small {
    color: var(--bs-secondary-color);
    font-size: 11.2px;
    font-weight: 400;
}

.title-with-help {
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
    color: #2688ff;
    cursor: help;
    font-size: 1rem;
    line-height: 1;
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
    font-size: 1.2rem;
}

.option-switch {
    margin: .4rem 0;
    padding-left: 2.75em;
}

.option-switch strong,
.option-switch small {
    display: block;
}

.option-switch strong {
    font-size: .84rem;
}

.option-switch small {
    color: var(--bs-secondary-color);
    font-size: .78rem;
}

.sponsor-fields {
    margin-top: .25rem;
}
</style>
