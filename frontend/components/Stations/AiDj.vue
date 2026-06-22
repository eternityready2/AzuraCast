<template>
    <div class="ai-dj-page">
        <section
            class="ai-dj-shell"
            role="region"
            aria-labelledby="hdr_ai_dj"
        >
            <header class="ai-dj-topbar">
                <div class="ai-dj-branding">
                    <span class="logo-dot" />
                    <div>
                        <h2
                            id="hdr_ai_dj"
                            class="ai-dj-title"
                        >
                            {{ $gettext('AI DJ') }}
                        </h2>
                        <p class="ai-dj-subtitle mb-0">
                            {{ $gettext('Manage your AI DJ personalities') }}
                        </p>
                    </div>
                </div>
            </header>

            <loading
                :loading="isLoading"
                lazy
            >
                <div class="ai-dj-container">
                    <!-- DJ List -->
                    <section class="dashboard-card">
                        <div class="dj-list-header">
                            <div class="dashboard-card-title mb-0">
                                {{ $gettext('DJ Personalities') }}
                            </div>
                            <button
                                type="button"
                                class="btn btn-primary btn-sm"
                                @click="openCreate"
                            >
                                + {{ $gettext('Create DJ') }}
                            </button>
                        </div>

                        <div
                            v-if="djs.length === 0"
                            class="dj-empty"
                        >
                            {{ $gettext('No DJ personalities configured yet. Create one to get started.') }}
                        </div>

                        <div
                            v-else
                            class="dj-table-wrap"
                        >
                            <table class="dj-table">
                                <thead>
                                    <tr>
                                        <th>{{ $gettext('Name') }}</th>
                                        <th>{{ $gettext('Voice Model') }}</th>
                                        <th>{{ $gettext('Status') }}</th>
                                        <th>{{ $gettext('Schedule') }}</th>
                                        <th class="dj-actions-col">
                                            {{ $gettext('Actions') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="dj in djs"
                                        :key="dj.id"
                                    >
                                        <td class="dj-name">
                                            {{ dj.name }}
                                        </td>
                                        <td class="dj-voice">
                                            <span
                                                v-if="dj.voice_model_path"
                                                class="voice-badge"
                                            >
                                                {{ voiceLabel(dj.voice_model_path) }}
                                            </span>
                                            <span
                                                v-else
                                                class="text-muted"
                                            >—</span>
                                        </td>
                                        <td>
                                            <span
                                                class="status-badge"
                                                :class="dj.is_enabled ? 'status-on' : 'status-off'"
                                            >
                                                {{ dj.is_enabled ? $gettext('Enabled') : $gettext('Disabled') }}
                                            </span>
                                        </td>
                                        <td class="dj-schedule">
                                            <span
                                                v-if="dj.schedules && dj.schedules.length > 0"
                                                class="schedule-summary"
                                            >
                                                {{ scheduleSummary(dj.schedules) }}
                                            </span>
                                            <span
                                                v-else
                                                class="text-muted"
                                            >{{ $gettext('No schedule') }}</span>
                                        </td>
                                        <td class="dj-actions">
                                            <button
                                                type="button"
                                                class="btn btn-secondary btn-sm me-1"
                                                :disabled="isTesting === dj.id"
                                                @click="runTest(dj)"
                                            >
                                                <span
                                                    v-if="isTesting === dj.id"
                                                    class="spinner"
                                                />
                                                <span v-else>▶</span>
                                                {{ $gettext('Test') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-secondary btn-sm me-1"
                                                @click="openEdit(dj)"
                                            >
                                                {{ $gettext('Edit') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-info btn-sm me-1"
                                                @click="openSchedules(dj)"
                                            >
                                                {{ $gettext('Schedules') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-danger btn-sm"
                                                @click="confirmDelete(dj)"
                                            >
                                                {{ $gettext('Delete') }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- DJ Editor -->
                    <section
                        v-if="editorOpen"
                        class="dashboard-card editor-card"
                    >
                        <div class="dashboard-card-title">
                            {{ editingDj ? $gettext('Edit DJ') : $gettext('Create DJ') }}
                        </div>

                        <form @submit.prevent="saveForm">
                            <form-group-field
                                id="dj_name"
                                :field="v$.name"
                            >
                                <template #label>
                                    {{ $gettext('Name') }}
                                    <span class="text-danger">*</span>
                                </template>
                                <template #default="{id, model}">
                                    <input
                                        :id="id"
                                        v-model="model.$model"
                                        class="form-control form-control-dark"
                                        type="text"
                                        :placeholder="$gettext('e.g. Morning Mix Mike')"
                                        required
                                    >
                                </template>
                            </form-group-field>

                            <form-group-field
                                id="dj_voice_model_path"
                                :field="v$.voice_model_path"
                            >
                                <template #label>
                                    {{ $gettext('AI Voice') }}
                                </template>
                                <template #default="{model}">
                                    <form-select
                                        v-model="model.$model"
                                        class="form-control-dark"
                                        :options="voiceSelectOptions"
                                    />
                                </template>
                                <template #description>
                                    {{ $gettext('Choose an installed Piper voice model.') }}
                                </template>
                            </form-group-field>

                            <div class="toggle-row mb-3">
                                <div>
                                    <div class="toggle-label">
                                        {{ $gettext('Enabled') }}
                                    </div>
                                    <div class="toggle-helper">
                                        {{ $gettext('Allow this DJ to be scheduled and inject intros.') }}
                                    </div>
                                </div>
                                <label class="toggle">
                                    <input
                                        v-model="form.is_enabled"
                                        type="checkbox"
                                    >
                                    <span class="slider" />
                                </label>
                            </div>

                            <div class="mb-3">
                                <label
                                    for="dj_talk_frequency"
                                    class="form-label"
                                >
                                    {{ $gettext('Talk Frequency') }}
                                    <span class="text-muted ms-1">{{ Math.round(form.talk_frequency * 100) }}%</span>
                                </label>
                                <input
                                    id="dj_talk_frequency"
                                    v-model.number="form.talk_frequency"
                                    type="range"
                                    class="form-range"
                                    min="0"
                                    max="1"
                                    step="0.05"
                                >
                                <div class="form-text">
                                    {{ $gettext('How often the DJ speaks between songs. 0% = never, 100% = every song.') }}
                                </div>
                            </div>

                            <form-group-field
                                id="dj_shift_intro_template"
                                :field="v$.shift_intro_template"
                            >
                                <template #label>
                                    {{ $gettext('Shift Intro Template') }}
                                </template>
                                <template #default="{id, model}">
                                    <textarea
                                        :id="id"
                                        v-model="model.$model"
                                        class="form-control form-control-dark"
                                        rows="3"
                                        :placeholder="defaultIntroTemplate"
                                    />
                                </template>
                                <template #description>
                                    {{ $gettext('Template read when this DJ starts a shift. Variables: {dj_name}, {station_name}.') }}
                                </template>
                            </form-group-field>

                            <form-group-field
                                id="dj_shift_outro_template"
                                :field="v$.shift_outro_template"
                            >
                                <template #label>
                                    {{ $gettext('Shift Sign-off Template') }}
                                </template>
                                <template #default="{id, model}">
                                    <textarea
                                        :id="id"
                                        v-model="model.$model"
                                        class="form-control form-control-dark"
                                        rows="3"
                                        :placeholder="defaultOutroTemplate"
                                    />
                                </template>
                                <template #description>
                                    {{ $gettext('Template read when this DJ ends a shift. Variables: {dj_name}, {station_name}.') }}
                                </template>
                            </form-group-field>

                            <div class="btn-row">
                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                    :disabled="isSaving"
                                >
                                    {{ isSaving ? $gettext('Saving…') : $gettext('Save DJ') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="closeEditor"
                                >
                                    {{ $gettext('Cancel') }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <!-- Delete Confirm -->
                    <section
                        v-if="deleteTarget"
                        class="dashboard-card delete-card"
                    >
                        <div class="dashboard-card-title">
                            {{ $gettext('Confirm Delete') }}
                        </div>
                        <p>
                            {{ $gettext('Delete DJ "%{name}"? This cannot be undone.', { name: deleteTarget.name }) }}
                        </p>
                        <div class="btn-row">
                            <button
                                type="button"
                                class="btn btn-danger"
                                :disabled="isDeleting"
                                @click="doDelete"
                            >
                                {{ isDeleting ? $gettext('Deleting…') : $gettext('Delete') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-secondary"
                                @click="deleteTarget = null"
                            >
                                {{ $gettext('Cancel') }}
                            </button>
                        </div>
                    </section>
                </div>
            </loading>

            <ai-dj-schedule-modal ref="scheduleModalRef" />
        </section>

        <!-- Content Library -->
        <section
            class="ai-dj-shell"
            style="margin-top: 1.5rem;"
            role="region"
            aria-labelledby="hdr_content_library"
        >
            <div class="dashboard-card">
                <ai-dj-content-library />
            </div>
        </section>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from "vue";
import {useGettext} from "vue3-gettext";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormSelect from "~/components/Form/FormSelect.vue";
import AiDjScheduleModal from "~/components/Stations/AiDjScheduleModal.vue";
import AiDjContentLibrary from "~/components/Stations/AiDjContentLibrary.vue";
import Loading from "~/components/Common/Loading.vue";
import {useAxios} from "~/vendor/axios";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useAppRegle} from "~/vendor/regle.ts";
import {useResettableRef} from "~/functions/useResettableRef.ts";

// --- Types ---

interface AiDjSchedule {
    id?: number;
    loop_days?: number[];
    start_time?: string | null;
    end_time?: string | null;
}

interface AiDj {
    id: number;
    name: string;
    voice_model_path: string | null;
    is_enabled: boolean;
    shift_intro_template: string | null;
    shift_outro_template: string | null;
    talk_frequency: number;
    schedules?: AiDjSchedule[];
}

interface AiDjForm {
    name: string;
    voice_model_path: string | null;
    is_enabled: boolean;
    shift_intro_template: string | null;
    shift_outro_template: string | null;
    talk_frequency: number;
}

interface VoiceOption {
    label: string;
    path: string;
}

// --- Setup ---

const {$gettext} = useGettext();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {getStationApiUrl} = useApiRouter();

const listUrl = getStationApiUrl('/ai-dj');

const djUrl = (id: number) => getStationApiUrl(`/ai-dj/${id}`);
const djTestUrl = (id: number) => getStationApiUrl(`/ai-dj/${id}/test`);

const defaultIntroTemplate = 'This is {{dj_name}} on {{station_name}}';
const defaultOutroTemplate = 'This has been {{dj_name}} on {{station_name}}. Thanks for listening!';

// --- State ---

const isLoading = ref(true);
const isSaving = ref(false);
const isDeleting = ref(false);
const isTesting = ref<number | null>(null);
const djs = ref<AiDj[]>([]);
const voiceOptions = ref<VoiceOption[]>([]);
const editorOpen = ref(false);
const editingDj = ref<AiDj | null>(null);
const deleteTarget = ref<AiDj | null>(null);
const scheduleModalRef = ref<InstanceType<typeof AiDjScheduleModal> | null>(null);

const {record: form, reset: resetForm} = useResettableRef<AiDjForm>(() => ({
    name: '',
    voice_model_path: null,
    is_enabled: true,
    shift_intro_template: null,
    shift_outro_template: null,
    talk_frequency: 0.5,
}));

const {r$: v$} = useAppRegle(form, {}, {});

// --- Computed ---

const voiceSelectOptions = computed(() => {
    const base = [{text: $gettext('— Select a voice —'), value: null as string | null}];
    const mapped = voiceOptions.value.map((v) => ({
        text: v.label,
        value: v.path,
    }));
    const all = [...base, ...mapped];

    if (
        form.value.voice_model_path &&
        !mapped.some((o) => o.value === form.value.voice_model_path)
    ) {
        all.push({text: $gettext('Custom Path'), value: form.value.voice_model_path});
    }

    return all;
});

// --- Helpers ---

const voiceLabel = (path: string | null): string => {
    if (!path) return '—';
    const match = voiceOptions.value.find((v) => v.path === path);
    if (match) return match.label;
    const parts = path.split('/');
    return parts[parts.length - 1] ?? path;
};

const dayNames: string[] = [
    $gettext('Mon'),
    $gettext('Tue'),
    $gettext('Wed'),
    $gettext('Thu'),
    $gettext('Fri'),
    $gettext('Sat'),
    $gettext('Sun'),
];

const scheduleSummary = (schedules: AiDjSchedule[]): string => {
    if (!schedules || schedules.length === 0) return $gettext('No schedule');

    return schedules.map((s) => {
        const days =
            s.loop_days && s.loop_days.length > 0
                ? s.loop_days.map((d) => dayNames[d - 1] ?? String(d)).join(', ')
                : $gettext('Every day');
        const time =
            s.start_time && s.end_time
                ? `${s.start_time}–${s.end_time}`
                : $gettext('All day');
        return `${days} ${time}`;
    }).join(' | ');
};

// --- API ---

const loadDjs = async (): Promise<void> => {
    isLoading.value = true;
    try {
        const resp = await axios.get<{rows?: AiDj[]; voice_options?: VoiceOption[]} | AiDj[]>(listUrl.value);
        const data = resp.data;
        if (Array.isArray(data)) {
            djs.value = data;
        } else {
            djs.value = data.rows ?? [];
            if (data.voice_options) {
                voiceOptions.value = data.voice_options;
            }
        }
    } catch {
        notifyError($gettext('Failed to load DJ list.'));
    } finally {
        isLoading.value = false;
    }
};

// --- Editor ---

const openCreate = (): void => {
    editingDj.value = null;
    resetForm();
    editorOpen.value = true;
    deleteTarget.value = null;
};

const openEdit = (dj: AiDj): void => {
    editingDj.value = dj;
    form.value = {
        name: dj.name,
        voice_model_path: dj.voice_model_path,
        is_enabled: dj.is_enabled,
        shift_intro_template: dj.shift_intro_template,
        shift_outro_template: dj.shift_outro_template,
        talk_frequency: dj.talk_frequency ?? 0.5,
    };
    editorOpen.value = true;
    deleteTarget.value = null;
};

const closeEditor = (): void => {
    editorOpen.value = false;
    editingDj.value = null;
    resetForm();
};

const saveForm = async (): Promise<void> => {
    isSaving.value = true;
    try {
        if (editingDj.value) {
            await axios.put(djUrl(editingDj.value.id).value, form.value);
            notifySuccess($gettext('DJ updated.'));
        } else {
            await axios.post(listUrl.value, form.value);
            notifySuccess($gettext('DJ created.'));
        }
        closeEditor();
        await loadDjs();
    } catch {
        notifyError($gettext('Failed to save DJ.'));
    } finally {
        isSaving.value = false;
    }
};

// --- Delete ---

const openSchedules = (dj: AiDj): void => {
    scheduleModalRef.value?.open(dj.id, dj.name);
};

const confirmDelete = (dj: AiDj): void => {
    deleteTarget.value = dj;
    editorOpen.value = false;
};

const doDelete = async (): Promise<void> => {
    if (!deleteTarget.value) return;
    isDeleting.value = true;
    try {
        await axios.delete(djUrl(deleteTarget.value.id).value);
        notifySuccess($gettext('DJ deleted.'));
        deleteTarget.value = null;
        await loadDjs();
    } catch {
        notifyError($gettext('Failed to delete DJ.'));
    } finally {
        isDeleting.value = false;
    }
};

// --- Test Generation ---

const runTest = async (dj: AiDj): Promise<void> => {
    isTesting.value = dj.id;
    try {
        await axios.get(djTestUrl(dj.id).value);
        notifySuccess($gettext('Test generation queued for "%{name}".', {name: dj.name}));
    } catch {
        notifyError($gettext('Test generation failed.'));
    } finally {
        isTesting.value = null;
    }
};

// --- Init ---

onMounted(async () => {
    await Promise.all([loadDjs()]);
});
</script>

<style scoped lang="scss">
.ai-dj-page {
    padding: 1rem;
}

.ai-dj-shell {
    max-width: 1100px;
    margin: 0 auto;
}

.ai-dj-topbar {
    display: flex;
    align-items: center;
    margin-bottom: 1.5rem;
}

.ai-dj-branding {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.logo-dot {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--bs-primary, #5a7fd4);
    flex-shrink: 0;
}

.ai-dj-title {
    font-size: 1.4rem;
    font-weight: 700;
    margin: 0;
}

.ai-dj-subtitle {
    font-size: 0.85rem;
    color: var(--bs-secondary-color, #888);
}

.ai-dj-container {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.dashboard-card {
    background: var(--bs-body-bg, #1a1d23);
    border: 1px solid var(--bs-border-color, #2d3140);
    border-radius: 8px;
    padding: 1.25rem;
}

.dashboard-card-title {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 1rem;
    color: var(--bs-body-color, #e0e0e0);
}

.dj-list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}

.dj-empty {
    color: var(--bs-secondary-color, #888);
    text-align: center;
    padding: 2rem 0;
}

.dj-table-wrap {
    overflow-x: auto;
}

.dj-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;

    th,
    td {
        padding: 0.6rem 0.75rem;
        text-align: left;
        border-bottom: 1px solid var(--bs-border-color, #2d3140);
    }

    th {
        font-weight: 600;
        color: var(--bs-secondary-color, #888);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    tr:last-child td {
        border-bottom: none;
    }

    tr:hover td {
        background: var(--bs-tertiary-bg, #22252e);
    }
}

.dj-name {
    font-weight: 600;
}

.voice-badge {
    display: inline-block;
    background: var(--bs-tertiary-bg, #22252e);
    border-radius: 4px;
    padding: 0.15rem 0.5rem;
    font-size: 0.8rem;
    font-family: monospace;
}

.status-badge {
    display: inline-block;
    border-radius: 12px;
    padding: 0.15rem 0.6rem;
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;

    &.status-on {
        background: rgba(40, 167, 69, 0.18);
        color: #5cb85c;
    }

    &.status-off {
        background: rgba(108, 117, 125, 0.18);
        color: var(--bs-secondary-color, #888);
    }
}

.schedule-summary {
    font-size: 0.8rem;
    color: var(--bs-secondary-color, #aaa);
}

.dj-actions-col {
    width: 1%;
    white-space: nowrap;
}

.dj-actions {
    white-space: nowrap;
}

.editor-card {
    border-color: var(--bs-primary, #5a7fd4);
}

.delete-card {
    border-color: var(--bs-danger, #dc3545);
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

.toggle-helper {
    font-size: 0.8rem;
    color: var(--bs-secondary-color, #888);
    margin-top: 0.2rem;
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

.spinner {
    display: inline-block;
    width: 12px;
    height: 12px;
    border: 2px solid currentColor;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
    vertical-align: middle;
    margin-right: 4px;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.btn-row {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
}

@media (max-width: 768px) {
    .dj-table th:nth-child(4),
    .dj-table td:nth-child(4) {
        display: none;
    }
}
</style>
