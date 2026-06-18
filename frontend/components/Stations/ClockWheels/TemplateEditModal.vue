<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="langTitle"
        :error="error"
        :disable-save-button="r$.$invalid"
        @submit="doSubmit"
        @hidden="clearContents"
    >
        <tabs>
            <ClockWheelsFormEntries
                variant="template"
                :form="form"
                :r$="r$"
                v-model:entries="entries"
                :add-entry="addEntry"
                :remove-entry="removeEntry"
                :duplicate-entry="duplicateEntry"
                :insert-entry-after="insertEntryAfter"
                :on-entries-reordered="onEntriesReordered"
                :on-entries-changed="onEntriesChanged"
            />
        </tabs>

        <template
            v-if="isEditMode"
            #modal-footer
        >
            <button
                type="button"
                class="btn btn-danger me-auto"
                @click="doDeleteFromModal"
            >
                {{ $gettext('Delete') }}
            </button>
            <button
                type="button"
                class="btn btn-secondary"
                @click="close"
            >
                {{ $gettext('Close') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="r$.$invalid"
                @click="doSubmit"
            >
                {{ $gettext('Save Changes') }}
            </button>
        </template>
    </modal-form>
</template>

<script setup lang="ts">
import ModalForm from '~/components/Common/ModalForm.vue';
import Tabs from '~/components/Common/Tabs.vue';
import {BaseEditModalEmits, BaseEditModalProps, useBaseEditModal} from '~/functions/useBaseEditModal';
import {computed, reactive, ref, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAppRegle} from '~/vendor/regle.ts';
import {required} from '@regle/rules';
import mergeExisting from '~/functions/mergeExisting.ts';
import useConfirmAndDelete from '~/functions/useConfirmAndDelete.ts';
import ClockWheelsFormEntries from '~/components/Stations/ClockWheels/Form/Entries.vue';
import {
    applyDragOrderToPositions,
    sortClockWheelEntries,
} from '~/functions/clockWheelPosition.ts';
import {isMediaTypeValue, type MediaTypeValue} from '~/functions/mediaTypes.ts';

interface ClockWheelEntry {
    type: MediaTypeValue;
    algorithm: string;
    position_seconds: number;
    duration_seconds: number | null;
    category_id: number | null;
    playlist_id: number | null;
    pool_mode: 'restrict_pool' | 'playlist_rotation';
    separation_override_enabled: boolean;
    separation_artist_minutes: number | null;
    separation_title_minutes: number | null;
    is_hard_anchor: boolean;
    research_score: number | null;
    sound_code: string | null;
}

const props = defineProps<BaseEditModalProps>();
const emit = defineEmits<BaseEditModalEmits>();

const $modal = useTemplateRef('$modal');
const {notifySuccess} = useNotify();
const {$gettext} = useTranslate();

const blankForm = {
    name: '',
    color: '#e87722',
    separation_enabled: false,
    separation_artist_minutes: 45,
    separation_title_minutes: 90,
    burn_rate_max_plays_24h: null as number | null,
};

const form = ref({...blankForm});
const entries = reactive<ClockWheelEntry[]>([]);

const {r$} = useAppRegle(form, {
    name: {required},
    color: {},
    separation_enabled: {},
    separation_artist_minutes: {},
    separation_title_minutes: {},
    burn_rate_max_plays_24h: {},
});

const defaultEntry = (positionSeconds: number): ClockWheelEntry => ({
    type: 'music',
    algorithm: 'random',
    position_seconds: Math.min(3599, Math.max(0, positionSeconds)),
    duration_seconds: null,
    category_id: null,
    playlist_id: null,
    pool_mode: 'restrict_pool',
    separation_override_enabled: false,
    separation_artist_minutes: null,
    separation_title_minutes: null,
    is_hard_anchor: false,
    research_score: null,
    sound_code: null,
});

const addEntry = () => {
    sortClockWheelEntries(entries);
    const lastPosition = entries.length > 0
        ? entries[entries.length - 1].position_seconds + 300
        : 0;
    entries.push(defaultEntry(lastPosition));
    sortClockWheelEntries(entries);
};

const removeEntry = (index: number) => {
    entries.splice(index, 1);
};

const duplicateEntry = (index: number) => {
    const source = entries[index];
    if (!source) {
        return;
    }
    entries.push({...source, position_seconds: Math.min(3599, source.position_seconds + 60)});
    sortClockWheelEntries(entries);
};

const insertEntryAfter = (index: number) => {
    const source = entries[index];
    if (!source) {
        return;
    }
    entries.splice(index + 1, 0, defaultEntry(Math.min(3599, source.position_seconds + 300)));
    sortClockWheelEntries(entries);
};

const onEntriesReordered = () => {
    applyDragOrderToPositions(entries);
    sortClockWheelEntries(entries);
};

const onEntriesChanged = () => {
    sortClockWheelEntries(entries);
};

const resetForm = () => {
    form.value = {...blankForm};
    entries.splice(0, entries.length);
};

const normalizeSlotType = (type: string | null | undefined): MediaTypeValue => {
    return isMediaTypeValue(type) ? type : 'music';
};

const populateForm = (data: Record<string, unknown>) => {
    form.value = mergeExisting(form.value, {
        ...data,
        separation_enabled: Boolean(data.separation_enabled),
        separation_artist_minutes: Number(data.separation_artist_minutes ?? 45),
        separation_title_minutes: Number(data.separation_title_minutes ?? 90),
        burn_rate_max_plays_24h: data.burn_rate_max_plays_24h != null
            ? Number(data.burn_rate_max_plays_24h)
            : null,
    });
    if (Array.isArray(data.slots)) {
        const converted = (data.slots as {
            type?: string | null;
            algorithm?: string;
            position_seconds?: number;
            duration_seconds?: number | null;
            category_id?: number | null;
            playlist_id?: number | null;
            pool_mode?: string;
            separation_override_enabled?: boolean;
            separation_artist_minutes?: number | null;
            separation_title_minutes?: number | null;
            is_hard_anchor?: boolean;
            research_score?: number | null;
            sound_code?: string | null;
        }[]).map((s) => ({
            type: normalizeSlotType(s.type),
            algorithm: s.algorithm ?? 'random',
            position_seconds: s.position_seconds ?? 0,
            duration_seconds: s.duration_seconds ?? null,
            category_id: s.category_id ?? null,
            playlist_id: s.playlist_id ?? null,
            pool_mode: s.pool_mode === 'playlist_rotation' ? 'playlist_rotation' : 'restrict_pool',
            separation_override_enabled: Boolean(s.separation_override_enabled),
            separation_artist_minutes: s.separation_artist_minutes ?? null,
            separation_title_minutes: s.separation_title_minutes ?? null,
            is_hard_anchor: Boolean(s.is_hard_anchor),
            research_score: s.research_score ?? null,
            sound_code: s.sound_code ?? null,
        }));
        entries.splice(0, entries.length, ...converted);
        sortClockWheelEntries(entries);
    }
};

const validateForm = async () => {
    const {valid} = await r$.$validate();
    const slots = entries.map((e) => ({
        type: e.type,
        category_id: e.category_id,
        playlist_id: e.playlist_id,
        pool_mode: e.playlist_id ? e.pool_mode : 'restrict_pool',
        algorithm: e.algorithm,
        position_seconds: e.position_seconds,
        duration_seconds: e.duration_seconds,
        separation_override_enabled: e.separation_override_enabled,
        separation_artist_minutes: e.separation_override_enabled
            ? e.separation_artist_minutes
            : null,
        separation_title_minutes: e.separation_override_enabled
            ? e.separation_title_minutes
            : null,
        is_hard_anchor: e.is_hard_anchor,
        research_score: e.research_score,
        sound_code: e.sound_code,
    }));
    return {valid, data: {...form.value, slots}};
};

const langTitle = computed(() =>
    isEditMode.value ? $gettext('Edit Clock Template') : $gettext('Add Clock Template')
);

const {
    loading,
    error,
    isEditMode,
    editUrl,
    clearContents,
    create,
    edit,
    close,
    doSubmit,
} = useBaseEditModal(
    computed(() => props.createUrl),
    emit,
    $modal,
    resetForm,
    populateForm,
    validateForm,
    {
        onSubmitSuccess: () => {
            notifySuccess($gettext('Clock template saved. Linked wheels with inheritance enabled were updated.'));
            emit('relist');
            close();
        },
    }
);

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete this clock template? Dayparts using it must be removed first.'),
    () => {
        emit('relist');
    }
);

const doDeleteFromModal = () => {
    if (editUrl.value) {
        $modal.value?.hide();
        void doDelete(editUrl.value);
    }
};

defineExpose({create, edit});
</script>
