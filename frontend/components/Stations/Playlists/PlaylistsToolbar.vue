<template>
    <div
        class="playlists-toolbar"
        role="toolbar"
        :aria-label="$gettext('Playlist tools')"
    >
        <div
            v-if="canStartOnAir"
            class="playlists-toolbar-section"
        >
            <span class="playlists-toolbar-label">
                {{ $gettext('On Air') }}
                <span class="playlists-toolbar-hint">{{ $gettext('tick one playlist') }}</span>
            </span>
            <div class="playlists-toolbar-controls">
                <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    :disabled="!onAirPlaylist || busy"
                    :title="$gettext('Stop the current song and start this playlist now. Tick one enabled song-based playlist.')"
                    @click="startOnAir('play_now')"
                >
                    <icon-ic-play-arrow />
                    <span>{{ $gettext('Play Now') }}</span>
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-secondary"
                    :disabled="!onAirPlaylist || busy"
                    :title="$gettext('Play this whole playlist right after the current song. Tick one enabled song-based playlist.')"
                    @click="startOnAir('queue_next')"
                >
                    <icon-ic-queue-play-next />
                    <span>{{ $gettext('Play Next') }}</span>
                </button>
            </div>
        </div>

        <div class="playlists-toolbar-section">
            <span class="playlists-toolbar-label">
                {{ $gettext('Turn On / Off') }}
                <span class="playlists-toolbar-hint">{{ $gettext('tick one or more playlists') }}</span>
            </span>
            <div class="playlists-toolbar-controls">
                <button
                    type="button"
                    class="btn btn-sm btn-success"
                    :disabled="toEnable.length === 0 || busy"
                    :title="$gettext('Turn the ticked playlists on.')"
                    @click="toggleAll(toEnable, true)"
                >
                    {{ $gettext('Enable') }}
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-warning"
                    :disabled="toDisable.length === 0 || busy"
                    :title="$gettext('Turn the ticked playlists off.')"
                    @click="toggleAll(toDisable, false)"
                >
                    {{ $gettext('Disable') }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import {computed, ref} from "vue";
import {useTranslate} from "~/vendor/gettext";
import {useAxios} from "~/vendor/axios";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useDialog} from "~/components/Common/Dialogs/useDialog.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useStationData} from "~/functions/useStationQuery.ts";
import {useUserAllowedForStation} from "~/functions/useUserallowedForStation.ts";
import {BackendAdapters, StationPermissions} from "~/entities/ApiInterfaces.ts";
import IconIcPlayArrow from "~icons/ic/baseline-play-arrow";
import IconIcQueuePlayNext from "~icons/ic/baseline-queue-play-next";

export interface ToolbarPlaylist {
    id: number,
    name: string,
    source: string,
    order: string,
    is_enabled: boolean,
    links: {
        toggle: string,
    },
}

const props = defineProps<{
    selected: ToolbarPlaylist[],
    // Reloads the list; awaited so the buttons stay locked until fresh rows arrive.
    refresh: () => Promise<void>,
}>();

const emit = defineEmits<{
    (e: 'needs-restart'): void,
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess} = useNotify();
const {showAlert} = useDialog();
const {getStationApiUrl} = useApiRouter();
const {userAllowedForStation} = useUserAllowedForStation();
const stationData = useStationData();

const manualPlaylistUrl = getStationApiUrl('/features/playout/manual-playlist');

const busy = ref(false);

const single = computed<ToolbarPlaylist | null>(
    () => (props.selected.length === 1) ? props.selected[0] : null
);

// Starting a playlist on air needs Liquidsoap and the Broadcasting permission,
// the same rule the station home page uses for Skip Song.
const canStartOnAir = computed(
    () => stationData.value.backendType === BackendAdapters.Liquidsoap
        && userAllowedForStation(StationPermissions.Broadcasting)
);

const onAirPlaylist = computed<ToolbarPlaylist | null>(() => {
    const p = single.value;
    return (p && p.source === 'songs' && p.is_enabled) ? p : null;
});

const toEnable = computed(() => props.selected.filter((p) => !p.is_enabled));
const toDisable = computed(() => props.selected.filter((p) => p.is_enabled));

