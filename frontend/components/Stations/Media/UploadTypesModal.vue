<template>
    <modal
        id="upload_types_modal"
        ref="$modal"
        size="lg"
        :title="$gettext('Select types for the files that you want to upload')"
        @hidden="onHidden"
    >
        <div class="upload-types">
            <div class="upload-types-bulk">
                <div class="upload-types-bulk-row">
                    <label
                        class="upload-types-bulk-label"
                        for="upload_types_bulk_type"
                    >{{ $gettext('Type') }}</label>
                    <select
                        id="upload_types_bulk_type"
                        v-model="bulkType"
                        class="form-select form-select-sm"
                    >
                        <option
                            v-for="opt in typeOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary text-nowrap"
                        :disabled="selectedCount === 0"
                        @click="applyType"
                    >
                        {{ $gettext('Apply type to selected tracks') }}
                    </button>
                </div>
                <p class="upload-types-help mb-0">
                    {{ $gettext('This will apply the changes to all the selected files from the list below.') }}
                </p>
            </div>

            <div class="upload-types-table">
                <div class="upload-types-row upload-types-head">
                    <div class="col-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            :checked="allSelected"
                            :indeterminate.prop="selectedCount > 0 && !allSelected"
                            :aria-label="$gettext('Select all')"
                            @change="toggleAll"
                        >
                    </div>
                    <div class="col-name">
                        {{ $gettext('Name') }}
                    </div>
                    <div class="col-type">
                        {{ $gettext('Type') }}
                    </div>
                    <div class="col-actions" />
                </div>

                <div
                    v-for="(row, index) in rows"
                    :key="row.uniqueIdentifier"
                    class="upload-types-row"
                    :class="{'is-selected': row.selected}"
                >
                    <div class="col-check">
                        <input
                            v-model="row.selected"
                            class="form-check-input"
                            type="checkbox"
                            :aria-label="$gettext('Select')"
                        >
                    </div>
                    <div
                        class="col-name"
                        :title="row.relativePath"
                    >
                        <span class="file-name">{{ row.name }}</span>
                        <small class="file-size">{{ formatFileSize(row.size) }}</small>
                    </div>
                    <div class="col-type">
                        <select
                            v-model="row.type"
                            class="form-select form-select-sm"
                            :aria-label="$gettext('Type')"
                            @change="row.touched = true"
                        >
                            <option
                                v-for="opt in typeOptions"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.short }}
                            </option>
                        </select>
                    </div>
                    <div class="col-actions">
                        <button
                            type="button"
                            class="btn btn-sm btn-link text-danger p-1"
                            :title="$gettext('Remove')"
                            @click="removeRow(index)"
                        >
                            <icon-ic-delete />
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-link p-1"
                            :disabled="index === 0"
                            :title="$gettext('Move up')"
                            @click="moveRow(index, -1)"
                        >
                            <icon-ic-arrow-upward />
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-link p-1"
                            :disabled="index === rows.length - 1"
                            :title="$gettext('Move down')"
                            @click="moveRow(index, 1)"
                        >
                            <icon-ic-arrow-downward />
                        </button>
                    </div>
                </div>

                <div
                    v-if="rows.length === 0"
                    class="upload-types-empty"
                >
                    {{ $gettext('No files selected.') }}
                </div>
            </div>
        </div>

        <template #modal-footer>
            <div class="w-100">
                <p class="upload-types-note text-center mb-2">
                    {{ $gettext('You will be able to review the tracks when the upload will be completed.') }}
                </p>
                <div class="d-flex justify-content-between gap-2">
                    <button
                        type="button"
                        class="btn btn-secondary"
                        @click="cancel"
                    >
                        {{ $gettext('Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="rows.length === 0"
                        @click="upload"
                    >
                        {{ $gettext('Upload') }}
                    </button>
                </div>
            </div>
        </template>
    </modal>
</template>

<script setup lang="ts">
import Modal from "~/components/Common/Modal.vue";
import {computed, ref, useTemplateRef} from "vue";
import {useTranslate} from "~/vendor/gettext";
import {formatMediaType, getMediaTypeOptions, MediaTypeValue} from "~/functions/mediaTypes.ts";
import formatFileSize from "~/functions/formatFileSize";
import {guessMediaType, readAudioDuration} from "~/functions/guessMediaType.ts";
import {QueuedUploadFile} from "~/components/Common/FlowUpload.vue";
import IconIcDelete from "~icons/ic/baseline-delete";
import IconIcArrowUpward from "~icons/ic/baseline-arrow-upward";
import IconIcArrowDownward from "~icons/ic/baseline-arrow-downward";

export type UploadTypeChoice = {
    uniqueIdentifier: string,
    type: MediaTypeValue,
};

type UploadRow = QueuedUploadFile & {
    selected: boolean,
    type: MediaTypeValue,
    // Set once the user picks a type, so a late guess never overwrites it.
    touched: boolean,
};

const props = withDefaults(
    defineProps<{
        currentDirectory?: string,
    }>(),
    {
        currentDirectory: '',
    }
);

const emit = defineEmits<{
    (e: 'upload', choices: UploadTypeChoice[]): void,
    (e: 'remove', uniqueIdentifier: string): void,
    (e: 'cancel'): void,
}>();

const {$gettext} = useTranslate();

const typeOptions = getMediaTypeOptions($gettext).map((opt) => ({
    ...opt,
    short: formatMediaType(opt.value, $gettext),
}));

const rows = ref<UploadRow[]>([]);
const bulkType = ref<MediaTypeValue>('music');

const selectedCount = computed(() => rows.value.filter((row) => row.selected).length);
const allSelected = computed(() => rows.value.length > 0 && selectedCount.value === rows.value.length);

const toggleAll = () => {
    const value = !allSelected.value;
    rows.value.forEach((row) => {
        row.selected = value;
    });
};

const applyType = () => {
    rows.value.filter((row) => row.selected).forEach((row) => {
        row.type = bulkType.value;
        row.touched = true;
    });
};

const removeRow = (index: number) => {
    const [row] = rows.value.splice(index, 1);
    if (row) {
        emit('remove', row.uniqueIdentifier);
    }
};

const moveRow = (index: number, offset: number) => {
    const target = index + offset;
    if (target < 0 || target >= rows.value.length) {
        return;
    }
    const [row] = rows.value.splice(index, 1);
    rows.value.splice(target, 0, row);
};

const $modal = useTemplateRef('$modal');

// Set when the box closes by Upload, so closing it any other way cancels the held files.
let submitted = false;

/** Open the box, or add to it if it is already open (files picked or dropped again). */
const open = (files: QueuedUploadFile[]) => {
    const known = new Set(rows.value.map((row) => row.uniqueIdentifier));
    const added = files.filter((file) => !known.has(file.uniqueIdentifier));
    added.forEach((file) => {
        rows.value.push({
            ...file,
            selected: false,
            type: guessMediaType(file.name, file.relativePath, props.currentDirectory, null).type,
            touched: false,
        });
    });

    submitted = false;
    $modal.value?.show();

    void guessFromLength(added);
};

// Name and folder guesses are instant; length needs the file read, so it fills in a moment later.
const guessFromLength = async (files: QueuedUploadFile[]) => {
    for (const file of files) {
        if (!file.file) {
            continue;
        }
        const duration = await readAudioDuration(file.file);
        const row = rows.value.find((r) => r.uniqueIdentifier === file.uniqueIdentifier);
        if (row && !row.touched) {
            row.type = guessMediaType(file.name, file.relativePath, props.currentDirectory, duration).type;
        }
    }
};

const upload = () => {
    submitted = true;
    emit('upload', rows.value.map((row) => ({
        uniqueIdentifier: row.uniqueIdentifier,
        type: row.type,
    })));
    $modal.value?.hide();
};

const cancel = () => {
    $modal.value?.hide();
};

const onHidden = () => {
    if (!submitted) {
        emit('cancel');
    }
    submitted = false;
    rows.value = [];
};

defineExpose({
    open
});
</script>

<style lang="scss">
// Teleported to <body>, so this can't be scoped. Keeps the box compact like the Live365 one.
#upload_types_modal {
    --bs-modal-width: 640px;
}
</style>

<style lang="scss" scoped>
.upload-types-bulk {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    margin-bottom: 0.75rem;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    background: var(--bs-tertiary-bg);
}

.upload-types-bulk-row {
    display: grid;
    grid-template-columns: 3rem minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.5rem;
}

.upload-types-bulk-label {
    font-size: 0.85rem;
    font-weight: 600;
}

.upload-types-help,
.upload-types-note {
    color: var(--bs-secondary-color);
    font-size: 0.8rem;
}

.upload-types-table {
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    max-height: 50vh;
    overflow-y: auto;
}

.upload-types-row {
    display: grid;
    grid-template-columns: 1.5rem minmax(0, 1fr) 9rem 5.25rem;
    align-items: center;
    gap: 0.5rem;
    padding: 0.3rem 0.6rem;
    border-top: 1px solid var(--bs-border-color);
    font-size: 0.875rem;

    &:first-child {
        border-top: 0;
    }

    &.is-selected {
        background: rgba(var(--bs-primary-rgb), 0.08);
    }
}

.upload-types-head {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--bs-body-bg);
    font-weight: 600;
    font-size: 0.8rem;
    color: var(--bs-secondary-color);
}

.col-name {
    min-width: 0;
    display: flex;
    flex-direction: column;
    line-height: 1.25;

    .file-name {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .file-size {
        color: var(--bs-secondary-color);
        font-size: 0.75rem;
    }
}

.col-actions {
    display: flex;
    justify-content: flex-end;

    .btn {
        line-height: 1;
    }
}

.upload-types-empty {
    padding: 1.25rem;
    text-align: center;
    color: var(--bs-secondary-color);
}

@media (max-width: 575.98px) {
    .upload-types-bulk-row {
        grid-template-columns: minmax(0, 1fr) auto;

        .upload-types-bulk-label {
            grid-column: 1 / -1;
        }
    }

    .upload-types-row {
        grid-template-columns: 1.5rem minmax(0, 1fr) 5.25rem;

        .col-type {
            grid-column: 2 / span 2;
        }
    }

    .upload-types-head .col-type {
        display: none;
    }
}
</style>
