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
import {
    defaultClockWheelSlotEditorRow,
    mapApiSlotToEditorRow,
    mapEditorRowToApiSlot,
    type ClockWheelSlotEditorRow,
} from '~/functions/clockWheelSlotEditor.ts';

interface ClockWheelEntry extends ClockWheelSlotEditorRow {}

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

const defaultEntry = (positionSeconds: number): ClockWheelEntry =>
    defaultClockWheelSlotEditorRow(positionSeconds);

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
        const converted = (data.slots as Record<string, unknown>[]).map((s) =>
            mapApiSlotToEditorRow(s)
        );
        entries.splice(0, entries.length, ...converted);
        sortClockWheelEntries(entries);
    }
};

const validateForm = async () => {
    const {valid} = await r$.$validate();
    const slots = entries.map((e) => mapEditorRowToApiSlot(e));
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
