<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_playout_controls"
    >
        <div class="card-header text-bg-primary">
            <h2
                id="hdr_playout_controls"
                class="card-title"
            >
                {{ $gettext('Playout Controls') }}
            </h2>
        </div>

        <div class="card-body">
            <tabs
                v-model="activeTab"
                content-class="mt-3"
                destroy-on-hide
            >
                <!-- ── Live Controls ── -->
                <tab
                    id="live_controls"
                    :label="$gettext('Live Controls')"
                >
                    <info-card>
                        <p class="mb-0">
                            {{ $gettext('Operator controls for starting a playlist, scheduling a one-time playlist window, or immediately escaping incorrect on-air content.') }}
                        </p>
                    </info-card>

                    <div class="row g-4 mt-1">
                        <div class="col-lg-7">
                            <h3 class="h6">
                                {{ $gettext('Start a Playlist') }}
                            </h3>
                            <p class="text-secondary small">
                                {{ $gettext('Only enabled song-based playlists are shown. Manual starts intentionally override the playlist\'s normal schedule; disabled playlists remain protected.') }}
                            </p>

                            <div class="mb-3">
                                <label
                                    class="form-label"
                                    for="manual_playlist_id"
                                >
                                    {{ $gettext('Playlist') }}
                                </label>
                                <select
                                    id="manual_playlist_id"
                                    v-model.number="selectedPlaylistId"
                                    class="form-select"
                                    :disabled="playlistsLoading || manualBusy"
                                >
                                    <option
                                        :value="null"
                                        disabled
                                    >
                                        {{ playlistsLoading ? $gettext('Loading playlists...') : $gettext('Select a playlist') }}
                                    </option>
                                    <option
                                        v-for="playlist in playlists"
                                        :key="playlist.id"
                                        :value="playlist.id"
                                    >
                                        {{ playlist.name }}
                                    </option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label
                                    class="form-label"
                                    for="manual_playlist_mode"
                                >
                                    {{ $gettext('Action') }}
                                </label>
                                <select
                                    id="manual_playlist_mode"
                                    v-model="manualMode"
                                    class="form-select"
                                    :disabled="manualBusy"
                                >
                                    <option value="queue_next">
                                        {{ $gettext('Queue Playlist Next') }}
                                    </option>
                                    <option value="play_now">
                                        {{ $gettext('Play Playlist Now') }}
                                    </option>
                                    <option value="schedule_once">
                                        {{ $gettext('Schedule Playlist Once') }}
                                    </option>
                                </select>
                            </div>

                            <template v-if="manualMode === 'schedule_once'">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-7">
                                        <label
                                            class="form-label"
                                            for="manual_start_at"
                                        >
                                            {{ $gettext('Start Date / Time') }}
                                        </label>
                                        <input
                                            id="manual_start_at"
                                            v-model="scheduledAt"
                                            type="datetime-local"
                                            class="form-control"
                                            :disabled="manualBusy"
                                        >
                                    </div>
                                    <div class="col-md-5">
                                        <label
                                            class="form-label"
                                            for="manual_duration"
                                        >
                                            {{ $gettext('Duration (minutes)') }}
                                        </label>
                                        <input
                                            id="manual_duration"
                                            v-model.number="durationMinutes"
                                            type="number"
                                            class="form-control"
                                            min="1"
                                            max="1440"
                                            :disabled="manualBusy"
                                        >
                                    </div>
                                </div>

                                <div class="form-check mb-3">
                                    <input
                                        id="manual_strict_start"
                                        v-model="strictStart"
                                        type="checkbox"
                                        class="form-check-input"
                                        :disabled="manualBusy"
                                    >
                                    <label
                                        class="form-check-label"
                                        for="manual_strict_start"
                                    >
                                        {{ $gettext('Use a strict start time (cut the previous item if needed)') }}
                                    </label>
                                </div>
                            </template>

                            <div class="buttons">
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    :disabled="manualBusy || selectedPlaylistId === null || (manualMode === 'schedule_once' && scheduledAt === '')"
                                    @click="runManualPlaylist"
                                >
                                    {{ manualMode === 'play_now' ? $gettext('Play Now') : manualMode === 'schedule_once' ? $gettext('Schedule Once') : $gettext('Queue Next') }}
                                </button>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <h3 class="h6">
                                {{ $gettext('Emergency Playout') }}
                            </h3>
                            <p class="text-secondary small">
                                {{ $gettext('Skip the current item and rebuild unsent AutoDJ choices, or restart Liquidsoap to flush already-buffered bad content.') }}
                            </p>

                            <div class="buttons mb-4">
                                <button
                                    type="button"
                                    class="btn btn-warning"
                                    :disabled="emergencyBusy"
                                    @click="stopCurrent(false)"
                                >
                                    {{ $gettext('Skip Current Item') }}
                                </button>
                            </div>

                            <div class="alert alert-danger">
                                <strong>{{ $gettext('Emergency Reset') }}</strong>
                                <div class="small mt-1">
                                    {{ $gettext('This restarts Liquidsoap and clears its in-memory request queues. Use this when incorrect scheduled/manual content keeps returning after Skip.') }}
                                </div>
                            </div>

                            <div class="form-check mb-2">
                                <input
                                    id="confirm_emergency_reset"
                                    v-model="confirmEmergencyReset"
                                    type="checkbox"
                                    class="form-check-input"
                                    :disabled="emergencyBusy"
                                >
                                <label
                                    class="form-check-label"
                                    for="confirm_emergency_reset"
                                >
                                    {{ $gettext('I understand this briefly interrupts the stream.') }}
                                </label>
                            </div>

                            <div class="buttons">
                                <button
                                    type="button"
                                    class="btn btn-danger"
                                    :disabled="emergencyBusy || !confirmEmergencyReset"
                                    @click="stopCurrent(true)"
                                >
                                    {{ $gettext('Emergency Reset Liquidsoap') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </tab>

                <!-- ── Audio Settings ── -->
                <tab
                    id="audio_settings"
                    :label="$gettext('Audio Settings')"
                >
                    <info-card>
                        <p class="mb-0">
                            {{ $gettext('Advanced stretch/squeeze and audio-ducking controls. Top-of-hour timing is handled on the Top of Hour ID page.') }}
                        </p>
                    </info-card>

                    <loading
                        :loading="isLoading"
                        lazy
                    >
                        <form
                            class="mt-3"
                            @submit.prevent="saveChanges"
                        >
                            <h3 class="h6">
                                {{ $gettext('Stretch / Squeeze') }}
                            </h3>
                            <p class="text-secondary small">
                                {{ $gettext('Speeds up or slows down a track slightly when AutoDJ backtimes music into a protected boundary. This also raises or lowers pitch (like a turntable), so keep it at 2-3% to stay unnoticeable. This is station-wide and applies to standard rotation playlists, Smart Blocks and Clock Wheels when their selected track has stretch/squeeze timing metadata.') }}
                            </p>

                            <form-group
                                id="stretch_squeeze_enabled"
                                class="mb-3"
                            >
                                <template #label>
                                    {{ $gettext('Enable stretch / squeeze') }}
                                </template>
                                <form-checkbox
                                    id="stretch_squeeze_enabled"
                                    v-model="form.stretch_squeeze_enabled"
                                />
                            </form-group>

                            <template v-if="form.stretch_squeeze_enabled">
                                <form-group
                                    id="stretch_max_percent"
                                    class="mb-3"
                                >
                                    <template #label>
                                        {{ $gettext('Maximum stretch (%)') }}
                                    </template>
                                    <input
                                        id="stretch_max_percent"
                                        v-model.number="form.stretch_max_percent"
                                        type="number"
                                        class="form-control"
                                        min="0.5"
                                        max="5"
                                        step="0.5"
                                    >
                                    <div class="form-text">
                                        {{ $gettext('How much AutoDJ may slow a track down (extend its duration) to reach a timing target. Hard limit is 5%.') }}
                                    </div>
                                </form-group>

                                <form-group
                                    id="squeeze_max_percent"
                                    class="mb-3"
                                >
                                    <template #label>
                                        {{ $gettext('Maximum squeeze (%)') }}
                                    </template>
                                    <input
                                        id="squeeze_max_percent"
                                        v-model.number="form.squeeze_max_percent"
                                        type="number"
                                        class="form-control"
                                        min="0.5"
                                        max="5"
                                        step="0.5"
                                    >
                                    <div class="form-text">
                                        {{ $gettext('How much AutoDJ may speed a track up (shorten its duration) to reach a timing target. Hard limit is 5%. Configured separately from stretch because listeners can perceive the two directions differently.') }}
                                    </div>
                                </form-group>
                            </template>

                            <hr class="my-4">

                            <h3 class="h6">
                                {{ $gettext('Smart Ducking') }}
                            </h3>
                            <p class="text-secondary small">
                                {{ $gettext('Lowers the music bed while supported interrupting audio plays, then restores it smoothly.') }}
                            </p>

                            <form-group
                                id="smart_duck_enabled"
                                class="mb-3"
                            >
                                <template #label>
                                    {{ $gettext('Enable smart ducking') }}
                                </template>
                                <form-checkbox
                                    id="smart_duck_enabled"
                                    v-model="form.smart_duck_enabled"
                                />
                            </form-group>

                            <template v-if="form.smart_duck_enabled">
                                <form-group
                                    id="smart_duck_attenuation"
                                    class="mb-3"
                                >
                                    <template #label>
                                        {{ $gettext('Music bed level while ducked (0 = silent, 1 = full volume)') }}
                                    </template>
                                    <input
                                        id="smart_duck_attenuation"
                                        v-model.number="form.smart_duck_attenuation"
                                        type="number"
                                        class="form-control"
                                        min="0"
                                        max="1"
                                        step="0.05"
                                    >
                                </form-group>

                                <form-group
                                    id="smart_duck_delay"
                                    class="mb-3"
                                >
                                    <template #label>
                                        {{ $gettext('Ducking fade time (seconds)') }}
                                    </template>
                                    <input
                                        id="smart_duck_delay"
                                        v-model.number="form.smart_duck_delay"
                                        type="number"
                                        class="form-control"
                                        min="0.5"
                                        max="15"
                                        step="0.5"
                                    >
                                </form-group>
                            </template>

                            <div class="alert alert-info">
                                {{ $gettext('Ducking configuration changes take effect after broadcasting is restarted. Stretch / Squeeze does not require a broadcasting restart; new settings apply to newly planned AutoDJ items while items already in the queue keep their existing timing plan.') }}
                            </div>

                            <div class="buttons">
                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                    :disabled="isLoading || isSaving"
                                >
                                    {{ $gettext('Save Changes') }}
                                </button>
                            </div>
                        </form>
                    </loading>
                </tab>

                <!-- ── Crossfade Profiles ── -->
                <tab
                    id="crossfade"
                    :label="$gettext('Crossfade Profiles')"
                >
                    <crossfade-profiles />
                </tab>

                <!-- ── Restart Broadcasting ── -->
                <tab
                    id="restart_broadcasting"
                    :label="$gettext('Restart Broadcasting')"
                >
                    <div class="row">
                        <div class="col col-md-6">
                            <section
                                class="card"
                                role="region"
                                aria-labelledby="hdr_soft_reload"
                            >
                                <div class="card-header text-bg-primary">
                                    <h3
                                        id="hdr_soft_reload"
                                        class="card-title"
                                    >
                                        {{ $gettext('Reload Configuration') }}
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <p class="card-text">
                                        {{ $gettext('Stations using Icecast can soft-reload the station configuration, applying changes while keeping the stream broadcast running.') }}
                                    </p>
                                    <p class="card-text has-text-weight-bold text-body-emphasis">
                                        {{ $gettext('Reloading broadcasting will not disconnect your listeners.') }}
                                    </p>
                                    <template v-if="canReload">
                                        <p class="card-text text-success">
                                            {{ $gettext('Your station supports reloading configuration.') }}
                                        </p>
                                        <div class="buttons">
                                            <button
                                                type="button"
                                                class="btn btn-warning"
                                                :disabled="broadcastBusy"
                                                @click="doReload"
                                            >
                                                {{ $gettext('Reload Configuration') }}
                                            </button>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <p class="card-text text-danger">
                                            {{ $gettext('Your station does not support reloading configuration. Restart broadcasting instead to apply changes.') }}
                                        </p>
                                    </template>
                                </div>
                            </section>
                        </div>

                        <div class="col col-md-6">
                            <section
                                class="card"
                                role="region"
                                aria-labelledby="hdr_restart_broadcasting"
                            >
                                <div class="card-header text-bg-primary">
                                    <h3
                                        id="hdr_restart_broadcasting"
                                        class="card-title"
                                    >
                                        {{ $gettext('Restart Broadcasting') }}
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <p class="card-text">
                                        {{ $gettext('Restarting broadcasting will rewrite all configuration files and restart all services.') }}
                                    </p>
                                    <p class="card-text has-text-weight-bold text-body-emphasis">
                                        {{ $gettext('Restarting broadcasting will briefly disconnect your listeners.') }}
                                    </p>
                                    <div class="buttons">
                                        <button
                                            type="button"
                                            class="btn btn-warning"
                                            :disabled="broadcastBusy"
                                            @click="doRestart"
                                        >
                                            {{ $gettext('Restart Broadcasting') }}
                                        </button>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </tab>
            </tabs>
        </div>
    </section>
</template>

<script setup lang="ts">
import InfoCard from '~/components/Common/InfoCard.vue';
import Loading from '~/components/Common/Loading.vue';
import Tab from '~/components/Common/Tab.vue';
import Tabs from '~/components/Common/Tabs.vue';
import CrossfadeProfiles from '~/components/Stations/CrossfadeProfiles.vue';
import FormGroup from '~/components/Form/FormGroup.vue';
import FormCheckbox from '~/components/Form/FormCheckbox.vue';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import type {PlayoutControlsSettings} from '~/entities/PlayoutControls.ts';
import {useAxios} from '~/vendor/axios.ts';
import {onMounted, ref} from 'vue';
import {useTranslate} from '~/vendor/gettext.ts';
import {useRoute, useRouter} from 'vue-router';
import {useDialog} from '~/components/Common/Dialogs/useDialog.ts';
import {useClearAllStationQueries, useStationData} from '~/functions/useStationQuery.ts';
import {ApiStatus, FlashLevels} from '~/entities/ApiInterfaces.ts';
import {toRefs} from '@vueuse/core';
import {delay} from 'es-toolkit';

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError, notify} = useNotify();
const {$gettext} = useTranslate();
const router = useRouter();
const {showAlert} = useDialog();

const route = useRoute();
const activeTab = ref(typeof route.query.tab === 'string' ? route.query.tab : 'live_controls');

// Audio settings
const apiUrl = getStationApiUrl('/playout-controls');
const isLoading = ref(true);
const isSaving = ref(false);

const form = ref<PlayoutControlsSettings>({
    stretch_squeeze_enabled: true,
    stretch_squeeze_max_percent: 5,
    stretch_max_percent: 5,
    squeeze_max_percent: 5,
    smart_duck_enabled: false,
    smart_duck_attenuation: 0.2,
    smart_duck_delay: 3,
});

const loadSettings = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<PlayoutControlsSettings>(apiUrl.value);
        form.value = {
            stretch_squeeze_enabled: data.stretch_squeeze_enabled ?? true,
            stretch_squeeze_max_percent: data.stretch_squeeze_max_percent ?? 5,
            stretch_max_percent: data.stretch_max_percent ?? 5,
            squeeze_max_percent: data.squeeze_max_percent ?? 5,
            smart_duck_enabled: data.smart_duck_enabled ?? false,
            smart_duck_attenuation: data.smart_duck_attenuation ?? 0.2,
            smart_duck_delay: data.smart_duck_delay ?? 3,
        };
    } finally {
        isLoading.value = false;
    }
};

