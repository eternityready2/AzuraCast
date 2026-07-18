<template>
    <tab :label="$gettext('Basic Information')"
         :item-header-class="tabClass">
        <div class="row g-3">
            <form-group-field
                id="edit_form_path"
                class="col-md-6"
                :field="r$.path"
                :label="$gettext('File Name')"
                :description="$gettext('The relative path of the file in the station\'s media directory.')"
            />

            <form-group-field
                id="edit_form_title"
                class="col-md-6"
                :field="r$.title"
                :label="$gettext('Song Title')"
            />

            <form-group-field
                id="edit_form_artist"
                class="col-md-6"
                :field="r$.artist"
                :label="$gettext('Song Artist')"
            />

            <form-group-field
                id="edit_form_genre"
                class="col-md-6"
                :field="r$.genre"
                :label="$gettext('Song Genre')"
            />

            <form-group-field
                id="edit_form_album"
                class="col-md-6"
                :field="r$.album"
                :label="$gettext('Song Album')"
            />

            <form-group-field
                id="edit_form_lyrics"
                class="col-md-6"
                :field="r$.lyrics"
                input-type="textarea"
                :label="$gettext('Song Lyrics')"
            />

            <form-group-field
                id="edit_form_isrc"
                class="col-md-6"
                :field="r$.isrc"
                :label="$gettext('ISRC')"
                :description="$gettext('International Standard Recording Code, used for licensing reports.')"
            />

            <div class="col-md-6">
                <label
                    class="form-label fw-semibold"
                    for="edit_form_type"
                >
                    {{ $gettext('Type') }}
                </label>
                <select
                    id="edit_form_type"
                    v-model="r$.type.$value"
                    class="form-select"
                >
                    <option value="music">{{ $gettext('Music (music and copyrighted material)') }}</option>
                    <option value="talk">{{ $gettext('Talk (sermons, speeches, and live recordings)') }}</option>
                    <option value="id">{{ $gettext('ID (station identification, sweepers, and top-of-hour IDs)') }}</option>
                    <option value="promo">{{ $gettext('Promo (station promotion that is not considered an ID)') }}</option>
                    <option value="ad">{{ $gettext('Ad (advert replacement files)') }}</option>
                </select>
            </div>

            <div class="col-md-6">
                <label
                    class="form-label fw-semibold"
                    for="edit_form_category"
                >
                    {{ $gettext('Category') }}
                </label>
                <select
                    id="edit_form_category"
                    v-model="r$.category_id.$value"
                    class="form-select"
                >
                    <option :value="null">{{ $gettext('— None —') }}</option>
                    <option
                        v-for="cat in categories"
                        :key="cat.id"
                        :value="cat.id"
                    >
                        {{ cat.name }}
                    </option>
                </select>
            </div>
        </div>

        <form-group-checkbox
            id="do_not_play"
            class="mb-3"
            :field="r$.do_not_play"
            :label="$gettext('Do not play')"
            :description="$gettext('Exclude this track from AutoDJ and clock wheel selection.')"
        />

        <template v-if="form.do_not_play">
            <form-group-field
                id="do_not_play_reason"
                class="mb-3"
                :field="r$.do_not_play_reason"
                :label="$gettext('DNP reason')"
            />
            <form-group-field
                id="do_not_play_until"
                class="mb-3"
                :field="r$.do_not_play_until"
                type="datetime-local"
                :label="$gettext('DNP until (optional)')"
                :description="$gettext('Leave empty for a permanent do-not-play flag.')"
            />
        </template>

        <form-fieldset>
            <template #label>
                {{ $gettext('Time Restrictions') }}
            </template>

            <small class="text-muted d-block mb-2">
                {{ $gettext('Restrict when this specific track is eligible to play (e.g. "weekends only" or "not before 6am"). Leave everything below blank/unchecked for no restriction.') }}
            </small>

            <div class="mb-3">
                <label class="form-label">{{ $gettext('Allowed Days') }}</label>
                <div class="d-flex flex-wrap gap-3">
                    <div
                        v-for="day in dayOptions"
                        :key="day.value"
                        class="form-check"
                    >
                        <input
                            :id="'allowed_day_' + day.value"
                            v-model="form.allowed_days"
                            class="form-check-input"
                            type="checkbox"
                            :value="day.value"
                        >
                        <label class="form-check-label" :for="'allowed_day_' + day.value">
                            {{ day.text }}
                        </label>
                    </div>
                </div>
                <small class="text-muted">
                    {{ $gettext('Leave all unchecked to allow every day.') }}
                </small>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">{{ $gettext('Allowed start time') }}</label>
                    <AmPmTimeInput v-model="allowedStartTimeDisplay" />
                    <small class="text-muted">
                        {{ $gettext('Leave blank for no start restriction.') }}
                    </small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ $gettext('Allowed end time') }}</label>
                    <AmPmTimeInput v-model="allowedEndTimeDisplay" />
                    <small class="text-muted">
                        {{ $gettext('If end is before start, treated as an overnight window. Leave blank for no end restriction.') }}
                    </small>
                </div>
            </div>
        </form-fieldset>
    </tab>
