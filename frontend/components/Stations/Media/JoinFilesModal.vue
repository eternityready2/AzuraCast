<template>
    <modal
        id="join_files"
        ref="$modal"
        size="lg"
        centered
        :busy="loading"
        :title="$gettext('Join Files into One')"
        @shown="onShown"
        @hidden="reset"
    >
        <div
            v-if="job"
            class="text-center py-4"
        >
            <div
                class="spinner-border text-primary"
                role="status"
            />
            <div class="mt-3 fw-semibold">
                {{ $gettext('Joining the files...') }}
            </div>
            <div class="small text-body-secondary mt-1">
                {{ job.reencode
                    ? $gettext('These files are in different formats, so the joined file is re-encoded. A long program can take a few minutes. You can close this box; the new file appears in this folder when it is done.')
                    : $gettext('The new file appears in this folder when it is done. You can close this box.') }}
            </div>
        </div>

        <template v-else>
            <p class="small text-body-secondary">
                {{ $gettext('Use this for a program that comes in several parts. The files are joined in the order below into one new file in the same folder. The original files are not changed. Drag a row, or use the arrows, to change the order.') }}
            </p>

            <table class="table table-striped align-middle sortable mb-3">
                <tbody ref="$tbody">
                    <tr
                        v-for="(part, index) in parts"
                        :key="part.path"
                    >
                        <td style="width: 2.5rem">
                            {{ index + 1 }}
                        </td>
                        <td>
                            <span class="typography-subheading">{{ part.title }}</span>
                            <div class="small text-body-secondary">
                                {{ part.name }}
                            </div>
                        </td>
                        <td class="text-end font-monospace small">
                            {{ part.length }}
                        </td>
                        <td
                            class="text-end"
                            style="width: 6rem"
                        >
                            <div class="btn-group btn-group-sm">
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    :title="$gettext('Move Up')"
                                    :disabled="index === 0"
                                    @click.prevent="move(index, -1)"
                                >
                                    <icon-bi-chevron-up/>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    :title="$gettext('Move Down')"
                                    :disabled="index + 1 === parts.length"
                                    @click.prevent="move(index, 1)"
                                >
                                    <icon-bi-chevron-down/>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="mb-3">
                <label
                    class="form-label"
                    for="join_files_name"
                >
                    {{ $gettext('Name of the new file') }}
                </label>
                <input
                    id="join_files_name"
                    v-model="name"
                    type="text"
                    class="form-control"
                    maxlength="150"
                >
                <div class="form-text">
                    {{ $gettext('Also used as the title. The artist, type, category and cover art are copied from the first file.') }}
                </div>
            </div>

            <div class="form-check">
                <input
                    id="join_files_replace"
                    v-model="replaceInPlaylists"
                    class="form-check-input"
                    type="checkbox"
                >
                <label
                    class="form-check-label"
                    for="join_files_replace"
                >
                    {{ $gettext('Put the joined file in the playlist in place of the parts') }}
                </label>
                <div class="form-text">
                    {{ $gettext('Applies to every playlist that holds all of these files: the joined file takes their position and the parts are taken out of that playlist. The parts stay in the library.') }}
                </div>
            </div>
        </template>

        <template #modal-footer>
            <button
                type="button"
                class="btn btn-secondary"
                @click="hide"
            >
                {{ $gettext('Close') }}
            </button>
            <button
                v-if="!job"
                type="button"
                class="btn btn-primary"
                :disabled="loading || parts.length < 2 || name.trim() === ''"
                @click="execute"
            >
                {{ $gettext('Join') }}
            </button>
        </template>
    </modal>
</template>