const saveChanges = async () => {
    isSaving.value = true;
    try {
        await axios.put(apiUrl.value, form.value);
        notifySuccess();
        await loadSettings();
    } catch {
        notifyError();
    } finally {
        isSaving.value = false;
    }
};

// Live playout actions
interface PlaylistSummary {
    id: number;
    name: string;
    source: string;
    is_enabled: boolean;
}

interface PlaylistListResponse {
    total: number;
    rows: PlaylistSummary[];
}

interface StatusResponse {
    success: boolean;
    message: string;
}

type ManualPlaylistMode = 'queue_next' | 'play_now' | 'schedule_once';

const playlistsUrl = getStationApiUrl('/playlists');
const manualPlaylistUrl = getStationApiUrl('/features/playout/manual-playlist');
const stopCurrentUrl = getStationApiUrl('/features/playout/stop-current');
const playlistsLoading = ref(true);
const manualBusy = ref(false);
const emergencyBusy = ref(false);
const confirmEmergencyReset = ref(false);
const playlists = ref<PlaylistSummary[]>([]);
const selectedPlaylistId = ref<number | null>(null);
const manualMode = ref<ManualPlaylistMode>('queue_next');
const scheduledAt = ref('');
const durationMinutes = ref(60);
const strictStart = ref(true);