</template>

<script setup lang="ts">
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormFieldset from "~/components/Form/FormFieldset.vue";
import AmPmTimeInput from '~/components/Common/AmPmTimeInput.vue';
import {storeToRefs} from "pinia";
import {useFormTabClass} from "~/functions/useFormTabClass.ts";
import {computed, ref, onMounted} from "vue";
import {useStationsMediaForm} from "~/components/Stations/Media/Form/form.ts";
import Tab from "~/components/Common/Tab.vue";
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios.ts';
import {useTranslate} from '~/vendor/gettext';

const {$gettext} = useTranslate();
const {r$, form} = storeToRefs(useStationsMediaForm());
const tabClass = useFormTabClass(computed(() => r$.value.$groups.basicInfoTab));

const {getStationApiUrl} = useApiRouter();
const categoriesUrl = getStationApiUrl('/media-categories');
const {axios} = useAxios();

const categories = ref<{id: number; name: string}[]>([]);

const dayOptions = [
    {value: 1, text: $gettext('Monday')},
    {value: 2, text: $gettext('Tuesday')},
    {value: 3, text: $gettext('Wednesday')},
    {value: 4, text: $gettext('Thursday')},
    {value: 5, text: $gettext('Friday')},
    {value: 6, text: $gettext('Saturday')},
    {value: 7, text: $gettext('Sunday')},
];

// AmPmTimeInput works in HHMM format (e.g. 600 for 6:00 AM). The backend
// stores allowed_start_minute/allowed_end_minute as plain minutes-since-
// midnight (e.g. 360 for 6:00 AM) to keep the boundary math simple elsewhere.
// These computed properties convert between the two so the UI can use a
// real time picker without changing the backend format or needing a new
// migration.
const minutesToHHMM = (minutes: number | null): number | null => {
    if (minutes === null || minutes === undefined) {
        return null;
    }
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h * 100 + m;
};

const hhmmToMinutes = (hhmm: number | null): number | null => {
    if (hhmm === null || hhmm === undefined) {
        return null;
    }
    const h = Math.floor(hhmm / 100);
    const m = hhmm % 100;
    return h * 60 + m;
};

const allowedStartTimeDisplay = computed<number | null>({
    get: () => minutesToHHMM(form.value.allowed_start_minute),
    set: (val) => {
        form.value.allowed_start_minute = hhmmToMinutes(val);
    },
});

const allowedEndTimeDisplay = computed<number | null>({
    get: () => minutesToHHMM(form.value.allowed_end_minute),
    set: (val) => {
        form.value.allowed_end_minute = hhmmToMinutes(val);
    },
});

onMounted(async () => {
    try {
        const resp = await axios.get(categoriesUrl.value);
        categories.value = resp.data?.rows ?? resp.data ?? [];
    } catch {
        categories.value = [];
    }
});
</script>
