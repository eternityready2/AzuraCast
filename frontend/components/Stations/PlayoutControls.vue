<template>
    <form @submit.prevent="saveChanges">
        <card-page header-id="hdr_playout_controls">
            <template #header="{id}">
                <h2
                    :id="id"
                    class="card-title my-0"
                >
                    {{ $gettext('Playout Controls') }}
                </h2>
            </template>

            <info-card>
                <p class="mb-0">
                    {{ $gettext('Advanced hard-clock, stretch/squeeze and audio-ducking controls, kept separate from the Top of Hour ID page.') }}
                </p>
            </info-card>

            <loading :loading="isLoading" lazy>
                <div class="card-body">
                    <h3 class="h6">
                        {{ $gettext('Hard Clock Trigger') }}
                    </h3>
                    <p class="text-secondary small">
                        {{ $gettext('System-clock safety trigger used by the existing playout engine. Changes here preserve the pre-rebuild Top of Hour behavior.') }}
                    </p>

                    <form-group id="hard_clock_enabled" class="mb-3">
                        <template #label>
                            {{ $gettext('Enable hard clock trigger') }}
                        </template>
                        <form-checkbox id="hard_clock_enabled" v-model="form.hard_clock_enabled" />
                    </form-group>

                    <template v-if="form.hard_clock_enabled">
                        <form-group id="hard_clock_trigger_seconds" class="mb-3">
                            <template #label>
                                {{ $gettext('Trigger window (seconds)') }}
                            </template>
                            <input
                                id="hard_clock_trigger_seconds"
                                v-model.number="form.hard_clock_trigger_seconds"
                                type="number"
                                class="form-control"
                                min="1"
                                max="30"
                                step="0.5"
                            >
                        </form-group>

                        <form-group id="hard_clock_fade_seconds" class="mb-3">
                            <template #label>
                                {{ $gettext('Fade duration (seconds)') }}
                            </template>
                            <input
                                id="hard_clock_fade_seconds"
                                v-model.number="form.hard_clock_fade_seconds"
                                type="number"
                                class="form-control"
                                min="0"
                                max="10"
                                step="0.5"
                            >
                        </form-group>
                    </template>

                    <hr class="my-4">

                    <h3 class="h6">
                        {{ $gettext('Stretch / Squeeze') }}
                    </h3>
                    <p class="text-secondary small">
                        {{ $gettext('Uses pitch-preserving time adjustment when AutoDJ backtimes music into a protected boundary. This is station-wide and applies to standard rotation playlists, Smart Blocks and Clock Wheels when their selected track has stretch/squeeze timing metadata.') }}
                    </p>

                    <form-group id="stretch_squeeze_enabled" class="mb-3">
                        <template #label>
                            {{ $gettext('Enable stretch / squeeze') }}
                        </template>
                        <form-checkbox id="stretch_squeeze_enabled" v-model="form.stretch_squeeze_enabled" />
                    </form-group>

                    <template v-if="form.stretch_squeeze_enabled">
                        <form-group id="stretch_squeeze_max_percent" class="mb-3">
                            <template #label>
                                {{ $gettext('Maximum timing adjustment (%)') }}
                            </template>
                            <input
                                id="stretch_squeeze_max_percent"
                                v-model.number="form.stretch_squeeze_max_percent"
                                type="number"
                                class="form-control"
                                min="0.5"
                                max="5"
                                step="0.5"
                            >
                            <div class="form-text">
                                {{ $gettext('The existing safe limit is 5%. Lower values reduce how much AutoDJ may speed up or slow down a track.') }}
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

                    <form-group id="smart_duck_enabled" class="mb-3">
                        <template #label>
                            {{ $gettext('Enable smart ducking') }}
                        </template>
                        <form-checkbox id="smart_duck_enabled" v-model="form.smart_duck_enabled" />
                    </form-group>

                    <template v-if="form.smart_duck_enabled">
                        <form-group id="smart_duck_attenuation" class="mb-3">
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

                        <form-group id="smart_duck_delay" class="mb-3">
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

                    <div class="alert alert-info mb-0">
                        {{ $gettext('Ducking and Liquidsoap hard-clock configuration changes take effect after broadcasting is restarted. Stretch / Squeeze does not require a broadcasting restart; new settings apply to newly planned AutoDJ items while items already in the queue keep their existing timing plan.') }}
                    </div>
                </div>
            </loading>

            <template #footer_actions>
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="isLoading || isSaving"
                >
                    {{ $gettext('Save Changes') }}
                </button>
            </template>
        </card-page>
    </form>

    <card-page class="mt-4" header-id="hdr_live_playout_actions">
        <template #header="{id}">
            <h2 :id="id" class="card-title my-0">
                {{ $gettext('Live Playout Actions') }}
            </h2>
        </template>

        <info-card>
            <p class="mb-0">
                {{ $gettext('Operator controls for starting a playlist, scheduling a one-time playlist window, or immediately escaping incorrect on-air content.') }}
            </p>
        </info-card>

        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-7">
                    <h3 class="h6">
                        {{ $gettext('Start a Playlist') }}
                    </h3>
                    <p class="text-secondary small">
                        {{ $gettext('Only enabled song-based playlists are shown. Manual starts intentionally override the playlist\'s normal schedule; disabled playlists remain protected.') }}
                    </p>

                    <div class="mb-3">
                        <label class="form-label" for="manual_playlist_id">
                            {{ $gettext('Playlist') }}
                        </label>
                        <select
                            id="manual_playlist_id"
                            v-model.number="selectedPlaylistId"
                            class="form-select"
                            :disabled="playlistsLoading || manualBusy"
                        >
                            <option :value="null" disabled>
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
                        <label class="form-label" for="manual_playlist_mode">
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
                                <label class="form-label" for="manual_start_at">
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
                                <label class="form-label" for="manual_duration">
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
                            <label class="form-check-label" for="manual_strict_start">
                                {{ $gettext('Use a strict start time (cut the previous item if needed)') }}
                            </label>
                        </div>
                    </template>

                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="manualBusy || selectedPlaylistId === null || (manualMode === 'schedule_once' && scheduledAt === '')"
                        @click="runManualPlaylist"
                    >
                        {{ manualMode === 'play_now' ? $gettext('Play Now') : manualMode === 'schedule_once' ? $gettext('Schedule Once') : $gettext('Queue Next') }}
                    </button>
                </div>

                <div class="col-lg-5">
                    <h3 class="h6">
                        {{ $gettext('Emergency Playout') }}
                    </h3>
                    <p class="text-secondary small">
                        {{ $gettext('Skip the current item and rebuild unsent AutoDJ choices, or restart Liquidsoap to flush already-buffered bad content.') }}
                    </p>

                    <div class="d-grid gap-2 mb-4">
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
                        <label class="form-check-label" for="confirm_emergency_reset">
                            {{ $gettext('I understand this briefly interrupts the stream.') }}
                        </label>
                    </div>

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
    </card-page>
