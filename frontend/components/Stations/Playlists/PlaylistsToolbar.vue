<template>
    <div
        class="playlists-toolbar"
        role="toolbar"
        :aria-label="$gettext('Playlist tools')"
    >
        <div class="playlists-toolbar-rows">
            <div class="playlists-toolbar-row">
                <div
                    class="playlists-toolbar-selection"
                    :class="{'is-active': selected.length > 0}"
                >
                    <icon-ic-check-box class="playlists-toolbar-selection-icon" />
                    <span v-if="selected.length > 0">
                        {{ $gettext('%{count} selected', {count: String(selected.length)}) }}
                    </span>
                    <span v-else>
                        {{ $gettext('Tick playlists to use these tools') }}
                    </span>
                </div>

                <div
                    v-if="canStartOnAir"
                    class="playlists-toolbar-section"
                >
                    <span class="playlists-toolbar-label">
                        {{ $gettext('On Air') }}
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
                            <span>{{ $gettext('Queue Next') }}</span>
                        </button>
                    </div>
                </div>

                <div class="playlists-toolbar-section">
                    <span class="playlists-toolbar-label">
                        {{ $gettext('Playlist') }}
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
                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            :disabled="toReshuffle.length === 0 || busy"
                            :title="$gettext('Shuffle the play order of the ticked playlists again.')"
                            @click="reshuffleAll"
                        >
                            {{ $gettext('Reshuffle') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            :disabled="!single || busy"
                            :title="$gettext('Make a copy of the ticked playlist. Tick one playlist.')"
                            @click="single && emit('clone', single)"
                        >
                            {{ $gettext('Duplicate') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-danger"
                            :disabled="toEmpty.length === 0 || busy"
                            :title="$gettext('Remove all songs from the ticked playlists. The song files stay in the library.')"
                            @click="emptyAll"
                        >
                            {{ $gettext('Empty') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="playlists-toolbar-row">
                <div class="playlists-toolbar-section">
                    <span class="playlists-toolbar-label">
                        {{ $gettext('Songs & Order') }}
                    </span>
                    <div class="playlists-toolbar-controls">
                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            :disabled="!canReorder || busy"
                            :title="$gettext('Set the play order of a sequential playlist. Tick one playlist.')"
                            @click="single && emit('reorder', single)"
                        >
                            {{ $gettext('Reorder') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            :disabled="!single?.links.queue || busy"
                            :title="$gettext('See the order AutoDJ will play this playlist\'s songs. Tick one playlist.')"
                            @click="single && emit('queue', single)"
                        >
                            {{ $gettext('Playback Queue') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            :disabled="!single?.links.import || busy"
                            :title="$gettext('Add songs to this playlist from a PLS or M3U file. Tick one playlist.')"
                            @click="single && emit('import', single)"
                        >
                            {{ $gettext('Import from PLS/M3U') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            :disabled="!single?.links.applyto || busy"
                            :title="$gettext('Add this playlist to media folders. Tick one playlist.')"
                            @click="single && emit('apply-to', single)"
                        >
                            {{ $gettext('Apply to Folders') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            :disabled="single?.source !== 'playlists' || busy"
                            :title="$gettext('Open Playlist Grouping. Tick one playlist group.')"
                            @click="emit('grouping')"
                        >
                            {{ $gettext('Playlist Grouping') }}
                        </button>
                    </div>
                </div>

                <div class="playlists-toolbar-section">
                    <span class="playlists-toolbar-label">
                        {{ $gettext('Export') }}
                    </span>
                    <div class="playlists-toolbar-controls">
                        <div class="dropdown">
                            <button
                                class="btn btn-sm btn-secondary dropdown-toggle"
                                type="button"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                :disabled="!single"
                                :title="$gettext('Download the ticked playlist. Tick one playlist.')"
                            >
                                <icon-bi-cloud-download />
                                <span>{{ $gettext('Export') }}</span>
                            </button>
                            <!-- The menu stays mounted: Bootstrap keeps a reference to it. -->
                            <ul class="dropdown-menu">
                                <template v-if="single && single.source !== 'playlists'">
                                    <li
                                        v-for="format in ['pls', 'm3u']"
                                        :key="format"
                                    >
                                        <a
                                            class="dropdown-item"
                                            :href="single.links.export[format]"
                                            target="_blank"
                                        >
                                            {{ $gettext('Export %{format}', {format: format.toUpperCase()}) }}
                                        </a>
                                    </li>
                                </template>
                                <li v-if="single">
                                    <a
                                        class="dropdown-item"
                                        :href="exportConfigUrl(single.id)"
                                        target="_blank"
                                    >
                                        {{ $gettext('Export JSON') }}
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
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
import IconIcCheckBox from "~icons/ic/baseline-check-box";
import IconIcPlayArrow from "~icons/ic/baseline-play-arrow";
import IconIcQueuePlayNext from "~icons/ic/baseline-queue-play-next";
import IconBiCloudDownload from "~icons/bi/cloud-download";

export interface ToolbarPlaylist {
    id: number,
    name: string,
    source: string,
    order: string,
    is_enabled: boolean,
    links: {
        toggle: string,
        clone: string,
        export: Record<string, string>,
        order?: string,
        members?: string,
        queue?: string,
        import?: string,
        applyto?: string,
        empty?: string,
        reshuffle?: string,
    },
}

const props = defineProps<{
    selected: ToolbarPlaylist[],
    // Reloads the list; awaited so the buttons stay locked until fresh rows arrive.
    refresh: () => Promise<void>,
}>();

const emit = defineEmits<{
    (e: 'needs-restart'): void,
    (e: 'clone' | 'reorder' | 'queue' | 'import' | 'apply-to', playlist: ToolbarPlaylist): void,
    (e: 'grouping'): void,
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess} = useNotify();
const {showAlert, confirmDelete} = useDialog();
const {getStationApiUrl} = useApiRouter();
const {userAllowedForStation} = useUserAllowedForStation();
const stationData = useStationData();

const manualPlaylistUrl = getStationApiUrl('/features/playout/manual-playlist');
const exportConfigUrl = (playlistId: number): string =>
    getStationApiUrl(`/playlist/${playlistId}/export-config`).value;

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

const canReorder = computed(() => {
    const p = single.value;
    if (!p) {
        return false;
    }
    return !!p.links.order
        || (p.source === 'playlists' && p.order === 'sequential' && !!p.links.members);
});

const toEnable = computed(() => props.selected.filter((p) => !p.is_enabled));
const toDisable = computed(() => props.selected.filter((p) => p.is_enabled));
const toReshuffle = computed(() => props.selected.filter((p) => !!p.links.reshuffle));
const toEmpty = computed(() => props.selected.filter((p) => !!p.links.empty));

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

const reshuffleAll = async () => {
    const done = await runEach(toReshuffle.value, (p) => axios.put(p.links.reshuffle!));
    if (done > 0) {
        notifySuccess($gettext('Playlists reshuffled: %{count}', {count: String(done)}));
    }
};

const emptyAll = async () => {
    const targets = toEmpty.value;
    const {value} = await confirmDelete({
        title: (targets.length === 1)
            ? $gettext('Clear all media from playlist?')
            : $gettext('Clear all media from %{count} playlists?', {count: String(targets.length)}),
        confirmButtonText: $gettext('Empty'),
    });
    if (!value) {
        return;
    }

    const done = await runEach(targets, (p) => axios.delete(p.links.empty!));
    if (done > 0) {
        notifySuccess($gettext('Playlists emptied: %{count}', {count: String(done)}));
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
}

.playlists-toolbar-selection {
    display: inline-flex;
    align-self: center;
    align-items: center;
    gap: 0.4rem;
    min-height: 1.75rem;
    padding: 0 0.6rem;
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    background: var(--bs-body-bg);
    color: var(--bs-secondary-color);
    font-size: 0.76rem;
    font-weight: 600;
    white-space: nowrap;

    &.is-active {
        border-color: var(--bs-primary);
        color: var(--bs-primary-text-emphasis);
    }
}

.playlists-toolbar-selection-icon {
    font-size: 1rem;
}

.playlists-toolbar-label {
    display: block;
    color: var(--bs-secondary-color);
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.075em;
    text-transform: uppercase;
}

// Two fixed rows: the panel is as wide as the longer row, so no empty
// space is left on the sides (a wrapping row would stretch it full width).
.playlists-toolbar-rows {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
}

.playlists-toolbar-row {
    display: flex;
    flex-wrap: nowrap;
    justify-content: center;
    gap: 1.25rem;
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

// Narrower screens: let a row wrap rather than push the page sideways.
@media (max-width: 1199.98px) {
    .playlists-toolbar-row {
        flex-wrap: wrap;
    }
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
}
</style>
