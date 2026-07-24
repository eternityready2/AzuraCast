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
        <tabs
            :key="tabsKey"
            v-model="activeTab"
        >
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
            <FormSchedule v-model:schedule-items="scheduleItems" />
        </tabs>
    </modal-form>
</template>

<script setup lang="ts">
import ModalForm from '~/components/Common/ModalForm.vue';
import Tabs from '~/components/Common/Tabs.vue';
import {BaseEditModalEmits, BaseEditModalProps, useBaseEditModal} from '~/functions/useBaseEditModal';
import {computed, onMounted, reactive, ref, useTemplateRef} from 'vue';
import {useAxios} from '~/vendor/axios';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAppRegle} from '~/vendor/regle.ts';
import {required} from '@regle/rules';
import mergeExisting from '~/functions/mergeExisting.ts';
import ClockWheelsFormEntries from '~/components/Stations/ClockWheels/Form/Entries.vue';
import FormSchedule from '~/components/Stations/ClockWheels/Form/Schedule.vue';
import type {ClockWheelScheduleRow} from '~/components/Stations/ClockWheels/Form/ScheduleRow.vue';
import normalizeStationScheduleDays from '~/functions/normalizeStationScheduleDays';
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
const {notifySuccess} = useNotify();
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
const scheduleItems = ref<ClockWheelScheduleRow[]>([]);
const activeTab = ref('basic-info');
const tabsKey = ref(0);

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
    scheduleItems.value.splice(0, scheduleItems.value.length);
    activeTab.value = 'basic-info';
    tabsKey.value += 1;
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
    if (Array.isArray(data.schedule_items)) {
        scheduleItems.value.splice(
            0,
            scheduleItems.value.length,
            ...(data.schedule_items as Record<string, unknown>[]).map((item) => {
                const endType = (item.recurrence_end_type as string | undefined) ?? 'never';
                return {
                    ...item,
                    loop_once: false,
                    clock_wheel_mode: item.clock_wheel_mode === 'strict' ? 'strict' : 'flexible',
                    recurrence_type: (item.recurrence_type as string | null) ?? 'weekly',
                    recurrence_interval: Number(item.recurrence_interval ?? 1),
                    recurrence_end_type: endType === 'on_date' ? 'never' : endType,
                    recurrence_end_after: endType === 'after' ? (item.recurrence_end_after ?? null) : null,
                    recurrence_end_date: null,
                    days: normalizeStationScheduleDays(item.days),
                } as ClockWheelScheduleRow;
            })
        );
    } else {
        scheduleItems.value.splice(0, scheduleItems.value.length);
    }
};

const validateForm = async () => {
    const {valid} = await r$.$validate();
    const inheritSlots = Boolean(form.value.inherits_template_slots)
        && form.value.template_id != null
        && form.value.template_id > 0
        && (form.value.daypart_id == null || form.value.daypart_id <= 0);

    const schedule_items = scheduleItems.value.map((item) => {
        const normalizedDays = normalizeStationScheduleDays(item.days);
        const out: Record<string, unknown> = {
            ...item,
            loop_once: false,
            clock_wheel_mode: item.clock_wheel_mode === 'strict' ? 'strict' : 'flexible',
            end_date: item.recurrence_end_type === 'after' ? '' : (item.end_date || item.start_date),
            days: item.recurrence_type === 'monthly' && item.recurrence_monthly_pattern === 'date'
                ? []
                : normalizedDays,
        };
        if (
            out.recurrence_type === 'monthly'
            && out.recurrence_monthly_pattern === 'day_of_week'
            && normalizedDays.length > 0
        ) {
            out.recurrence_monthly_day_of_week = normalizedDays[0];
        }
        return out;
    });

    const payload: Record<string, unknown> = {
        ...form.value,
        template_id: form.value.template_id != null && form.value.template_id > 0
            ? Number(form.value.template_id)
            : null,
        inherits_template_slots: inheritSlots,
        schedule_items,
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

defineExpose({create, edit});
</script>