// Runs one request per playlist and returns how many succeeded.
// Failures already show their own error toast. The buttons stay locked
// until the list has reloaded, so a second click never acts on old rows
// (toggle flips the state, so a stale second Enable would turn them off).
const runEach = async (
    playlists: ToolbarPlaylist[],
    request: (p: ToolbarPlaylist) => Promise<unknown>
): Promise<number> => {
    busy.value = true;
    let done = 0;
    try {
        for (const p of playlists) {
            try {
                await request(p);
                done++;
            } catch {
                // Toast shown by the axios error handler.
            }
        }

        if (done > 0) {
            emit('needs-restart');
            await props.refresh();
        }
    } finally {
        busy.value = false;
    }
    return done;
};

const toggleAll = async (playlists: ToolbarPlaylist[], enable: boolean) => {
    const done = await runEach(playlists, (p) => axios.put(p.links.toggle));
    if (done > 0) {
        notifySuccess(enable
            ? $gettext('Playlists enabled: %{count}', {count: String(done)})
            : $gettext('Playlists disabled: %{count}', {count: String(done)}));
    }
};

const startOnAir = async (mode: 'play_now' | 'queue_next') => {
    const p = onAirPlaylist.value;
    if (!p) {
        return;
    }

    if (mode === 'play_now') {
        const {value} = await showAlert({
            title: $gettext('Stop the current song and play "%{name}" now?', {name: p.name}),
            confirmButtonText: $gettext('Play Now'),
            confirmButtonClass: 'btn-primary',
        });
        if (!value) {
            return;
        }
    }

    busy.value = true;
    try {
        const {data} = await axios.post(manualPlaylistUrl.value, {
            playlist_id: p.id,
            mode,
        });
        notifySuccess(data.message);
    } catch {
        // Toast shown by the axios error handler.
    } finally {
        busy.value = false;
    }
};
</script>

<style lang="scss" scoped>
// Neutral gray panel with the same 1px border and corners as the search box.
// The panel hugs its tools (no empty space on the right) and sits centered.
.playlists-toolbar {
    width: fit-content;
    max-width: calc(100% - 2rem);
    margin: 0 auto 0.75rem;
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    background: var(--bs-tertiary-bg);
    // One row: On Air, Turn On / Off.
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 1.25rem;
}

.playlists-toolbar-label {
    display: block;
    color: var(--bs-emphasis-color);
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.075em;
    text-transform: uppercase;
}

.playlists-toolbar-hint {
    margin-left: 0.3rem;
    font-weight: 600;
    letter-spacing: normal;
    text-transform: none;
    color: var(--bs-body-color);
}

.playlists-toolbar-section {
    flex: 0 1 auto;
    min-width: 0;
    // Groups have no box of their own: the labels and spacing separate them.
    padding: 0.15rem 0.1rem;

    > .playlists-toolbar-label {
        margin-bottom: 0.3rem;
    }
}

// Faint line between On Air and Turn On / Off: their tick rules differ.
.playlists-toolbar-section + .playlists-toolbar-section {
    padding-left: 1.25rem;
    border-left: 1px solid var(--bs-border-color);
}

.playlists-toolbar-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem;
}

.playlists-toolbar .btn {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    min-height: 1.85rem;
    padding: 0.15rem 0.55rem;
    border-radius: var(--bs-border-radius);
    font-weight: 600;
    letter-spacing: 0.01em;
}

// Disabled buttons still show their hover hint ("Tick one playlist")
// but cannot be clicked: the native disabled attribute blocks clicks.
.playlists-toolbar .btn:disabled {
    pointer-events: auto;
    cursor: not-allowed;
}

@media (max-width: 767.98px) {
    .playlists-toolbar {
        width: auto;
        max-width: none;
        margin: 0 0.5rem 0.75rem;
        padding: 0.4rem;
    }

    .playlists-toolbar-section {
        flex: 1 1 100%;
    }

    // Stacked on small screens: the line goes between the rows instead.
    .playlists-toolbar-section + .playlists-toolbar-section {
        padding-top: 0.6rem;
        padding-left: 0.1rem;
        border-top: 1px solid var(--bs-border-color);
        border-left: 0;
    }
}
</style>
