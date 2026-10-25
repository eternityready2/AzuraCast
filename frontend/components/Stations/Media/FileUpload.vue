<template>
    <flow-upload
        ref="$flowUpload"
        class="station-media-upload"
        :target-url="targetUrl"
        :valid-mime-types="validMimeTypes"
        allow-multiple
        hold-upload
        @queued="onQueued"
        @success="onSuccess"
        @complete="onComplete"
        @error="onError"
    />

    <upload-types-modal
        ref="$typesModal"
        :current-directory="currentDirectory"
        @upload="onUpload"
        @remove="onRemove"
        @cancel="onCancel"
    />
</template>

<script setup lang="ts">
import FlowUpload, {OriginalFlowFile, QueuedUploadFile, UploadResponseBody} from "~/components/Common/FlowUpload.vue";
import UploadTypesModal, {UploadTypeChoice} from "~/components/Stations/Media/UploadTypesModal.vue";
import {ReviewItem} from "~/components/Stations/Media/MediaReview.vue";
import {computed, useTemplateRef} from "vue";

const props = withDefaults(
    defineProps<{
        uploadUrl: string,
        currentDirectory: string,
        searchPhrase: string,
        validMimeTypes?: string[],
    }>(),
    {
        validMimeTypes: () => ['audio/*'],
    }
);

const emit = defineEmits<{
    (e: 'relist'): void,
    (e: 'uploaded', items: ReviewItem[]): void,
}>();

const targetUrl = computed(() => {
    const url = new URL(props.uploadUrl, document.location.href);
    url.searchParams.set('currentDirectory', props.currentDirectory);
    url.searchParams.set('searchPhrase', props.searchPhrase);

    return url.toString();
});

const $flowUpload = useTemplateRef('$flowUpload');
const $typesModal = useTemplateRef('$typesModal');

// Files picked or dropped wait here until types are chosen in the upload box.
const onQueued = (files: QueuedUploadFile[]) => {
    $typesModal.value?.open(files);
};

const onUpload = (choices: UploadTypeChoice[]) => {
    const params: Record<string, Record<string, string>> = {};
    choices.forEach((choice) => {
        params[choice.uniqueIdentifier] = {
            media_type: choice.type,
        };
    });

    $flowUpload.value?.startUpload(params, choices.map((choice) => choice.uniqueIdentifier));
};

const onRemove = (uniqueIdentifier: string) => {
    $flowUpload.value?.removeFile(uniqueIdentifier);
};

const onCancel = () => {
    $flowUpload.value?.cancelHeld();
};

let uploaded: ReviewItem[] = [];

const onSuccess = (file: OriginalFlowFile, message: UploadResponseBody | null) => {
    const media = message?.media;
    if (media) {
        uploaded.push({
            ...media,
            name: file.name,
        });
    }
};

const onComplete = () => {
    if (uploaded.length > 0) {
        emit('uploaded', uploaded);
        uploaded = [];
    }
    emit('relist');
};

const onError = () => {
    emit('relist');
};
</script>

<style lang="scss" scoped>
.station-media-upload {
    margin-bottom: 1.75rem;
}

.station-media-upload :deep(.file-drop-target) {
    display: flex;
    min-height: 8.5rem;
    align-items: center;
    justify-content: center;
    padding: 2rem 1.5rem;
    border-width: 2px;
    border-color: rgba(var(--bs-primary-rgb), 0.28);
    border-radius: 1rem;
    background:
        radial-gradient(circle at 50% 0, rgba(var(--bs-primary-rgb), 0.11), transparent 48%),
        var(--bs-body-bg);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.035),
        0 0.65rem 1.8rem rgba(0, 0, 0, 0.08);
    transition:
        border-color 160ms ease,
        box-shadow 160ms ease,
        transform 160ms ease;
}

.station-media-upload :deep(.file-drop-target.drag_over) {
    border-color: var(--bs-primary);
    box-shadow:
        inset 0 0 0 1px rgba(var(--bs-primary-rgb), 0.2),
        0 0.75rem 2rem rgba(var(--bs-primary-rgb), 0.16);
    transform: translateY(-1px);
}

.station-media-upload :deep(.file-upload) {
    padding: 0.55rem 0.9rem;
    border-radius: 0.65rem;
    font-weight: 600;
    box-shadow: 0 0.3rem 0.85rem rgba(var(--bs-primary-rgb), 0.2);
}
</style>