const loadPlaylists = async () => {
    playlistsLoading.value = true;
    try {
        const {data} = await axios.get<PlaylistListResponse>(playlistsUrl.value, {
            params: {internal: true, rowCount: 0},
        });
        playlists.value = (data.rows ?? [])
            .filter((p) => p.source === 'songs' && p.is_enabled)
            .sort((a, b) => a.name.localeCompare(b.name));
        if (selectedPlaylistId.value === null && playlists.value.length > 0) {
            selectedPlaylistId.value = playlists.value[0].id;
        }
    } catch {
        notifyError();
    } finally {
        playlistsLoading.value = false;
    }
};

const runManualPlaylist = async () => {
    if (selectedPlaylistId.value === null) return;
    manualBusy.value = true;
    try {
        const {data} = await axios.post<StatusResponse>(manualPlaylistUrl.value, {
            playlist_id: selectedPlaylistId.value,
            mode: manualMode.value,
            start_at: manualMode.value === 'schedule_once' ? scheduledAt.value : null,
            duration_minutes: durationMinutes.value,
            strict_start: strictStart.value,
        });
        notifySuccess(data.message);
    } catch {
        notifyError();
    } finally {
        manualBusy.value = false;
    }
};

const stopCurrent = async (hardReset: boolean) => {
    emergencyBusy.value = true;
    try {
        const {data} = await axios.post<StatusResponse>(stopCurrentUrl.value, {hard_reset: hardReset});
        notifySuccess(data.message);
        confirmEmergencyReset.value = false;
    } catch {
        notifyError();
    } finally {
        emergencyBusy.value = false;
    }
};