</template>

<script setup lang="ts">
import CardPage from '~/components/Common/CardPage.vue';
import InfoCard from '~/components/Common/InfoCard.vue';
import Loading from '~/components/Common/Loading.vue';
import FormGroup from '~/components/Form/FormGroup.vue';
import FormCheckbox from '~/components/Form/FormCheckbox.vue';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import type {PlayoutControlsSettings} from '~/entities/PlayoutControls.ts';
import {useAxios} from '~/vendor/axios.ts';
import {onMounted, ref} from 'vue';

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

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();

const apiUrl = getStationApiUrl('/playout-controls');
const playlistsUrl = getStationApiUrl('/playlists');
const manualPlaylistUrl = getStationApiUrl('/features/playout/manual-playlist');
const stopCurrentUrl = getStationApiUrl('/features/playout/stop-current');
const isLoading = ref(true);
const isSaving = ref(false);
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

const form = ref<PlayoutControlsSettings>({
    hard_clock_enabled: false,
    hard_clock_trigger_seconds: 3,
    hard_clock_fade_seconds: 3,
    stretch_squeeze_enabled: true,
    stretch_squeeze_max_percent: 5,
    smart_duck_enabled: false,
    smart_duck_attenuation: 0.2,
    smart_duck_delay: 3,
});

const loadSettings = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<PlayoutControlsSettings>(apiUrl.value);
        form.value = {
            hard_clock_enabled: data.hard_clock_enabled ?? false,
            hard_clock_trigger_seconds: data.hard_clock_trigger_seconds ?? 3,
            hard_clock_fade_seconds: data.hard_clock_fade_seconds ?? 3,
            stretch_squeeze_enabled: data.stretch_squeeze_enabled ?? true,
            stretch_squeeze_max_percent: data.stretch_squeeze_max_percent ?? 5,
            smart_duck_enabled: data.smart_duck_enabled ?? false,
            smart_duck_attenuation: data.smart_duck_attenuation ?? 0.2,
            smart_duck_delay: data.smart_duck_delay ?? 3,
        };
    } finally {
        isLoading.value = false;
    }
};

const loadPlaylists = async () => {
    playlistsLoading.value = true;
    try {
        const {data} = await axios.get<PlaylistListResponse>(playlistsUrl.value, {
            params: {
                internal: true,
                rowCount: 0,
            },
        });

        playlists.value = (data.rows ?? [])
            .filter((playlist) => playlist.source === 'songs' && playlist.is_enabled)
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

const runManualPlaylist = async () => {
    if (selectedPlaylistId.value === null) {
        return;
    }

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
        const {data} = await axios.post<StatusResponse>(stopCurrentUrl.value, {
            hard_reset: hardReset,
        });
        notifySuccess(data.message);
        confirmEmergencyReset.value = false;
    } catch {
        notifyError();
    } finally {
        emergencyBusy.value = false;
    }
};

onMounted(() => {
    void loadSettings();
    void loadPlaylists();
});
</script>
