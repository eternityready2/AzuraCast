<template>
    <div class="d-flex flex-column gap-4">
        <section
            class="card"
            role="region"
            aria-labelledby="hdr_ai_dj"
        >
            <div class="card-header text-bg-primary">
                <div class="d-flex align-items-center">
                    <h2
                        id="hdr_ai_dj"
                        class="card-title flex-fill my-0 d-flex align-items-center gap-2"
                    >
                        <icon-ic-baseline-mic />
                        {{ $gettext('AI DJ') }}
                    </h2>
                </div>
            </div>

            <loading
                :loading="isLoading"
                lazy
            >
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h3 class="h6 mb-0">
                            {{ $gettext('DJ Personalities') }}
                        </h3>
                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            @click="openCreate"
                        >
                            <icon-ic-baseline-add />
                            {{ $gettext('Create DJ') }}
                        </button>
                    </div>

                    <p
                        v-if="djs.length === 0"
                        class="text-muted text-center py-4 mb-0"
                    >
                        {{ $gettext('No DJ personalities configured yet. Create one to get started.') }}
                    </p>

                    <div
                        v-else
                        class="table-responsive"
                    >
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">
                                        {{ $gettext('Name') }}
                                    </th>
                                    <th scope="col">
                                        {{ $gettext('Voice Model') }}
                                    </th>
                                    <th scope="col">
                                        {{ $gettext('Status') }}
                                    </th>
                                    <th scope="col">
                                        {{ $gettext('Schedule') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="shrink"
                                    >
                                        {{ $gettext('Actions') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="dj in djs"
                                    :key="dj.id"
                                    class="ai-dj-personality-row"
                                >
                                    <td class="fw-semibold">
                                        {{ dj.name }}
                                        <span
                                            v-if="activeDjId === dj.id"
                                            class="ai-dj-live-badge ms-1"
                                        >
                                            <span
                                                class="ai-dj-live-dot"
                                                aria-hidden="true"
                                            />
                                            {{ $gettext('LIVE') }}
                                        </span>
                                    </td>
                                    <td>
                                        <span
                                            v-if="dj.voice_model_path"
                                            class="badge text-bg-secondary font-monospace fw-normal"
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
                                            class="badge"
                                            :class="dj.is_enabled ? 'text-bg-success' : 'text-bg-secondary'"
                                        >
                                            {{ dj.is_enabled ? $gettext('Enabled') : $gettext('Disabled') }}
                                        </span>
                                    </td>
                                    <td>
                                        <span
                                            v-if="dj.schedules && dj.schedules.length > 0"
                                            class="small"
                                        >
                                            {{ scheduleSummary(dj.schedules) }}
                                        </span>
                                        <span
                                            v-else
                                            class="text-muted"
                                        >{{ $gettext('No schedule') }}</span>
                                    </td>
                                    <td class="shrink">
                                        <div
                                            class="btn-group btn-group-sm"
                                            role="group"
                                            :aria-label="$gettext('DJ Actions')"
                                        >
                                            <button
                                                type="button"
                                                class="btn btn-secondary"
                                                :disabled="isTesting === dj.id"
                                                @click="runTest(dj)"
                                            >
                                                <span
                                                    v-if="isTesting === dj.id"
                                                    class="spinner-border spinner-border-sm"
                                                    role="status"
                                                    aria-hidden="true"
                                                />
                                                <icon-ic-baseline-play-arrow v-else />
                                                {{ $gettext('Test') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-secondary"
                                                @click="openEdit(dj)"
                                            >
                                                {{ $gettext('Edit') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-info"
                                                @click="openSchedules(dj)"
                                            >
                                                {{ $gettext('Schedules') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-danger"
                                                @click="confirmDelete(dj)"
                                            >
                                                {{ $gettext('Delete') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="editorOpen"
                        class="card card-body bg-body-tertiary mt-4"
                    >
                        <h3 class="h6">
                            {{ editingDj ? $gettext('Edit DJ') : $gettext('Create DJ') }}
                        </h3>

                        <form @submit.prevent="saveForm">
                            <h4 class="ai-dj-form-section">
                                {{ $gettext('Basic Info') }}
                            </h4>

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
                                        class="form-control"
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
                                        :options="voiceSelectOptions"
                                    />
                                </template>
                                <template #description>
                                    {{ $gettext('Choose a voice for this DJ personality.') }}
                                </template>
                            </form-group-field>

                            <div class="form-check form-switch mb-3">
                                <input
                                    id="dj_is_enabled"
                                    v-model="form.is_enabled"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                >
                                <label
                                    class="form-check-label"
                                    for="dj_is_enabled"
                                >
                                    {{ $gettext('Enabled') }}
                                </label>
                                <div class="form-text">
                                    {{ $gettext('Allow this DJ to be scheduled and inject intros.') }}
                                </div>
                            </div>

                            <h4 class="ai-dj-form-section">
                                {{ $gettext('Voice & Delivery') }}
                            </h4>

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

                            <div class="mb-3">
                                <label
                                    for="dj_voice_speed"
                                    class="form-label"
                                >
                                    {{ $gettext('Voice Speed') }}
                                    <span class="text-muted ms-1">{{ form.voice_speed.toFixed(1) }}x</span>
                                </label>
                                <input
                                    id="dj_voice_speed"
                                    v-model.number="form.voice_speed"
                                    type="range"
                                    class="form-range"
                                    min="0.7"
                                    max="1.5"
                                    step="0.1"
                                >
                                <div class="form-text">
                                    {{ $gettext('Speed of DJ speech. 0.7 = slow/calm, 1.0 = normal, 1.5 = fast/energetic.') }}
                                </div>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input
                                    id="dj_use_background_audio"
                                    v-model="form.use_background_audio"
                                    type="checkbox"
                                    class="form-check-input"
                                    role="switch"
                                >
                                <label
                                    for="dj_use_background_audio"
                                    class="form-check-label"
                                >
                                    {{ $gettext('Background Audio') }}
                                </label>
                                <div class="form-text">
                                    {{ $gettext('Adds a soft ambient music bed underneath DJ voice clips. Great for overnight shifts.') }}
                                </div>
                            </div>

                            <h4 class="ai-dj-form-section">
                                {{ $gettext('Shift Announcements') }}
                            </h4>

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
                                        class="form-control"
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
                                        class="form-control"
                                        rows="3"
                                        :placeholder="defaultOutroTemplate"
                                    />
                                </template>
                                <template #description>
                                    {{ $gettext('Template read when this DJ ends a shift. Variables: {dj_name}, {station_name}.') }}
                                </template>
                            </form-group-field>

                            <div class="d-flex gap-2">
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
                    </div>

                    <div
                        v-if="deleteTarget"
                        class="card card-body border-danger mt-4"
                    >
                        <h3 class="h6">
                            {{ $gettext('Confirm Delete') }}
                        </h3>
                        <p>
                            {{ $gettext('Delete DJ "%{name}"? This cannot be undone.', { name: deleteTarget.name }) }}
                        </p>
                        <div class="d-flex gap-2">
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
                    </div>
                </div>
            </loading>

            <ai-dj-schedule-modal ref="scheduleModalRef" />
        </section>

        <form
            class="card"
            role="region"
            aria-labelledby="hdr_ai_dj_talk_rules"
            @submit.prevent="saveTalkRules"
        >
            <div class="card-header text-bg-primary">
                <h2
                    id="hdr_ai_dj_talk_rules"
                    class="card-title my-0 d-flex align-items-center gap-2"
                >
                    <icon-ic-baseline-tune />
                    {{ $gettext('Talk Rules') }}
                </h2>
            </div>

            <loading
                :loading="talkRulesLoading"
                lazy
            >
                <div class="card-body">
                    <p class="text-muted">
                        {{ $gettext('Station-wide rules for when AI DJ breaks may air. These apply to every DJ personality and take effect on the next queued break.') }}
                    </p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label
                                class="form-label fw-semibold"
                                for="ai_dj_ident_interval_minutes"
                            >{{ $gettext('Station/DJ Name Interval (minutes)') }}</label>
                            <input
                                id="ai_dj_ident_interval_minutes"
                                v-model.number="talkRules.ai_dj_ident_interval_minutes"
                                type="number"
                                class="form-control"
                                min="0"
                                max="120"
                                required
                            >
                            <div class="form-text">
                                {{ $gettext('Minimum time between breaks where the DJ says her name and the station name. 0 = every break. (0–120)') }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label
                                class="form-label fw-semibold"
                                for="ai_dj_quiet_before_hour_minutes"
                            >{{ $gettext('Quiet Before Top of Hour (minutes)') }}</label>
                            <input
                                id="ai_dj_quiet_before_hour_minutes"
                                v-model.number="talkRules.ai_dj_quiet_before_hour_minutes"
                                type="number"
                                class="form-control"
                                min="0"
                                max="30"
                                required
                            >
                            <div class="form-text">
                                {{ $gettext('No DJ break airs in this many minutes before :00, so talk never runs into the Station ID. (0–30)') }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label
                                class="form-label fw-semibold"
                                for="ai_dj_quiet_after_hour_minutes"
                            >{{ $gettext('Quiet After Top of Hour (minutes)') }}</label>
                            <input
                                id="ai_dj_quiet_after_hour_minutes"
                                v-model.number="talkRules.ai_dj_quiet_after_hour_minutes"
                                type="number"
                                class="form-control"
                                min="0"
                                max="15"
                                required
                            >
                            <div class="form-text">
                                {{ $gettext('No DJ break is queued in the first minutes after :00, leaving room for the ID and news. (0–15)') }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-end">
                    <button
                        type="submit"
                        class="btn btn-primary"
                        :disabled="talkRulesLoading || talkRulesSaving"
                    >
                        {{ $gettext('Save Talk Rules') }}
                    </button>
                </div>
            </loading>
        </form>

        <section
            class="card"
            role="region"
            aria-labelledby="hdr_content_library"
        >
            <div class="card-header text-bg-primary">
                <div class="d-flex align-items-center">
                    <h2
                        id="hdr_content_library"
                        class="card-title flex-fill my-0 d-flex align-items-center gap-2"
                    >
                        <icon-ic-baseline-library-music />
                        {{ $gettext('Content Library') }}
                    </h2>
                </div>
            </div>

            <div class="card-body">
                <ai-dj-content-library />
            </div>
        </section>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref} from "vue";
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
    voice_speed: number;
    use_background_audio: boolean;
    schedules?: AiDjSchedule[];
}

interface AiDjForm {
    name: string;
    voice_model_path: string | null;
    is_enabled: boolean;
    shift_intro_template: string | null;
    shift_outro_template: string | null;
    talk_frequency: number;
    voice_speed: number;
    use_background_audio: boolean;
}

interface VoiceOption {
    label: string;
    path: string;
}

const {$gettext} = useGettext();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {getStationApiUrl} = useApiRouter();

const listUrl = getStationApiUrl('/ai-dj');

const djUrl = (id: number) => getStationApiUrl(`/ai-dj/${id}`);
const djTestUrl = (id: number) => getStationApiUrl(`/ai-dj/${id}/test`);

const defaultIntroTemplate = 'This is {{dj_name}} on {{station_name}}';
const defaultOutroTemplate = 'This has been {{dj_name}} on {{station_name}}. Thanks for listening!';

const isLoading = ref(true);
const isSaving = ref(false);
const isDeleting = ref(false);
const isTesting = ref<number | null>(null);
const djs = ref<AiDj[]>([]);
const voiceOptions = ref<VoiceOption[]>([]);
const activeDjId = ref<number | null>(null);
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
    voice_speed: 1.0,
    use_background_audio: false,
}));

const {r$: v$} = useAppRegle(form, {}, {});

const voiceSelectOptions = computed(() => {
    const base = [{text: $gettext('— Select a voice —'), value: null as string | null}];

    const kokoro = voiceOptions.value
        .filter((v) => v.path.startsWith('kokoro:'))
        .map((v) => ({text: v.label, value: v.path}));

    const piper = voiceOptions.value
        .filter((v) => !v.path.startsWith('kokoro:'))
        .map((v) => ({text: v.label, value: v.path}));

    const all = [...base];
    if (kokoro.length > 0) {
        all.push({text: `── ${$gettext('Kokoro (Human-like)')} ──`, value: '__kokoro_header__'});
        all.push(...kokoro);
    }
    if (piper.length > 0) {
        all.push({text: `── ${$gettext('Piper (Lightweight)')} ──`, value: '__piper_header__'});
        all.push(...piper);
    }

    const mapped = voiceOptions.value.map((v) => v.path);
    if (
        form.value.voice_model_path &&
        !mapped.includes(form.value.voice_model_path)
    ) {
        all.push({text: $gettext('Custom Path'), value: form.value.voice_model_path});
    }

    return all;
});

const voiceLabel = (path: string | null): string => {
    if (!path) return '—';
    const match = voiceOptions.value.find((v) => v.path === path);
    if (match) return match.label;
    if (path.startsWith('kokoro:')) return path.replace('kokoro:', 'Kokoro: ');
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

const to12Hour = (t: string): string => {
    const parts = t.split(':');
    let h = parseInt(parts[0] ?? '0', 10);
    const m = parts[1] ?? '00';
    if (isNaN(h)) return t;
    const period = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    if (h === 0) h = 12;
    return `${h}:${m} ${period}`;
};

const scheduleSummary = (schedules: AiDjSchedule[]): string => {
    if (!schedules || schedules.length === 0) return $gettext('No schedule');

    return schedules.map((s) => {
        const days =
            s.loop_days && s.loop_days.length > 0
                ? s.loop_days.map((d) => dayNames[d - 1] ?? String(d)).join(', ')
                : $gettext('Every day');
        const time =
            s.start_time && s.end_time
                ? `${to12Hour(s.start_time)} - ${to12Hour(s.end_time)}`
                : $gettext('All day');
        return `${days} ${time}`;
    }).join(' | ');
};

const loadDjs = async (): Promise<void> => {
    isLoading.value = true;
    try {
        const resp = await axios.get<{rows?: AiDj[]; voice_options?: VoiceOption[]; active_dj_id?: number | null} | AiDj[]>(listUrl.value);
        const data = resp.data;
        if (Array.isArray(data)) {
            djs.value = data;
        } else {
            djs.value = data.rows ?? [];
            if (data.voice_options) {
                voiceOptions.value = data.voice_options;
            }
            activeDjId.value = data.active_dj_id ?? null;
        }
    } catch {
        notifyError($gettext('Failed to load DJ list.'));
    } finally {
        isLoading.value = false;
    }
};

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
        voice_speed: dj.voice_speed ?? 1.0,
        use_background_audio: dj.use_background_audio ?? false,
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

/** Polls for the currently-live DJ so the "Live" badge stays in sync without a full page reload. */
const LIVE_STATUS_POLL_INTERVAL_MS = 30_000;
let liveRefreshTimer: ReturnType<typeof setInterval> | null = null;

interface TalkRules {
    ai_dj_ident_interval_minutes: number;
    ai_dj_quiet_before_hour_minutes: number;
    ai_dj_quiet_after_hour_minutes: number;
}

const talkRulesUrl = getStationApiUrl('/ai-dj-talk-rules');
const talkRulesLoading = ref(true);
const talkRulesSaving = ref(false);
const talkRules = ref<TalkRules>({
    ai_dj_ident_interval_minutes: 20,
    ai_dj_quiet_before_hour_minutes: 15,
    ai_dj_quiet_after_hour_minutes: 3,
});

const loadTalkRules = async () => {
    talkRulesLoading.value = true;
    try {
        const {data} = await axios.get<TalkRules>(talkRulesUrl.value);
        talkRules.value = data;
    } finally {
        talkRulesLoading.value = false;
    }
};

const saveTalkRules = async () => {
    talkRulesSaving.value = true;
    try {
        await axios.put(talkRulesUrl.value, talkRules.value);
        notifySuccess($gettext('Talk rules saved.'));
        await loadTalkRules();
    } catch {
        notifyError();
    } finally {
        talkRulesSaving.value = false;
    }
};

onMounted(async () => {
    void loadTalkRules();
    await loadDjs();

    liveRefreshTimer = setInterval(async () => {
        try {
            const resp = await axios.get<{active_dj_id?: number | null}>(listUrl.value);
            if (!Array.isArray(resp.data) && resp.data.active_dj_id !== undefined) {
                activeDjId.value = resp.data.active_dj_id;
            }
        } catch {
            // Non-fatal: the live badge simply won't update until the next successful poll.
        }
    }, LIVE_STATUS_POLL_INTERVAL_MS);
});

onUnmounted(() => {
    if (liveRefreshTimer) clearInterval(liveRefreshTimer);
});
</script>

<style scoped>
.ai-dj-live-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.15rem 0.6rem;
    border-radius: 50rem;
    background-color: rgba(var(--bs-success-rgb), 0.15);
    color: var(--bs-success);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    vertical-align: middle;
}

.ai-dj-live-dot {
    position: relative;
    display: inline-block;
    width: 0.55rem;
    height: 0.55rem;
}

.ai-dj-live-dot::before,
.ai-dj-live-dot::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background-color: var(--bs-success);
}

.ai-dj-live-dot::before {
    z-index: 1;
    animation: ai-dj-live-glow 1.3s ease-in-out infinite;
}

.ai-dj-live-dot::after {
    z-index: 0;
    animation: ai-dj-live-ping 1.3s cubic-bezier(0, 0, 0.2, 1) infinite;
}

@media (prefers-reduced-motion: reduce) {
    .ai-dj-live-dot::before,
    .ai-dj-live-dot::after {
        animation: none;
    }
}

@keyframes ai-dj-live-glow {
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 0 0.35rem 0.05rem rgba(var(--bs-success-rgb), 0.9);
    }
    50% {
        transform: scale(1.25);
        box-shadow: 0 0 0.6rem 0.15rem rgba(var(--bs-success-rgb), 1);
    }
}

@keyframes ai-dj-live-ping {
    0% {
        transform: scale(1);
        opacity: 0.7;
    }
    75%, 100% {
        transform: scale(2.6);
        opacity: 0;
    }
}

.ai-dj-personality-row:hover {
    background-color: var(--bs-tertiary-bg);
}

th.shrink,
td.shrink {
    width: 0.1%;
    white-space: nowrap;
}

.ai-dj-form-section {
    margin: 1.5rem 0 1rem;
    padding-bottom: 0.35rem;
    border-bottom: 1px solid var(--bs-border-color);
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--bs-secondary-color);
}

.ai-dj-form-section:first-child {
    margin-top: 0;
}
</style>
