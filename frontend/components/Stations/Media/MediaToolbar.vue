<template>
    <!-- Extra tools: song details and file tools for the ticked files. -->
    <div
        class="station-toolbar"
        role="toolbar"
        :aria-label="$gettext('Media tools')"
    >
        <div class="station-toolbar-rows">
            <div class="station-toolbar-row">
                <div
                    class="station-toolbar-selection"
                    :class="{'is-active': selectedItems.all.length > 0}"
                >
                    <icon-ic-check-box class="station-toolbar-selection-icon" />
                    <span v-if="selectedItems.all.length > 0">
                        {{ $gettext('%{count} selected', {count: String(selectedItems.all.length)}) }}
                    </span>
                    <span v-else>
                        {{ $gettext('Tick files to use these tools') }}
                    </span>
                </div>

                <div class="station-toolbar-section">
                    <span class="station-toolbar-label">
                        {{ $gettext('Song Details') }}
                    </span>
                    <div class="station-toolbar-controls">
                        <select
                            id="bulk_media_type"
                            class="form-select form-select-sm bulk-classify-select"
                            :disabled="!hasSelectedClassifyItems || classifyPending"
                            :title="$gettext('Set type on selected files')"
                            @change="onBulkTypeChange"
                        >
                            <option
                                value=""
                                selected
                                disabled
                                hidden
                            >
                                {{ $gettext('Set type…') }}
                            </option>
                            <option
                                v-for="opt in mediaTypeOptions"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.shortLabel }}
                            </option>
                        </select>

                        <select
                            id="bulk_media_category"
                            class="form-select form-select-sm bulk-classify-select"
                            :disabled="!hasSelectedClassifyItems || classifyPending"
                            :title="$gettext('Set category on selected files')"
                            @change="onBulkCategoryChange"
                        >
                            <option
                                value=""
                                selected
                                disabled
                                hidden
                            >
                                {{ $gettext('Set category…') }}
                            </option>
                            <option value="none">
                                {{ $gettext('No category') }}
                            </option>
                            <option
                                v-for="cat in mediaCategories"
                                :key="cat.id"
                                :value="String(cat.id)"
                            >
                                {{ cat.name }}
                            </option>
                        </select>

                        <div class="input-group input-group-sm bulk-genre-group">
                            <input
                                id="bulk_media_genre"
                                v-model="bulkGenre"
                                type="text"
                                class="form-control"
                                :disabled="!hasSelectedClassifyItems || classifyPending"
                                :title="$gettext('Set genre on selected files')"
                                :placeholder="$gettext('Set genre…')"
                                @keyup.enter="onBulkGenreApply"
                            >

                            <button
                                type="button"
                                class="btn btn-primary"
                                :disabled="!hasSelectedClassifyItems || classifyPending || bulkGenre.trim() === ''"
                                @click="onBulkGenreApply"
                            >
                                {{ $gettext('Apply Genre') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="station-toolbar-row">
                <div class="station-toolbar-section">
                    <span class="station-toolbar-label">
                        {{ $gettext('Tools') }}
                    </span>
                    <div class="station-toolbar-controls">
                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            @click="downloadFromUrl"
                        >
                            <icon-ic-cloud-download/>
                            <span>
                                {{ $gettext('Download from URL') }}
                            </span>
                        </button>

                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            :disabled="!hasSelectedClassifyItems"
                            :title="$gettext('Find the album, record label, year, genre, ISRC, songwriters and cover art online for the songs you ticked. You review what was found before anything is saved.')"
                            @click="lookUpSelected"
                        >
                            <icon-ic-travel-explore/>

                            <span>
                                {{ $gettext('Find Song Info Online') }}
                            </span>
                        </button>

                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            :disabled="!canJoin"
                            :title="$gettext('Tick two or more files, such as the parts of a program, to combine them into one new file. The original files are kept.')"
                            @click="joinFiles"
                        >
                            <icon-ic-merge/>

                            <span>
                                {{ $gettext('Join Files into One') }}
                            </span>
                        </button>

                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            :disabled="!hasSelectedClassifyItems"
                            :title="$gettext('Create or populate playlists from the MP3 genre of selected files')"
                            @click="createGenrePlaylists"
                        >
                            <icon-ic-playlist-add/>

                            <span>
                                {{ $gettext('Create Playlists from Genre') }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock AzuraCast row: file actions on the left, New Folder on the right. -->
    <div
        id="app-toolbar"
        class="d-flex pt-2"
    >
        <div class="flex-fill buttons d-flex align-items-center">
            <span>
                {{ $gettext('With selected:') }}
            </span>

            <div class="btn-group btn-group-sm dropdown allow-focus">
                <div class="dropdown">
                    <button
                        ref="$playlistDropdown"
                        class="btn btn-sm btn-primary dropdown-toggle"
                        type="button"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        :disabled="!hasSelectedItems"
                    >
                        <icon-ic-clear-all/>

                        <span>
                            {{ $gettext('Playlists') }}
                        </span>
                        <span class="caret" />
                    </button>
                    <div
                        class="dropdown-menu"
                        style="min-width: 300px;"
                    >
                        <form
                            class="px-4 py-3"
                            @submit.prevent="setPlaylists"
                        >
                            <div
                                v-for="playlist in playlists"
                                :key="playlist.id"
                                class="form-group"
                            >
                                <div class="custom-control custom-checkbox">
                                    <input
                                        :id="'chk_playlist_' + playlist.id"
                                        v-model="checkedPlaylists"
                                        type="checkbox"
                                        class="custom-control-input"
                                        name="playlists[]"
                                        :value="playlist.id"
                                    >
                                    <label
                                        class="custom-control-label"
                                        :for="'chk_playlist_'+playlist.id"
                                    >
                                        {{ playlist.name }}
                                    </label>
                                </div>
                            </div>

                            <hr class="dropdown-divider">

                            <div class="form-group mt-3 mb-4">
                                <div class="input-group custom-control custom-checkbox">
                                    <div class="input-group-text">
                                        <input
                                            id="chk_playlist_new"
                                            v-model="checkedPlaylists"
                                            type="checkbox"
                                            class="custom-control-input"
                                            value="new"
                                        >
                                        <label
                                            class="custom-control-label"
                                            for="chk_playlist_new"
                                        />
                                    </div>

                                    <input
                                        id="new_playlist_name"
                                        v-model="newPlaylist"
                                        type="text"
                                        class="form-control p-2"
                                        name="new_playlist_name"
                                        style="min-width: 150px;"
                                        :placeholder="$gettext('New Playlist')"
                                    >
                                </div>
                            </div>

                            <div class="buttons">
                                <button
                                    class="btn btn-primary"
                                    type="submit"
                                >
                                    {{ $gettext('Save') }}
                                </button>
                                <button
                                    class="btn btn-warning"
                                    type="button"
                                    @click="clearPlaylists()"
                                >
                                    {{ $gettext('Clear') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <button
                type="button"
                class="btn btn-sm btn-primary"
                :disabled="!hasSelectedItems"
                @click="moveFiles"
            >
                <icon-ic-open-with/>

                <span>
                    {{ $gettext('Move') }}
                </span>
            </button>

            <div class="btn-group btn-group-sm dropdown allow-focus">
                <div class="dropdown">
                    <button
                        class="btn btn-sm btn-secondary dropdown-toggle"
                        type="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        :disabled="!hasSelectedItems"
                    >
                        <icon-ic-more-horiz/>
                        <span>
                            {{ $gettext('More') }}
                        </span>
                        <span class="caret" />
                    </button>
                    <ul class="dropdown-menu">
                        <li>
                            <button
                                type="button"
                                :title="$gettext('Queue the selected media to play next')"
                                class="dropdown-item"
                                @click="doQueue"
                            >
                                {{ $gettext('Queue') }}
                            </button>
                        </li>
                        <li>
                            <button
                                v-if="supportsImmediateQueue"
                                type="button"
                                class="dropdown-item"
                                :title="$gettext('Make the selected media play immediately, interrupting existing media')"
                                @click="doImmediateQueue"
                            >
                                {{ $gettext('Play Now') }}
                            </button>
                        </li>
                        <li>
                            <button
                                type="button"
                                class="dropdown-item"
                                :title="$gettext('Analyze and reprocess the selected media')"
                                @click="doReprocess"
                            >
                                {{ $gettext('Reprocess') }}
                            </button>
                        </li>
                        <li>
                            <button
                                type="button"
                                class="dropdown-item"
                                :title="$gettext('Remove any extra metadata (fade points, cue points, etc.) from the selected media')"
                                @click="doClearExtra"
                            >
                                {{ $gettext('Clear Extra Metadata') }}
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <button
                type="button"
                class="btn btn-sm btn-danger"
                :disabled="!hasSelectedItems"
                @click="doDelete"
            >
                <icon-ic-delete/>

                <span>
                    {{ $gettext('Delete') }}
                </span>
            </button>
        </div>
        <div class="flex-shrink-0">
            <button
                type="button"
                class="btn btn-sm btn-primary"
                @click="createDirectory"
            >
                <icon-ic-folder/>
                <span>
                    {{ $gettext('New Folder') }}
                </span>
            </button>
        </div>
    </div>

    <genre-playlists-modal
        ref="$genrePlaylistsModal"
        :selected-items="selectedItems"
        :current-directory="currentDirectory"
        :batch-url="batchUrl"
        @relist="emit('relist')"
        @add-playlist="(playlist) => emit('add-playlist', playlist)"
    />

    <join-files-modal
        ref="$joinFilesModal"
        :selected-items="selectedItems"
        :current-directory="currentDirectory"
        :batch-url="batchUrl"
        @relist="emit('relist')"
    />

    <metadata-lookup-modal
        ref="$metadataLookupModal"
        :current-directory="currentDirectory"
        :batch-url="batchUrl"
        @relist="emit('relist')"
    />
</template>

<script setup lang="ts">
import {Dropdown} from "bootstrap";
import {filter, intersection, map} from "es-toolkit/compat";
import {computed, ref, toRef, useTemplateRef, watch} from "vue";
import {useTranslate} from "~/vendor/gettext";
import {useAxios} from "~/vendor/axios";
import useHandleBatchResponse from "~/components/Stations/Media/useHandleBatchResponse.ts";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useDialog} from "~/components/Common/Dialogs/useDialog.ts";
import type {MediaInitialPlaylist, MediaSelectedItems} from "~/components/Stations/Media.vue";
import {ApiStationMediaPlaylist} from "~/entities/ApiInterfaces";
import IconIcCheckBox from "~icons/ic/baseline-check-box";
import IconIcClearAll from "~icons/ic/baseline-clear-all";
import IconIcCloudDownload from "~icons/ic/baseline-cloud-download";
import IconIcDelete from "~icons/ic/baseline-delete";
import IconIcFolder from "~icons/ic/baseline-folder";
import IconIcMerge from "~icons/ic/baseline-merge";
import IconIcTravelExplore from "~icons/ic/baseline-travel-explore";
import IconIcMoreHoriz from "~icons/ic/baseline-more-horiz";
import IconIcOpenWith from "~icons/ic/baseline-open-with";
import IconIcPlaylistAdd from "~icons/ic/baseline-playlist-add";
import {formatMediaType, getMediaTypeOptions} from "~/functions/mediaTypes.ts";
import GenrePlaylistsModal from "~/components/Stations/Media/GenrePlaylistsModal.vue";
import JoinFilesModal from "~/components/Stations/Media/JoinFilesModal.vue";
import MetadataLookupModal from "~/components/Stations/Media/MetadataLookupModal.vue";

const props = defineProps<{
    currentDirectory: string,
    selectedItems: MediaSelectedItems,
    playlists?: MediaInitialPlaylist[],
    batchUrl: string,
    supportsImmediateQueue: boolean,
    mediaCategories?: {id: number; name: string}[],
}>();

const emit = defineEmits<{
    (e: 'relist'): void,
    (e: 'add-playlist', playlist: any): void,
    (e: 'move-files'): void,
    (e: 'create-directory'): void,
    (e: 'download-from-url'): void
}>();

const {$gettext} = useTranslate();

const selectedItems = toRef(props, 'selectedItems');

const hasSelectedItems = computed(() => {
    return selectedItems.value.all.length > 0;
});

// Joining is for media files only: at least two, and no folders.
const canJoin = computed(
    () => selectedItems.value.directories.length === 0
        && selectedItems.value.all.filter((row) => !!row.media).length >= 2
);

const hasSelectedClassifyItems = computed(
    () => selectedItems.value.files.length > 0 || selectedItems.value.directories.length > 0
);

const mediaCategories = computed(() => props.mediaCategories ?? []);
const mediaTypeOptions = computed(() =>
    getMediaTypeOptions($gettext).map((opt) => ({
        ...opt,
        shortLabel: formatMediaType(opt.value, $gettext),
    }))
);

const classifyPending = ref(false);
const bulkGenre = ref('');

const checkedPlaylists = ref<(number | string)[]>([]);
const newPlaylist = ref('');

watch(selectedItems, (items) => {
    if (items.all.length === 0) {
        checkedPlaylists.value = [];
        return;
    }

    // Get all playlists that are active on ALL selected items.
    const playlistsForItems = map(items.all, (item) => {
        const itemPlaylists = (item.dir?.playlists ?? item.media?.playlists ?? []) as Required<ApiStationMediaPlaylist>[];

        return map(
            filter(
                itemPlaylists,
                (row) => row.folder === null
            ),
            'id'
        );
    });

    // Check the checkboxes for those playlists.
    checkedPlaylists.value = intersection(...playlistsForItems);
});

watch(newPlaylist, (text: string) => {
    if (text !== '') {
        if (!checkedPlaylists.value.includes('new')) {
            checkedPlaylists.value.push('new');
        }
    }
});

const {axios} = useAxios();

const {handleBatchResponse} = useHandleBatchResponse();

const {notifyError} = useNotify();

const notifyNoFiles = () => {
    notifyError($gettext('No files selected.'));
}

const doBatch = async (
    action: string,
    successMessage: string,
    errorMessage: string,
    extraPayload: Record<string, unknown> = {},
) => {
    if (hasSelectedItems.value) {
        const {data} = await axios.put(props.batchUrl, {
            'do': action,
            'current_directory': props.currentDirectory,
            'files': selectedItems.value.files,
            'dirs': selectedItems.value.directories,
            ...extraPayload,
        });

        handleBatchResponse(data, successMessage, errorMessage);
        emit('relist');
    } else {
        notifyNoFiles();
    }
};

const applyClassify = async (payload: Record<string, unknown>) => {
    if (!hasSelectedClassifyItems.value) {
        notifyNoFiles();
        return;
    }

    classifyPending.value = true;

    try {
        await doBatch(
            'classify',
            $gettext('Updated metadata for files:'),
            $gettext('Error updating files:'),
            payload,
        );
    } finally {
        classifyPending.value = false;
    }
};

const resetBulkSelect = (select: HTMLSelectElement) => {
    select.selectedIndex = 0;
};

const onBulkTypeChange = async (event: Event) => {
    const select = event.currentTarget as HTMLSelectElement;
    const type = select.value;
    resetBulkSelect(select);

    if (!type || classifyPending.value) {
        return;
    }

    await applyClassify({media_type: type});
};

const onBulkCategoryChange = async (event: Event) => {
    const select = event.currentTarget as HTMLSelectElement;
    const category = select.value;
    resetBulkSelect(select);

    if (!category || classifyPending.value) {
        return;
    }

    await applyClassify({
        category_id: category === 'none' ? null : Number(category),
    });
};

const onBulkGenreApply = async () => {
    const genre = bulkGenre.value.trim();

    if (!genre || classifyPending.value) {
        return;
    }

    await applyClassify({genre});
    bulkGenre.value = '';
};

const doImmediateQueue = () => {
    void doBatch(
        'immediate',
        $gettext('Files played immediately:'),
        $gettext('Error queueing files:')
    );
};

const doQueue = () => {
    void doBatch(
        'queue',
        $gettext('Files queued for playback:'),
        $gettext('Error queueing files:')
    );
};

const doReprocess = () => {
    void doBatch(
        'reprocess',
        $gettext('Files marked for reprocessing:'),
        $gettext('Error reprocessing files:')
    );
};

const doClearExtra = () => {
    void doBatch(
        'clear-extra',
        $gettext('Extra metadata cleared for files:'),
        $gettext('Error reprocessing files:')
    );
};

const {confirmDelete} = useDialog();

const doDelete = async () => {
    const numFiles = selectedItems.value.all.length;
    const buttonConfirmText = $gettext(
        'Delete %{num} media files?',
        {num: String(numFiles)}
    );

    const {value} = await confirmDelete({
        title: buttonConfirmText,
        confirmButtonText: $gettext('Delete')
    });

    if (!value) {
        return;
    }

    await doBatch(
        'delete',
        $gettext('Files removed:'),
        $gettext('Error removing files:')
    );
};

const $playlistDropdown = useTemplateRef('$playlistDropdown');

const setPlaylists = async () => {
    if ($playlistDropdown.value) {
        Dropdown.getInstance($playlistDropdown.value)?.hide();
    }

    if (hasSelectedItems.value) {
        const {data} = await axios.put(props.batchUrl, {
            'do': 'playlist',
            'playlists': checkedPlaylists.value,
            'new_playlist_name': newPlaylist.value,
            'currentDirectory': props.currentDirectory,
            'files': selectedItems.value.files,
            'dirs': selectedItems.value.directories
        });

        handleBatchResponse(
            data,
            (checkedPlaylists.value.length > 0)
                ? $gettext('Playlists updated for selected files:')
                : $gettext('Playlists cleared for selected files:'),
            $gettext('Error updating playlists:')
        );

        if (data.success) {
            if (data.record) {
                emit('add-playlist', data.record);
            }

            checkedPlaylists.value = [];
            newPlaylist.value = '';
        }

        emit('relist');
    } else {
        notifyNoFiles();
    }
};

const clearPlaylists = () => {
    checkedPlaylists.value = [];
    newPlaylist.value = '';

    void setPlaylists();
};

const moveFiles = () => {
    emit('move-files');
}

const createDirectory = () => {
    emit('create-directory');
}

const downloadFromUrl = () => {
    emit('download-from-url');
}

const $genrePlaylistsModal = useTemplateRef('$genrePlaylistsModal');

const $joinFilesModal = useTemplateRef('$joinFilesModal');

const joinFiles = () => {
    $joinFilesModal.value?.open();
};

const $metadataLookupModal = useTemplateRef('$metadataLookupModal');

const lookUpSelected = () => {
    void $metadataLookupModal.value?.open(selectedItems.value.files, selectedItems.value.directories);
};

// One track, from its edit box.
const lookUp = (path: string) => {
    void $metadataLookupModal.value?.open([path], []);
};

defineExpose({lookUp});

const createGenrePlaylists = () => {
    void $genrePlaylistsModal.value?.open();
};
</script>

<style lang="scss" scoped>
// Same look and layout as the Playlists toolbar.
// Neutral gray panel with the same 1px border and corners as the search box.
// The panel hugs its tools (no empty space on the right) and sits centered.
.station-toolbar {
    width: fit-content;
    max-width: calc(100% - 2rem);
    margin: 0 auto 0.75rem;
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    background: var(--bs-tertiary-bg);
}

.station-toolbar-selection {
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

.station-toolbar-selection-icon {
    font-size: 1rem;
}

.station-toolbar-label {
    display: block;
    color: var(--bs-secondary-color);
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.075em;
    text-transform: uppercase;
}

// Two rows: the panel is as wide as the longer row, so no empty space is
// left on the sides; a row wraps only when the screen is too narrow for it.
.station-toolbar-rows {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
}

.station-toolbar-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 1.25rem;
}

.station-toolbar-section {
    flex: 0 1 auto;
    min-width: 0;
    // Groups have no box of their own: the labels and spacing separate them.
    padding: 0.15rem 0.1rem;

    > .station-toolbar-label {
        margin-bottom: 0.3rem;
    }
}

.station-toolbar-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem;
}

.station-toolbar .btn {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    min-height: 1.85rem;
    padding: 0.15rem 0.55rem;
    border-radius: var(--bs-border-radius);
    font-weight: 600;
    letter-spacing: 0.01em;
}

// Disabled buttons still show their hover hint
// but cannot be clicked: the native disabled attribute blocks clicks.
.station-toolbar .btn:disabled {
    pointer-events: auto;
    cursor: not-allowed;
}

@media (max-width: 767.98px) {
    .station-toolbar {
        width: auto;
        max-width: none;
        margin: 0 0.5rem 0.75rem;
        padding: 0.4rem;
    }

    .station-toolbar-section {
        flex: 1 1 100%;
    }
}

.bulk-classify-select {
    width: auto;
    min-width: 8.25rem;
    max-width: 10.75rem;
}

.bulk-genre-group {
    width: auto;
    min-width: 15rem;
    max-width: 18rem;
}

@media (max-width: 767.98px) {
    .bulk-classify-select,
    .bulk-genre-group {
        flex: 1 1 100%;
        width: 100%;
        max-width: none;
    }
}
</style>
