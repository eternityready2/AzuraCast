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
                :form="form"
                :r$="r$"
                :template-options="templateOptions"
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
                class="btn btn-outline-secondary me-auto"
                @click="doExportJson"
            >
                {{ $gettext('Export JSON') }}
            </button>
            <button
                type="button"
                class="btn btn-danger"
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
import {computed, onMounted, reactive, ref, useTemplateRef, watch} from 'vue';
import {useAxios} from '~/vendor/axios';
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

const props = defineProps<BaseEditModalProps & {
    templatesUrl: string;
}>();
const emit = defineEmits<BaseEditModalEmits>();

const $modal = useTemplateRef('$modal');
const {notifySuccess, notifyError} = useNotify();
const {$gettext} = useTranslate();
const {axios} = useAxios();

const templateOptions = ref<{value: number; text: string}[]>([]);

onMounted(async () => {
    const {data} = await axios.get(props.templatesUrl);
    templateOptions.value = (data as Array<{id: number; name: string}>).map((t) => ({
        value: t.id,
        text: t.name,
    }));
});

const blankForm = {
    name: '',
    color: '#e87722',
    is_active: true,
    fill_strategy: 'conservative',
    separation_enabled: false,
    separation_artist_minutes: 45,
    separation_title_minutes: 90,
    burn_rate_max_plays_24h: null as number | null,
    template_id: null as number | null,
    inherits_template_slots: false,
    daypart_id: null as number | null,
};

const form = ref({...blankForm});
const entries = reactive<ClockWheelEntry[]>([]);

const {r$} = useAppRegle(form, {
    name: {required},
    color: {},
    is_active: {},
    fill_strategy: {},
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

    sortClockWheelEntries(entries);
    const next = entries[index + 1];
    let position = source.position_seconds + 60;
    if (next && position >= next.position_seconds) {
        position = Math.floor((source.position_seconds + next.position_seconds) / 2);
    }
    if (!next) {
        position = Math.min(3599, source.position_seconds + 300);
    }

    entries.push({
        ...source,
        position_seconds: position,
    });
    sortClockWheelEntries(entries);
};

const insertEntryAfter = (index: number) => {
    const source = entries[index];
    if (!source) {
        return;
    }

    sortClockWheelEntries(entries);
    const next = entries[index + 1];
    let position = source.position_seconds + 300;
    if (next) {
        position = Math.min(position, next.position_seconds - 1);
        if (position <= source.position_seconds) {
            position = Math.floor((source.position_seconds + next.position_seconds) / 2);
        }
    } else {
        position = Math.min(3599, position);
    }

    entries.splice(index + 1, 0, defaultEntry(position));
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
        template_id: data.template_id != null ? Number(data.template_id) : null,
        inherits_template_slots: Boolean(data.inherits_template_slots),
        daypart_id: data.daypart_id != null ? Number(data.daypart_id) : null,
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
    const inheritSlots = Boolean(form.value.inherits_template_slots)
        && form.value.template_id != null
        && form.value.template_id > 0
        && (form.value.daypart_id == null || form.value.daypart_id <= 0);

    const payload: Record<string, unknown> = {
        ...form.value,
        template_id: form.value.template_id != null && form.value.template_id > 0
            ? Number(form.value.template_id)
            : null,
        inherits_template_slots: inheritSlots,
    };

    if (!inheritSlots) {
        payload.slots = entries.map((e) => mapEditorRowToApiSlot(e));
    }

    return {valid, data: payload};
};

const langTitle = computed(() =>
    isEditMode.value ? $gettext('Edit Clock Wheel') : $gettext('Add Clock Wheel')
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
            notifySuccess($gettext('Clock Wheel saved.'));
            emit('relist');
            close();
        },
    }
);

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete Clock Wheel?'),
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

const doExportJson = async () => {
    if (!editUrl.value) {
        return;
    }

    try {
        const exportUrl = editUrl.value.replace(/\/?$/, '') + '/export';
        const {data} = await axios.get<Record<string, unknown>>(exportUrl);
        const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'});
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `${form.value.name || 'clock-wheel'}.json`;
        anchor.click();
        URL.revokeObjectURL(url);
    } catch {
        notifyError($gettext('Could not export clock wheel.'));
    }
};

defineExpose({create, edit});
</script>
