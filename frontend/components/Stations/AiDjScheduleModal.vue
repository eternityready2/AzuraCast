<template>
    <div
        v-if="isOpen"
        class="schedule-overlay"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="'sched-title-' + activeDjId"
        @keydown.esc="close"
    >
        <div
            class="schedule-panel"
            tabindex="-1"
        >
            <!-- Header -->
            <div class="schedule-panel-header">
                <div>
                    <h3
                        :id="'sched-title-' + activeDjId"
                        class="schedule-panel-title"
                    >
                        {{ $gettext('Schedules') }}
                        <span
                            v-if="activeDjName"
                            class="schedule-dj-name"
                        >— {{ activeDjName }}</span>
                    </h3>
                    <p class="schedule-panel-sub mb-0">
                        {{ $gettext('Manage when this DJ is active.') }}
                    </p>
                </div>
                <button
                    type="button"
                    class="schedule-close-btn"
                    :aria-label="$gettext('Close')"
                    @click="close"
                >
                    ✕
                </button>
            </div>

            <loading
                :loading="isLoadingSchedules"
                lazy
            >
                <!-- Schedule list -->
                <div class="schedule-list-header">
                    <span class="schedule-count">
                        {{ schedules.length }}
                        {{ schedules.length === 1 ? $gettext('schedule') : $gettext('schedules') }}
                    </span>
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="schedEditorOpen"
                        @click="openSchedCreate"
                    >
                        + {{ $gettext('Add Schedule') }}
                    </button>
                </div>

                <div
                    v-if="schedules.length === 0 && !schedEditorOpen"
                    class="schedule-empty"
                >
                    {{ $gettext('No schedules yet. Add one to control when this DJ is active.') }}
                </div>

                <div
                    v-for="sched in schedules"
                    :key="sched.id"
                    class="schedule-row"
                    :class="{'schedule-row--disabled': !sched.is_enabled}"
                >
                    <div class="schedule-row-info">
                        <div class="schedule-row-name">
                            {{ sched.name }}
                            <span
                                class="sched-status-badge"
                                :class="sched.is_enabled ? 'sched-on' : 'sched-off'"
                            >
                                {{ sched.is_enabled ? $gettext('On') : $gettext('Off') }}
                            </span>
                        </div>
                        <div class="schedule-row-meta">
                            <span class="sched-time">{{ formatTime(sched) }}</span>
                            <span class="sched-sep">·</span>
                            <span class="sched-days">{{ formatDays(sched) }}</span>
                        </div>
                    </div>
                    <div class="schedule-row-actions">
                        <button
                            type="button"
                            class="btn btn-secondary btn-xs"
                            @click="openSchedEdit(sched)"
                        >
                            {{ $gettext('Edit') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-danger btn-xs"
                            @click="confirmSchedDelete(sched)"
                        >
                            {{ $gettext('Delete') }}
                        </button>
                    </div>
                </div>

                <!-- Schedule Editor -->
                <div
                    v-if="schedEditorOpen"
                    class="sched-editor-card"
                >
                    <div class="sched-editor-title">
                        {{ editingSched ? $gettext('Edit Schedule') : $gettext('New Schedule') }}
                    </div>

                    <div
                        v-if="overlapError"
                        class="sched-error"
                        role="alert"
                    >
                        {{ overlapError }}
                    </div>

                    <form @submit.prevent="saveSched">
                        <!-- Name -->
                        <div class="sched-field">
                            <label
                                class="sched-label"
                                :for="'sched-name-' + activeDjId"
                            >
                                {{ $gettext('Name') }}
                                <span class="text-danger">*</span>
                            </label>
                            <input
                                :id="'sched-name-' + activeDjId"
                                v-model="schedForm.name"
                                class="form-control form-control-dark"
                                type="text"
                                :placeholder="$gettext('e.g. Morning Shift')"
                                required
                            >
                        </div>

                        <!-- Time row -->
                        <div class="sched-time-row">
                            <div class="sched-field sched-field--half">
                                <label
                                    class="sched-label"
                                    :for="'sched-start-' + activeDjId"
                                >
                                    {{ $gettext('Start Time') }}
                                </label>
                                <input
                                    :id="'sched-start-' + activeDjId"
                                    v-model="schedForm.start_time"
                                    class="form-control form-control-dark"
                                    type="time"
                                    step="60"
                                >
                            </div>
                            <div class="sched-field sched-field--half">
                                <label
                                    class="sched-label"
                                    :for="'sched-end-' + activeDjId"
                                >
                                    {{ $gettext('End Time') }}
                                </label>
                                <input
                                    :id="'sched-end-' + activeDjId"
                                    v-model="schedForm.end_time"
                                    class="form-control form-control-dark"
                                    type="time"
                                    step="60"
                                >
                            </div>
                        </div>

                        <!-- Loop Days -->
                        <div class="sched-field">
                            <div class="sched-label">
                                {{ $gettext('Active Days') }}
                            </div>
                            <div class="day-checkboxes">
                                <label
                                    v-for="(dayLabel, idx) in DAY_LABELS"
                                    :key="idx"
                                    class="day-check"
                                    :class="{'day-check--active': schedForm.loop_days.includes(idx + 1)}"
                                >
                                    <input
                                        type="checkbox"
                                        class="visually-hidden"
                                        :value="idx + 1"
                                        :checked="schedForm.loop_days.includes(idx + 1)"
                                        @change="toggleDay(idx + 1)"
                                    >
                                    {{ dayLabel }}
                                </label>
                            </div>
                            <div class="sched-helper">
                                {{ $gettext('Leave all unchecked to run every day.') }}
                            </div>
                        </div>

                        <!-- Enabled toggle -->
                        <div class="toggle-row mb-3">
                            <div>
                                <div class="toggle-label">
                                    {{ $gettext('Enabled') }}
                                </div>
                            </div>
                            <label class="toggle">
                                <input
                                    v-model="schedForm.is_enabled"
                                    type="checkbox"
                                >
                                <span class="slider" />
                            </label>
                        </div>

                        <div class="btn-row">
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

                <!-- Delete Confirm -->
                <div
                    v-if="schedDeleteTarget"
                    class="sched-delete-card"
                >
                    <div class="sched-editor-title">
                        {{ $gettext('Confirm Delete') }}
                    </div>
                    <p>
                        {{ $gettext('Delete schedule "%{name}"? This cannot be undone.', { name: schedDeleteTarget.name }) }}
                    </p>
                    <div class="btn-row">
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
            </loading>
        </div>
    </div>
</template>

<script setup lang="ts">
import {ref} from 'vue';
import {useGettext} from 'vue3-gettext';
import Loading from '~/components/Common/Loading.vue';
import {useAxios} from '~/vendor/axios';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';

// --- Types ---

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

// --- Setup ---

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

// --- State ---

const isOpen = ref(false);
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

// --- URL helpers ---

const schedulesUrl = () =>
    getStationApiUrl(`/ai-dj/${activeDjId.value}/schedules`).value;

const scheduleUrl = (id: number) =>
    getStationApiUrl(`/ai-dj/${activeDjId.value}/schedules/${id}`).value;

// --- Formatters ---

const formatTime = (s: AiDjSchedule): string => {
    if (s.start_time && s.end_time) return `${s.start_time}–${s.end_time}`;
    return $gettext('All day');
};

const formatDays = (s: AiDjSchedule): string => {
    if (!s.loop_days || s.loop_days.length === 0) return $gettext('Every day');
    return s.loop_days.map((d) => DAY_LABELS[d - 1] ?? String(d)).join(', ');
};

// --- API ---

const loadSchedules = async (): Promise<void> => {
    if (!activeDjId.value) return;
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

// --- Editor ---

const openSchedCreate = (): void => {
    editingSched.value = null;
    schedForm.value = emptyForm();
    overlapError.value = null;
    schedDeleteTarget.value = null;
    schedEditorOpen.value = true;
};

const openSchedEdit = (s: AiDjSchedule): void => {
    editingSched.value = s;
    schedForm.value = {
        name: s.name,
        start_time: s.start_time ?? '',
        end_time: s.end_time ?? '',
        loop_days: [...(s.loop_days ?? [])],
        is_enabled: s.is_enabled,
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

// --- Delete ---

const confirmSchedDelete = (s: AiDjSchedule): void => {
    schedDeleteTarget.value = s;
    schedEditorOpen.value = false;
};

const doSchedDelete = async (): Promise<void> => {
    if (!schedDeleteTarget.value) return;
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

// --- Public API ---

const open = (djId: number, djName: string): void => {
    activeDjId.value = djId;
    activeDjName.value = djName;
    schedEditorOpen.value = false;
    schedDeleteTarget.value = null;
    overlapError.value = null;
    schedules.value = [];
    isOpen.value = true;
    void loadSchedules();
};

const close = (): void => {
    isOpen.value = false;
    activeDjId.value = null;
    activeDjName.value = '';
    schedules.value = [];
    closeSchedEditor();
};

defineExpose({open, close});
</script>

<style scoped lang="scss">
.schedule-overlay {
    position: fixed;
    inset: 0;
    z-index: 1050;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 2rem 1rem;
    overflow-y: auto;
}

.schedule-panel {
    background: var(--bs-body-bg, #1a1d23);
    border: 1px solid var(--bs-border-color, #2d3140);
    border-radius: 10px;
    width: 100%;
    max-width: 560px;
    padding: 1.5rem;
    outline: none;
}

.schedule-panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.schedule-panel-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0 0 0.2rem;
    color: var(--bs-body-color, #e0e0e0);
}

.schedule-dj-name {
    font-weight: 400;
    color: var(--bs-secondary-color, #888);
}

.schedule-panel-sub {
    font-size: 0.82rem;
    color: var(--bs-secondary-color, #888);
}

.schedule-close-btn {
    background: none;
    border: none;
    color: var(--bs-secondary-color, #888);
    font-size: 1.1rem;
    cursor: pointer;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    line-height: 1;
    flex-shrink: 0;

    &:hover {
        background: var(--bs-tertiary-bg, #22252e);
        color: var(--bs-body-color, #e0e0e0);
    }
}

.schedule-list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
}

.schedule-count {
    font-size: 0.82rem;
    color: var(--bs-secondary-color, #888);
}

.schedule-empty {
    color: var(--bs-secondary-color, #888);
    font-size: 0.88rem;
    text-align: center;
    padding: 1.5rem 0;
}

.schedule-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.65rem 0;
    border-bottom: 1px solid var(--bs-border-color, #2d3140);

    &:last-of-type {
        border-bottom: none;
    }

    &--disabled {
        opacity: 0.55;
    }
}

.schedule-row-info {
    min-width: 0;
}

.schedule-row-name {
    font-weight: 600;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.schedule-row-meta {
    font-size: 0.8rem;
    color: var(--bs-secondary-color, #aaa);
    margin-top: 0.15rem;
}

.sched-sep {
    margin: 0 0.3rem;
}

.sched-status-badge {
    display: inline-block;
    border-radius: 10px;
    padding: 0.1rem 0.45rem;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;

    &.sched-on {
        background: rgba(40, 167, 69, 0.18);
        color: #5cb85c;
    }

    &.sched-off {
        background: rgba(108, 117, 125, 0.18);
        color: var(--bs-secondary-color, #888);
    }
}

.schedule-row-actions {
    display: flex;
    gap: 0.4rem;
    flex-shrink: 0;
}

.btn-xs {
    padding: 0.2rem 0.55rem;
    font-size: 0.78rem;
}

.sched-editor-card {
    margin-top: 1rem;
    background: var(--bs-tertiary-bg, #22252e);
    border: 1px solid var(--bs-primary, #5a7fd4);
    border-radius: 8px;
    padding: 1rem;
}

.sched-delete-card {
    margin-top: 1rem;
    background: var(--bs-tertiary-bg, #22252e);
    border: 1px solid var(--bs-danger, #dc3545);
    border-radius: 8px;
    padding: 1rem;
}

.sched-editor-title {
    font-size: 0.95rem;
    font-weight: 600;
    margin-bottom: 0.9rem;
    color: var(--bs-body-color, #e0e0e0);
}

.sched-error {
    background: rgba(220, 53, 69, 0.15);
    border: 1px solid var(--bs-danger, #dc3545);
    border-radius: 6px;
    color: #e07070;
    font-size: 0.85rem;
    padding: 0.6rem 0.75rem;
    margin-bottom: 0.9rem;
}

.sched-field {
    margin-bottom: 0.9rem;
}

.sched-field--half {
    flex: 1;
}

.sched-label {
    display: block;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--bs-secondary-color, #aaa);
    margin-bottom: 0.3rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.sched-time-row {
    display: flex;
    gap: 0.75rem;
    margin-bottom: 0.9rem;
}

.sched-helper {
    font-size: 0.78rem;
    color: var(--bs-secondary-color, #888);
    margin-top: 0.35rem;
}

.day-checkboxes {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin-top: 0.3rem;
}

.day-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2.4rem;
    padding: 0.3rem 0.5rem;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    background: var(--bs-body-bg, #1a1d23);
    border: 1px solid var(--bs-border-color, #2d3140);
    color: var(--bs-secondary-color, #aaa);
    user-select: none;
    transition: background 0.15s, border-color 0.15s, color 0.15s;

    &--active {
        background: var(--bs-primary, #5a7fd4);
        border-color: var(--bs-primary, #5a7fd4);
        color: #fff;
    }

    &:hover:not(.day-check--active) {
        border-color: var(--bs-primary, #5a7fd4);
        color: var(--bs-body-color, #e0e0e0);
    }
}

.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.toggle-label {
    font-weight: 600;
    font-size: 0.9rem;
}

.toggle {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
    flex-shrink: 0;

    input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        inset: 0;
        background: var(--bs-secondary, #6c757d);
        border-radius: 24px;
        cursor: pointer;
        transition: background 0.2s;

        &::before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            left: 3px;
            top: 3px;
            border-radius: 50%;
            background: #fff;
            transition: transform 0.2s;
        }
    }

    input:checked + .slider {
        background: var(--bs-primary, #5a7fd4);
    }

    input:checked + .slider::before {
        transform: translateX(20px);
    }
}

.btn-row {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
}
</style>