<script setup lang="ts">
import {onUnmounted, ref, useTemplateRef} from "vue";
import {useDraggable} from "vue-draggable-plus";
import Modal from "~/components/Common/Modal.vue";
import {useHasModal} from "~/functions/useHasModal.ts";
import {useTranslate} from "~/vendor/gettext.ts";
import {useAxios} from "~/vendor/axios.ts";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import type {MediaSelectedItems} from "~/components/Stations/Media.vue";
import IconBiChevronDown from "~icons/bi/chevron-down";
import IconBiChevronUp from "~icons/bi/chevron-up";

type JoinPart = {
    path: string,
    name: string,
    title: string,
    length: string,
};

type JoinJob = {
    job: string,
    path: string,
    reencode: boolean,
    seconds: number,
};

type JoinStatus = {
    status: 'running' | 'done' | 'failed',
    path: string | null,
    error: string | null,
};

type JoinBatchResponse<T> = {
    success: boolean,
    errors: string[],
    record: T | null,
};

const props = defineProps<{
    selectedItems: MediaSelectedItems,
    currentDirectory: string,
    batchUrl: string,
}>();

const emit = defineEmits<{
    (e: 'relist'): void,
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifyError, notifySuccess} = useNotify();

const loading = ref(false);
const parts = ref<JoinPart[]>([]);
const name = ref('');
const replaceInPlaylists = ref(true);
const job = ref<JoinJob | null>(null);

const $modal = useTemplateRef('$modal');
const {show, hide} = useHasModal($modal);

const $tbody = useTemplateRef('$tbody');

const onShown = () => {
    useDraggable($tbody, parts);
};

const reset = () => {
    loading.value = false;
    job.value = null;
};

const open = () => {
    // Part 2 before part 10, whatever order the files were ticked in.
    const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});

    parts.value = props.selectedItems.all
        .filter((row) => !!row.media)
        .map((row) => ({
            path: row.path,
            name: row.path_short,
            title: row.media?.title || row.path_short,
            length: row.media?.length_text ?? '',
        }))
        .sort((a, b) => collator.compare(a.name, b.name));

    name.value = '';
    replaceInPlaylists.value = true;
    job.value = null;

    show();
};

const move = (index: number, by: number) => {
    const moved = parts.value.splice(index, 1)[0];
    parts.value.splice(index + by, 0, moved);
};

// A join outlives this box: it is followed until it ends, open or closed.
let pollTimer: number | null = null;

const stopPolling = () => {
    if (pollTimer !== null) {
        window.clearTimeout(pollTimer);
        pollTimer = null;
    }
};

const poll = (running: JoinJob) => {
    pollTimer = window.setTimeout(() => {
        void (async () => {
            let status: JoinStatus | null = null;
            try {
                const {data} = await axios.put<JoinBatchResponse<JoinStatus>>(props.batchUrl, {
                    do: 'join-status',
                    job: running.job,
                });
                status = data.record;
            } catch {
                // A dropped request is not the join failing; ask again.
            }

            if (status === null || status.status === 'running') {
                poll(running);
                return;
            }

            if (status.status === 'done') {
                notifySuccess($gettext('Files joined into: %{path}', {path: status.path ?? running.path}));
                emit('relist');
            } else {
                notifyError(status.error ?? $gettext('The files could not be joined.'));
            }

            if (job.value?.job === running.job) {
                hide();
            }
        })();
    }, 2000);
};

const execute = async () => {
    loading.value = true;

    try {
        const {data} = await axios.put<JoinBatchResponse<JoinJob>>(props.batchUrl, {
            do: 'join',
            current_directory: props.currentDirectory,
            files: parts.value.map((part) => part.path),
            dirs: [],
            name: name.value,
            replace_in_playlists: replaceInPlaylists.value,
        });

        if (!data.success || data.record === null) {
            notifyError(data.errors.join(' ') || $gettext('The files could not be joined.'));
            return;
        }

        job.value = data.record;
        poll(data.record);
    } catch {
        notifyError($gettext('The files could not be joined.'));
    } finally {
        loading.value = false;
    }
};

onUnmounted(stopPolling);

defineExpose({open});
</script>
