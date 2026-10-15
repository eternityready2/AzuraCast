<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_live_controls"
    >
        <div class="card-header text-bg-primary">
            <h2
                id="hdr_live_controls"
                class="card-title"
            >
                {{ $gettext('Live Controls') }}
            </h2>
        </div>

        <div class="card-body">
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
        </div>
    </section>
</template>

<script setup lang="ts">
import InfoCard from '~/components/Common/InfoCard.vue';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios.ts';
import {onMounted, ref} from 'vue';

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();

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

onMounted(() => {
    void loadPlaylists();
});
</script>
