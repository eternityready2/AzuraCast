<template>
    <modal
        id="ai_dj_schedule_modal"
        ref="$modal"
        size="lg"
        :title="modalTitle"
        :busy="isLoadingSchedules"
        @hidden="handleHidden"
    >
        <template #default>
            <div class="d-flex align-items-center justify-content-between mb-3">
                <span class="text-muted small">
                    {{ scheduleCountText }}
                </span>
                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    :disabled="schedEditorOpen"
                    @click="openSchedCreate"
                >
                    <icon-ic-baseline-add />
                    {{ $gettext('Add Schedule') }}
                </button>
            </div>

            <p
                v-if="schedules.length === 0 && !schedEditorOpen"
                class="text-muted text-center py-4 mb-0"
            >
                {{ $gettext('No schedules yet. Add one to control when this DJ is active.') }}
            </p>

            <ul
                v-else
                class="list-group mb-3"
            >
                <li
                    v-for="sched in schedules"
                    :key="sched.id"
                    class="list-group-item d-flex align-items-center justify-content-between gap-3"
                    :class="{'opacity-50': !sched.is_enabled}"
                >
                    <div class="min-width-0">
                        <div class="fw-semibold">
                            {{ sched.name }}
                            <span
                                class="badge ms-1"
                                :class="sched.is_enabled ? 'text-bg-success' : 'text-bg-secondary'"
                            >
                                {{ sched.is_enabled ? $gettext('On') : $gettext('Off') }}
                            </span>
                        </div>
                        <div class="text-muted small">
                            {{ formatTime(sched) }} &middot; {{ formatDays(sched) }}
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-shrink-0">
                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            @click="openSchedEdit(sched)"
                        >
                            {{ $gettext('Edit') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-danger btn-sm"
                            @click="confirmSchedDelete(sched)"
                        >
                            {{ $gettext('Delete') }}
                        </button>
                    </div>
                </li>
            </ul>

            <div
                v-if="schedEditorOpen"
                class="card card-body bg-body-tertiary mb-3"
            >
                <h3 class="h6">
                    {{ editingSched ? $gettext('Edit Schedule') : $gettext('New Schedule') }}
                </h3>

                <div
                    v-if="overlapError"
                    class="alert alert-danger"
                    role="alert"
                >
                    {{ overlapError }}
                </div>

                <form @submit.prevent="saveSched">
                    <div class="mb-3">
                        <label
                            class="form-label"
                            :for="`sched_name_${activeDjId}`"
                        >
                            {{ $gettext('Name') }}
                            <span class="text-danger">*</span>
                        </label>
                        <input
                            :id="`sched_name_${activeDjId}`"
                            v-model="schedForm.name"
                            class="form-control"
                            type="text"
                            :placeholder="$gettext('e.g. Morning Shift')"
                            required
                        >
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label
                                class="form-label"
                                :for="`sched_start_${activeDjId}`"
                            >
                                {{ $gettext('Start Time') }}
                            </label>
                            <input
                                :id="`sched_start_${activeDjId}`"
                                v-model="schedForm.start_time"
                                class="form-control"
                                type="time"
                                step="60"
                            >
                        </div>
                        <div class="col-md-6">
                            <label
                                class="form-label"
                                :for="`sched_end_${activeDjId}`"
                            >
                                {{ $gettext('End Time') }}
                            </label>
                            <input
                                :id="`sched_end_${activeDjId}`"
                                v-model="schedForm.end_time"
                                class="form-control"
                                type="time"
                                step="60"
                            >
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label d-block">
                            {{ $gettext('Active Days') }}
                        </label>
                        <div
                            class="btn-group flex-wrap"
                            role="group"
                            :aria-label="$gettext('Active Days')"
                        >
                            <template
                                v-for="(dayLabel, idx) in DAY_LABELS"
                                :key="idx"
                            >
                                <input
                                    :id="`sched_day_${activeDjId}_${idx}`"
                                    type="checkbox"
                                    class="btn-check"
                                    autocomplete="off"
                                    :checked="schedForm.loop_days.includes(idx + 1)"
                                    @change="toggleDay(idx + 1)"
                                >
                                <label
                                    class="btn btn-outline-primary btn-sm"
                                    :for="`sched_day_${activeDjId}_${idx}`"
                                >
                                    {{ dayLabel }}
                                </label>
                            </template>
                        </div>
                        <div class="form-text">
                            {{ $gettext('Leave all unchecked to run every day.') }}
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input
                            :id="`sched_enabled_${activeDjId}`"
                            v-model="schedForm.is_enabled"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                        >
                        <label
                            class="form-check-label"
                            :for="`sched_enabled_${activeDjId}`"
                        >
                            {{ $gettext('Enabled') }}
                        </label>
                    </div>

                    <div class="d-flex gap-2">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :disabled="isSchedSaving"
                        >
                            {{ isSchedSaving ? $gettext('Saving…') : $gettext('Save Schedule') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-secondary"
                            @click="closeSchedEditor"
                        >
                            {{ $gettext('Cancel') }}
                        </button>
                    </div>
                </form>
            </div>

            <div
                v-if="schedDeleteTarget"
                class="card card-body border-danger mb-3"
            >
                <p class="mb-3">
                    {{ $gettext('Delete schedule "%{name}"? This cannot be undone.', { name: schedDeleteTarget.name }) }}
                </p>
                <div class="d-flex gap-2">
                    <button
                        type="button"
                        class="btn btn-danger"
                        :disabled="isSchedDeleting"
                        @click="doSchedDelete"
                    >
                        {{ isSchedDeleting ? $gettext('Deleting…') : $gettext('Delete') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-secondary"
                        @click="schedDeleteTarget = null"
                    >
                        {{ $gettext('Cancel') }}
                    </button>
                </div>
            </div>
        </template>

        <template #modal-footer>
            <button
                type="button"
                class="btn btn-secondary"
                @click="close"
            >
                {{ $gettext('Close') }}
            </button>
        </template>
    </modal>
</template>

<script setup lang="ts">
import {computed, ref, useTemplateRef} from 'vue';
import {useGettext} from 'vue3-gettext';
import Modal from '~/components/Common/Modal.vue';
import {useAxios} from '~/vendor/axios';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';

interface AiDjSchedule {
    id: number;
    name: string;
    loop_days: number[];
    start_time: string | null;
    end_time: string | null;
    is_enabled: boolean;
}

interface SchedForm {
    name: string;
    start_time: string;
    end_time: string;
    loop_days: number[];
    is_enabled: boolean;
}

const {$gettext} = useGettext();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {getStationApiUrl} = useApiRouter();

const DAY_LABELS = [
    $gettext('Mon'),
    $gettext('Tue'),
    $gettext('Wed'),
    $gettext('Thu'),
    $gettext('Fri'),
    $gettext('Sat'),
    $gettext('Sun'),
];

const $modal = useTemplateRef('$modal');

const activeDjId = ref<number | null>(null);
const activeDjName = ref<string>('');
const isLoadingSchedules = ref(false);
const schedules = ref<AiDjSchedule[]>([]);

const schedEditorOpen = ref(false);
const editingSched = ref<AiDjSchedule | null>(null);
const schedDeleteTarget = ref<AiDjSchedule | null>(null);
const isSchedSaving = ref(false);
const isSchedDeleting = ref(false);
const overlapError = ref<string | null>(null);

const emptyForm = (): SchedForm => ({
    name: '',
    start_time: '',
    end_time: '',
    loop_days: [],
    is_enabled: true,
});
const schedForm = ref<SchedForm>(emptyForm());

const modalTitle = computed(() => {
    return activeDjName.value
        ? `${$gettext('Schedules')} — ${activeDjName.value}`
        : $gettext('Schedules');
});

const scheduleCountText = computed(() => {
    return schedules.value.length === 1
        ? $gettext('1 schedule')
        : $gettext('%{count} schedules', {count: schedules.value.length});
});

const schedulesUrl = () =>
    getStationApiUrl(`/ai-dj/${activeDjId.value}/schedules`).value;

const scheduleUrl = (id: number) =>
    getStationApiUrl(`/ai-dj/${activeDjId.value}/schedules/${id}`).value;

/** Schedules are stored as 24-hour "HH:MM"; the UI always renders 12-hour times. */
const to12Hour = (t: string): string => {
    const match = /^(\d{1,2}):(\d{2})/.exec(t);
    if (!match) {
        return t;
    }

    let hours = parseInt(match[1], 10);
    const meridiem = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    if (hours === 0) {
        hours = 12;
    }

    return `${hours}:${match[2]} ${meridiem}`;
};

const formatTime = (schedule: AiDjSchedule): string => {
    if (schedule.start_time && schedule.end_time) {
        return `${to12Hour(schedule.start_time)} – ${to12Hour(schedule.end_time)}`;
    }
    return $gettext('All day');
};

const formatDays = (schedule: AiDjSchedule): string => {
    if (!schedule.loop_days || schedule.loop_days.length === 0) {
        return $gettext('Every day');
    }
    return schedule.loop_days.map((day) => DAY_LABELS[day - 1] ?? String(day)).join(', ');
};

const loadSchedules = async (): Promise<void> => {
    if (!activeDjId.value) {
        return;
    }

    isLoadingSchedules.value = true;
    try {
        const resp = await axios.get<AiDjSchedule[]>(schedulesUrl());
        schedules.value = Array.isArray(resp.data) ? resp.data : [];
    } catch {
        notifyError($gettext('Failed to load schedules.'));
    } finally {
        isLoadingSchedules.value = false;
    }
};

const openSchedCreate = (): void => {
    editingSched.value = null;
    schedForm.value = emptyForm();
    overlapError.value = null;
    schedDeleteTarget.value = null;
    schedEditorOpen.value = true;
};

const openSchedEdit = (schedule: AiDjSchedule): void => {
    editingSched.value = schedule;
    schedForm.value = {
        name: schedule.name,
        start_time: schedule.start_time ?? '',
        end_time: schedule.end_time ?? '',
        loop_days: [...(schedule.loop_days ?? [])],
        is_enabled: schedule.is_enabled,
    };
    overlapError.value = null;
    schedDeleteTarget.value = null;
    schedEditorOpen.value = true;
};

const closeSchedEditor = (): void => {
    schedEditorOpen.value = false;
    editingSched.value = null;
    overlapError.value = null;
    schedForm.value = emptyForm();
};

const toggleDay = (day: number): void => {
    const idx = schedForm.value.loop_days.indexOf(day);
    if (idx === -1) {
        schedForm.value.loop_days = [...schedForm.value.loop_days, day].sort((a, b) => a - b);
    } else {
        schedForm.value.loop_days = schedForm.value.loop_days.filter((d) => d !== day);
    }
};

const saveSched = async (): Promise<void> => {
    overlapError.value = null;
    isSchedSaving.value = true;

    const payload = {
        ...schedForm.value,
        start_time: schedForm.value.start_time || null,
        end_time: schedForm.value.end_time || null,
    };

    try {
        if (editingSched.value) {
            await axios.put(scheduleUrl(editingSched.value.id), payload);
            notifySuccess($gettext('Schedule updated.'));
        } else {
            await axios.post(schedulesUrl(), payload);
            notifySuccess($gettext('Schedule created.'));
        }
        closeSchedEditor();
        await loadSchedules();
    } catch (e: unknown) {
        const err = e as {response?: {status?: number; data?: {message?: string}}};
        if (err?.response?.status === 400) {
            overlapError.value =
                err.response.data?.message ?? $gettext('Schedule overlaps with an existing schedule.');
        } else {
            notifyError($gettext('Failed to save schedule.'));
        }
    } finally {
        isSchedSaving.value = false;
    }
};

const confirmSchedDelete = (schedule: AiDjSchedule): void => {
    schedDeleteTarget.value = schedule;
    schedEditorOpen.value = false;
};

const doSchedDelete = async (): Promise<void> => {
    if (!schedDeleteTarget.value) {
        return;
    }

    isSchedDeleting.value = true;
    try {
        await axios.delete(scheduleUrl(schedDeleteTarget.value.id));
        notifySuccess($gettext('Schedule deleted.'));
        schedDeleteTarget.value = null;
        await loadSchedules();
    } catch {
        notifyError($gettext('Failed to delete schedule.'));
    } finally {
        isSchedDeleting.value = false;
    }
};

const open = (djId: number, djName: string): void => {
    activeDjId.value = djId;
    activeDjName.value = djName;
    schedEditorOpen.value = false;
    schedDeleteTarget.value = null;
    overlapError.value = null;
    schedules.value = [];
    $modal.value?.show();
    void loadSchedules();
};

const close = (): void => {
    $modal.value?.hide();
};

const handleHidden = (): void => {
    activeDjId.value = null;
    activeDjName.value = '';
    schedules.value = [];
    closeSchedEditor();
};

defineExpose({open, close});
</script>
