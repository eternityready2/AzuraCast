<template>
    <div class="clock-wheel-inline-editor">
        <div class="clock-wheel-editor-hero">
            <div class="clock-wheel-editor-title">
                <button
                    type="button"
                    class="btn btn-link p-0 text-decoration-none clock-wheel-back"
                    @click="emit('cancel')"
                >
                    <span aria-hidden="true">←</span>
                    {{ $gettext('Back to Wheels') }}
                </button>
                <div class="clock-wheel-editor-kicker">
                    {{ $gettext('Clock Wheel Builder') }}
                </div>
                <h2 class="mb-1">
                    {{ isEditMode ? $gettext('Edit Clock Wheel') : $gettext('Add Clock Wheel') }}
                </h2>
                <p class="mb-0 text-muted">
                    {{ $gettext('Build the hour visually, keep every slot visible, and manage the schedule without leaving this page.') }}
                </p>
            </div>

            <div class="clock-wheel-editor-actions">
                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    :disabled="loading"
                    @click="emit('cancel')"
                >
                    {{ $gettext('Cancel') }}
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="loading || r$.$invalid"
                    @click="doSubmit"
                >
                    <span
                        v-if="loading"
                        class="spinner-border spinner-border-sm me-2"
                        role="status"
                        aria-hidden="true"
                    />
                    {{ loading ? $gettext('Saving…') : $gettext('Save Clock Wheel') }}
                </button>
            </div>
        </div>

        <div
            v-if="error"
            class="alert alert-danger mb-3"
        >
            {{ error }}
        </div>

        <div class="clock-wheel-editor-surface">
            <tabs
                :key="tabsKey"
                v-model="activeTab"
                nav-tabs-class="clock-wheel-editor-subtabs"
                content-class="clock-wheel-editor-tab-content"
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
        </div>

        <div class="clock-wheel-editor-footer">
            <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="loading"
                @click="emit('cancel')"
            >
                {{ $gettext('Cancel') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="loading || r$.$invalid"
                @click="doSubmit"
            >
                {{ loading ? $gettext('Saving…') : $gettext('Save Clock Wheel') }}
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, reactive, ref} from 'vue';
import {useAxios} from '~/vendor/axios';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAppRegle} from '~/vendor/regle.ts';
import {required} from '@regle/rules';
import mergeExisting from '~/functions/mergeExisting.ts';
import Tabs from '~/components/Common/Tabs.vue';
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

const props = defineProps<{
    createUrl: string;
    templatesUrl: string;
    recordUrl?: string | null;
}>();

const emit = defineEmits<{
    (e: 'saved'): void;
    (e: 'cancel'): void;
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess} = useNotify();

const loading = ref(false);
const error = ref<string | null>(null);
const templateOptions = ref<{value: number; text: string}[]>([]);
const activeTab = ref('basic-info');
const tabsKey = ref(0);

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

const isEditMode = computed(() => Boolean(props.recordUrl));

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
        const converted = (data.slots as Record<string, unknown>[]).map((slot) =>
            mapApiSlotToEditorRow(slot)
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
    }
};

const loadEditor = async () => {
    loading.value = true;
    error.value = null;
    resetForm();

    try {
        const {data: templates} = await axios.get(props.templatesUrl);
        templateOptions.value = (templates as Array<{id: number; name: string}>).map((template) => ({
            value: template.id,
            text: template.name,
        }));

        if (props.recordUrl) {
            const {data} = await axios.get(props.recordUrl);
            populateForm(data as Record<string, unknown>);
        }
    } catch (err: any) {
        error.value = err?.response?.data?.message ?? $gettext('Could not load this clock wheel.');
    } finally {
        loading.value = false;
    }
};

onMounted(loadEditor);

const defaultEntry = (positionSeconds: number): ClockWheelEntry =>
    defaultClockWheelSlotEditorRow(positionSeconds);

const addEntry = () => {
    sortClockWheelEntries(entries);
    const lastPosition = entries.length > 0
        ? entries[entries.length - 1].position_seconds + 300
        : 0;
    entries.push(defaultEntry(Math.min(3599, lastPosition)));
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

    entries.push({...source, position_seconds: position});
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

const buildPayload = async () => {
    const {valid} = await r$.$validate();
    if (!valid) {
        return null;
    }

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
        payload.slots = entries.map((entry) => mapEditorRowToApiSlot(entry));
    }

    return payload;
};

const doSubmit = async () => {
    const payload = await buildPayload();
    if (!payload) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        if (props.recordUrl) {
            await axios.put(props.recordUrl, payload);
        } else {
            await axios.post(props.createUrl, payload);
        }
        notifySuccess($gettext('Clock Wheel saved.'));
        emit('saved');
    } catch (err: any) {
        error.value = err?.response?.data?.message ?? $gettext('Could not save this clock wheel.');
    } finally {
        loading.value = false;
    }
};
</script>

<style scoped>
.clock-wheel-inline-editor {
    min-width: 0;
}

.clock-wheel-editor-hero {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1.5rem;
    padding: 1.25rem 1.35rem;
    margin-bottom: 1rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .9rem;
    background: linear-gradient(135deg, var(--bs-body-bg), var(--bs-tertiary-bg));
}

.clock-wheel-editor-title {
    min-width: 0;
}

.clock-wheel-back {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    margin-bottom: .7rem;
    font-weight: 600;
}

.clock-wheel-editor-kicker {
    margin-bottom: .15rem;
    color: var(--bs-primary);
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.clock-wheel-editor-actions,
.clock-wheel-editor-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: .65rem;
}

.clock-wheel-editor-surface {
    overflow: hidden;
    border: 1px solid var(--bs-border-color);
    border-radius: .9rem;
    background: var(--bs-body-bg);
    box-shadow: 0 .2rem .8rem rgba(0, 0, 0, .04);
}

.clock-wheel-editor-surface :deep(.clock-wheel-editor-subtabs) {
    padding: .65rem .9rem 0;
    background: var(--bs-tertiary-bg);
}

.clock-wheel-editor-surface :deep(.clock-wheel-editor-tab-content) {
    padding: 1rem;
}

.clock-wheel-editor-footer {
    position: sticky;
    bottom: 0;
    z-index: 5;
    padding: .85rem 1rem;
    margin-top: 1rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: color-mix(in srgb, var(--bs-body-bg) 94%, transparent);
    backdrop-filter: blur(10px);
}

@media (max-width: 767.98px) {
    .clock-wheel-editor-hero {
        align-items: stretch;
        flex-direction: column;
        padding: 1rem;
    }

    .clock-wheel-editor-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        width: 100%;
    }

    .clock-wheel-editor-actions .btn,
    .clock-wheel-editor-footer .btn {
        min-height: 2.75rem;
    }

    .clock-wheel-editor-surface :deep(.clock-wheel-editor-tab-content) {
        padding: .7rem;
    }

    .clock-wheel-editor-footer {
        display: grid;
        grid-template-columns: 1fr 1fr;
        padding: .7rem;
    }
}
</style>