// Broadcasting controls
const stationData = useStationData();
const {canReload} = toRefs(stationData);
const clearAllStationQueries = useClearAllStationQueries();
const reloadUrl = getStationApiUrl('/reload');
const restartUrl = getStationApiUrl('/restart');
const broadcastBusy = ref(false);

const makeBroadcastCall = async (uri: string) => {
    broadcastBusy.value = true;
    try {
        const {data} = await axios.post<ApiStatus>(uri);
        notify(data.formatted_message, {
            variant: data.success ? FlashLevels.Success : FlashLevels.Warning,
        });
        await delay(2000);
        await router.push({name: 'stations:index'});
        await clearAllStationQueries();
    } finally {
        broadcastBusy.value = false;
    }
};

const doReload = async () => {
    const {value} = await showAlert({
        title: $gettext('Are you sure?'),
        confirmButtonClass: 'btn-warning',
        confirmButtonText: $gettext('Reload Configuration'),
    });
    if (!value) return;
    await makeBroadcastCall(reloadUrl.value);
};

const doRestart = async () => {
    const {value} = await showAlert({
        title: $gettext('Are you sure?'),
        confirmButtonClass: 'btn-warning',
        confirmButtonText: $gettext('Restart Broadcasting'),
    });
    if (!value) return;
    await makeBroadcastCall(restartUrl.value);
};

onMounted(() => {
    void loadSettings();
    void loadPlaylists();
});
</script>
