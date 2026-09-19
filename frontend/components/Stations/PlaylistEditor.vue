<template>
    <div class="container py-4 playlist-editor-page">
        <section class="playlist-editor-hero mb-4">
            <div>
                <h1>{{ isEditMode ? $gettext('Edit Playlist') : $gettext('Add Playlist') }}</h1>
                <p>{{ $gettext('Configure playlist details, scheduling, and playout behavior.') }}</p>
            </div>
            <router-link
                class="btn btn-outline-primary"
                :to="{name: 'stations:playlists:index'}"
            >
                {{ $gettext('Back to Playlists') }}
            </router-link>
        </section>

        <div v-if="error" class="alert alert-danger mb-4">
            {{ error }}
        </div>

        <div v-if="loading" class="card p-5 text-center">
            <div class="spinner-border mx-auto mb-3" role="status" />
            <div>{{ $gettext('Loading playlist…') }}</div>
        </div>

        <form v-else @submit.prevent="doSubmit">
            <div class="playlist-editor-surface">
                <tabs>
                    <form-basic-info />
                    <form-schedule v-model:schedule-items="form.schedule_items" />
                </tabs>
            </div>

            <div class="playlist-editor-actions mt-4">
                <router-link
                    class="btn btn-secondary"
                    :to="{name: 'stations:playlists:index'}"
                >
                    {{ $gettext('Cancel / Back to Playlists') }}
                </router-link>
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="saving || r$.$invalid"
                >
                    {{ saving ? $gettext('Saving…') : $gettext('Save Playlist') }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup lang="ts">
import {computed, onBeforeUnmount, onMounted, ref} from "vue";
import {storeToRefs} from "pinia";
import {toRefs} from "@vueuse/core";
import {useRoute, useRouter} from "vue-router";
import Tabs from "~/components/Common/Tabs.vue";
import FormBasicInfo from "~/components/Stations/Playlists/Form/BasicInfo.vue";
import FormSchedule from "~/components/Stations/Playlists/Form/Schedule.vue";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form.ts";
import {useAppCollectScope} from "~/vendor/regle.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useMayNeedRestart} from "~/functions/useMayNeedRestart";
import {useStationData} from "~/functions/useStationQuery.ts";
import mergeExisting from "~/functions/mergeExisting.ts";
import normalizeStationScheduleDays from "~/functions/normalizeStationScheduleDays";

const {$gettext} = useTranslate();
const route = useRoute();
const router = useRouter();
const {axios} = useAxios();
const {notifySuccess} = useNotify();
const {getStationApiUrl} = useApiRouter();

const formStore = useStationsPlaylistsForm();
const {form, r$} = storeToRefs(formStore);
const {$reset: resetForm} = formStore;
const {r$: validatedr$} = useAppCollectScope('stations-playlists');

const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const playlistId = computed(() => route.params.playlist_id ? Number(route.params.playlist_id) : null);
const isEditMode = computed(() => playlistId.value !== null && Number.isFinite(playlistId.value));

const {mayNeedRestart: originalMayNeedRestart} = useMayNeedRestart();
const stationData = useStationData();
const {useManualAutoDj} = toRefs(stationData);
const mayNeedRestart = () => {
    if (useManualAutoDj.value) {
        originalMayNeedRestart();
    }
};

const normalizeLoadedSchedule = (item: Record<string, any>) => {
    const endType = item.recurrence_end_type ?? 'never';
    const merged: Record<string, any> = {
        ...item,
        loop_once: Boolean(item.loop_once),
        strict_start: Boolean(item.strict_start),
        recurrence_type: item.recurrence_type ?? 'weekly',
        recurrence_interval: item.recurrence_interval ?? 1,
        recurrence_end_type: endType === 'on_date' ? 'never' : endType,
        recurrence_end_after: endType === 'after' ? (item.recurrence_end_after ?? null) : null,
        recurrence_end_date: null,
    };

    if (endType === 'after') {
        merged.end_date = null;
    }
    if (
        merged.recurrence_type === 'monthly'
        && merged.recurrence_monthly_pattern === 'day_of_week'
        && merged.recurrence_monthly_day_of_week != null
        && (!merged.days || merged.days.length === 0)
    ) {
        merged.days = [Number(merged.recurrence_monthly_day_of_week)];
    }
    if (merged.id == null) {
        delete merged.id;
    }
    delete merged.playlist;
    delete merged.streamer;
    delete merged.clock_wheel;
    return merged;
};

const populateForm = (data: Record<string, any>) => {
    if (data.order === 'smart_shuffle') {
        data.order = 'shuffle';
    }
    if (data.schedule_items?.length) {
        data.schedule_items = data.schedule_items.map(normalizeLoadedSchedule);
    }
    r$.value.$reset({
        toState: mergeExisting(r$.value.$value, data),
    });
};

const loadPlaylist = async () => {
    resetForm();
    error.value = null;

    if (!isEditMode.value) {
        return;
    }

    loading.value = true;
    try {
        const url = getStationApiUrl(`/playlist/${playlistId.value}`).value;
        const {data} = await axios.get(url);
        populateForm(data);
    } catch (err: any) {
        error.value = err?.response?.data?.message ?? $gettext('Unable to load this playlist.');
    } finally {
        loading.value = false;
    }
};

const buildSubmitData = () => {
    const data = {...form.value} as Record<string, any>;

    if (data.id == null) {
        delete data.id;
    }
    delete data.playlists;
    delete data.playlist_groups;
    delete data.smart_block_criteria;

    if (Array.isArray(data.schedule_items) && data.schedule_items.length) {
        data.schedule_items = data.schedule_items.map((item: Record<string, any>) => {
            const out: Record<string, any> = {...item};
            out.recurrence_type = item.recurrence_type ?? 'weekly';
            out.recurrence_interval = (item.recurrence_type === 'biweekly' ? 2 : Number(item.recurrence_interval)) || 1;
            out.recurrence_end_type = item.recurrence_end_type ?? 'never';
            out.recurrence_end_after = (
                item.recurrence_end_type === 'after' && item.recurrence_end_after != null
            ) ? Number(item.recurrence_end_after) : null;
            out.recurrence_end_date = null;

            if (item.recurrence_end_type === 'after') {
                out.end_date = null;
            }

            const normalizedDays = normalizeStationScheduleDays(item.days);
            out.days = out.recurrence_type === 'monthly' && out.recurrence_monthly_pattern === 'date'
                ? []
                : normalizedDays;

            if (
                out.recurrence_type === 'monthly'
                && out.recurrence_monthly_pattern === 'day_of_week'
                && normalizedDays.length > 0
            ) {
                out.recurrence_monthly_day_of_week = normalizedDays[0];
            }

            if (out.id == null) {
                delete out.id;
            }
            delete out.playlist;
            delete out.streamer;
            delete out.clock_wheel;
            return out;
        });
    }

    return data;
};

const doSubmit = async () => {
    const {valid} = await validatedr$.$validate();
    if (!valid) {
        return;
    }

    saving.value = true;
    error.value = null;
    try {
        const data = buildSubmitData();
        if (isEditMode.value) {
            await axios.put(getStationApiUrl(`/playlist/${playlistId.value}`).value, data);
        } else {
            await axios.post(getStationApiUrl('/playlists').value, data);
        }
        notifySuccess();
        mayNeedRestart();
        await router.push({name: 'stations:playlists:index'});
    } catch (err: any) {
        error.value = err?.response?.data?.message ?? $gettext('Unable to save this playlist.');
    } finally {
        saving.value = false;
    }
};

onMounted(loadPlaylist);
onBeforeUnmount(resetForm);
</script>

<style scoped>
.playlist-editor-page {
    max-width: 1600px;
}

.playlist-editor-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.15rem 1.3rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .85rem;
    background: var(--bs-body-bg);
}

.playlist-editor-hero h1 {
    margin: 0;
    font-size: 1.5rem;
}

.playlist-editor-hero p {
    margin: .25rem 0 0;
    color: var(--bs-secondary-color);
}

.playlist-editor-surface {
    padding: 1.25rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .85rem;
    background: var(--bs-body-bg);
}

.playlist-editor-actions {
    position: sticky;
    bottom: .75rem;
    z-index: 20;
    display: flex;
    justify-content: flex-end;
    gap: .75rem;
    padding: .85rem 1rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: color-mix(in srgb, var(--bs-body-bg) 94%, transparent);
    box-shadow: 0 .25rem 1rem rgba(0, 0, 0, .12);
    backdrop-filter: blur(8px);
}

@media (max-width: 767.98px) {
    .playlist-editor-hero,
    .playlist-editor-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .playlist-editor-surface {
        padding: .8rem;
    }
}
</style>
