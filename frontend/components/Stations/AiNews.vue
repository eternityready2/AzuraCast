<template>
    <form
        class="form"
        @submit.prevent="saveChanges"
    >
        <section
            class="card"
            role="region"
            aria-labelledby="hdr_ai_news"
        >
            <div class="card-header text-bg-primary">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h2
                        id="hdr_ai_news"
                        class="card-title flex-fill my-0 d-flex align-items-center gap-2"
                    >
                        <icon-ic-baseline-campaign />
                        {{ $gettext('AI News Bulletin') }}
                    </h2>
                    <span
                        v-if="form.ai_news_enabled"
                        class="ai-news-live-badge"
                    >
                        <span
                            class="ai-news-live-dot"
                            aria-hidden="true"
                        />
                        {{ $gettext('Streaming Live') }}
                    </span>
                    <span
                        v-else
                        class="ai-news-disabled-badge"
                    >
                        <span
                            class="ai-news-disabled-dot"
                            aria-hidden="true"
                        />
                        {{ $gettext('Bulletin Disabled') }}
                    </span>
                </div>
            </div>

            <loading
                :loading="isLoading"
                lazy
            >
                <div class="card-body">
                    <div class="alert alert-info small mb-4">
                        <p class="mb-0">
                            {{ $gettext('Active hours format: start and end times use 12-hour text like 01:00 AM. Leave both blank to run all day. Source URLs should be one per line, and each must be a valid RSS or Atom feed URL.') }}
                        </p>
                    </div>

                    <h3 class="text-muted text-uppercase small fw-bold mb-3">
                        {{ $gettext('System Status') }}
                    </h3>
                    <div class="row row-cols-2 row-cols-md-3 g-2 mb-4">
                        <div
                            v-for="item in statusCards"
                            :key="item.label"
                            class="col"
                        >
                            <div class="card card-body h-100 py-2 px-3">
                                <div class="text-muted text-uppercase small">
                                    {{ item.label }}
                                </div>
                                <div
                                    class="fw-semibold"
                                    :class="item.tone"
                                    style="white-space: pre-line;"
                                >
                                    {{ item.value }}
                                </div>
                                <div
                                    v-if="item.helper"
                                    class="text-muted small"
                                >
                                    {{ item.helper }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4 align-items-start">
                        <div class="col-lg-6 d-flex flex-column gap-4">
                            <div class="card">
                                <div class="card-body">
                                    <h3 class="text-muted text-uppercase small fw-bold mb-3">
                                        {{ $gettext('Generate Bulletin') }}
                                    </h3>

                                    <div class="text-center py-2">
                                        <button
                                            type="button"
                                            class="btn btn-primary btn-lg"
                                            :disabled="isGenerateDisabled"
                                            @click="runTest"
                                        >
                                            <span
                                                v-if="isTesting"
                                                class="spinner-border spinner-border-sm"
                                                role="status"
                                                aria-hidden="true"
                                            />
                                            <icon-ic-baseline-play-arrow v-else-if="form.ai_news_enabled" />
                                            <icon-ic-baseline-stop v-else />
                                            {{ generateButtonText }}
                                        </button>
                                        <div
                                            v-if="generateHelpText"
                                            class="text-danger small mt-2"
                                        >
                                            {{ generateHelpText }}
                                        </div>
                                    </div>

                                    <div class="ai-news-summary-box mt-3">
                                        <div class="text-muted small mb-2">
                                            {{ $gettext('Latest Bulletin') }}
                                        </div>

                                        <div
                                            v-if="audioAvailable && bulletinPlaybackUrl"
                                            class="ai-news-status-callout ai-news-status-success small"
                                        >
                                            <icon-ic-baseline-check />
                                            <span>{{ latestBulletinText }}</span>
                                        </div>
                                        <div
                                            v-else-if="lastStatus === 'error'"
                                            class="ai-news-status-callout ai-news-status-danger small"
                                        >
                                            <icon-ic-baseline-warning />
                                            <span>{{ latestBulletinText }}</span>
                                        </div>
                                        <div
                                            v-else
                                            class="small text-muted"
                                        >
                                            {{ latestBulletinText }}
                                        </div>

                                        <div
                                            v-if="audioAvailable && bulletinPlaybackUrl"
                                            class="ai-news-player mt-3"
                                        >
                                            <audio
                                                :key="bulletinPlaybackUrl"
                                                class="w-100"
                                                controls
                                                preload="metadata"
                                                :src="bulletinPlaybackUrl"
                                            />
                                            <div class="d-flex flex-wrap gap-2 mt-3">
                                                <a
                                                    :href="bulletinPlaybackUrl"
                                                    class="btn btn-secondary btn-sm d-inline-flex align-items-center gap-1"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <icon-ic-baseline-open-in-new />
                                                    {{ $gettext('Open Audio') }}
                                                </a>
                                                <a
                                                    :href="bulletinPlaybackUrl"
                                                    class="btn btn-secondary btn-sm d-inline-flex align-items-center gap-1"
                                                    download="news_bulletin.mp3"
                                                >
                                                    <icon-ic-baseline-cloud-download />
                                                    {{ $gettext('Download MP3') }}
                                                </a>
                                            </div>
                                        </div>

                                        <div class="d-flex flex-wrap gap-3 text-muted small mt-3 pt-3 border-top">
                                            <div>{{ metaStoriesText }}</div>
                                            <div>{{ metaSourcesText }}</div>
                                            <div>{{ metaTimeText }}</div>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <div class="text-muted text-uppercase small fw-bold mb-2">
                                            {{ $gettext('Generation Log') }}
                                        </div>
                                        <div class="ai-news-summary-box log-panel">
                                            <div
                                                v-for="entry in logEntries"
                                                :key="entry.id"
                                                class="small font-monospace"
                                                :class="entry.tone"
                                            >
                                                [{{ entry.time }}] {{ entry.message }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <h3 class="text-muted text-uppercase small fw-bold mb-0">
                                        {{ $gettext('Live Headlines Preview') }}
                                    </h3>
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm"
                                        @click="refreshHeadlinePreview"
                                    >
                                        <icon-ic-baseline-refresh />
                                        {{ $gettext('Refresh') }}
                                    </button>
                                </div>

                                <ul class="list-group list-group-flush headline-list">
                                    <li
                                        v-for="headline in headlinePreviewItems"
                                        :key="headline.id"
                                        class="list-group-item d-flex align-items-start gap-2"
                                    >
                                        <span
                                            class="badge text-uppercase"
                                            :class="headline.tone"
                                        >
                                            {{ headline.source }}
                                        </span>
                                        <div>
                                            <div class="fw-semibold small">
                                                {{ headline.title }}
                                            </div>
                                            <div class="text-muted small">
                                                {{ headline.summary }}
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-body">
                                <h3 class="text-muted text-uppercase small fw-bold mb-3">
                                    {{ $gettext('Settings') }}
                                </h3>

                                <div class="mb-4">
                                    <div class="form-check form-switch">
                                        <input
                                            id="ai_news_enabled"
                                            v-model="form.ai_news_enabled"
                                            class="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                        >
                                        <label
                                            class="form-check-label fw-semibold"
                                            for="ai_news_enabled"
                                        >
                                            {{ $gettext('Bulletin Enabled') }}
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        {{ enabledDescription }}
                                    </div>
                                </div>

                                <form-group-field
                                    id="edit_ai_news_reporter_name"
                                    :field="r$.ai_news_reporter_name"
                                
                                    class="mb-4"
>
                                    <template #label>
                                        {{ $gettext('AI Reporter Name') }}
                                    </template>
                                    <template #default="{id, model}">
                                        <input
                                            :id="id"
                                            v-model="model.$model"
                                            class="form-control"
                                            type="text"
                                            :placeholder="$gettext('AzuraCast News Desk')"
                                        >
                                    </template>
                                    <template #description>
                                        {{ $gettext('Optional presenter line read before the bulletin intro.') }}
                                    </template>
                                </form-group-field>

                                <form-group-field
                                    id="edit_ai_news_intro"
                                    :field="r$.ai_news_intro"
                                
                                    class="mb-4"
>
                                    <template #label>
                                        {{ $gettext('Intro Script') }}
                                        <span class="text-muted fw-normal">{{ $gettext('(read at start of every bulletin)') }}</span>
                                    </template>
                                    <template #default="{id, model}">
                                        <textarea
                                            :id="id"
                                            v-model="model.$model"
                                            class="form-control"
                                            rows="4"
                                        />
                                    </template>
                                </form-group-field>

                                <form-group-field
                                    id="edit_ai_news_outro"
                                    :field="r$.ai_news_outro"
                                
                                    class="mb-4"
>
                                    <template #label>
                                        {{ $gettext('Outro Script') }}
                                        <span class="text-muted fw-normal">{{ $gettext('(read at end of every bulletin)') }}</span>
                                    </template>
                                    <template #default="{id, model}">
                                        <textarea
                                            :id="id"
                                            v-model="model.$model"
                                            class="form-control"
                                            rows="3"
                                        />
                                    </template>
                                </form-group-field>

                                <form-group-field
                                    id="edit_ai_news_voice_model_path"
                                    :field="r$.ai_news_voice_model_path"
                                
                                    class="mb-4"
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
                                        {{ $gettext('Choose an installed Piper voice model. Add more voices by downloading additional Piper models onto the server.') }}
                                    </template>
                                </form-group-field>

                                <form-group-field
                                    id="edit_ai_news_story_count"
                                    :field="r$.ai_news_story_count"
                                
                                    class="mb-4"
>
                                    <template #label>
                                        {{ $gettext('Stories Per Bulletin') }}
                                    </template>
                                    <template #default="{id, model}">
                                        <input
                                            :id="id"
                                            v-model="model.$model"
                                            class="form-control"
                                            type="number"
                                            min="1"
                                            max="25"
                                        >
                                    </template>
                                    <template #description>
                                        {{ $gettext('How many headlines to include in each generated bulletin. Range: 1-25.') }}
                                    </template>
                                </form-group-field>

                                <div class="mb-4">
                                    <label class="form-label">{{ $gettext('Broadcast Window') }}</label>
                                    <div class="row g-2">
                                        <div class="col-sm-6">
                                            <label
                                                class="form-label small text-muted"
                                                for="edit_ai_news_start_time"
                                            >
                                                {{ $gettext('Start Time') }}
                                            </label>
                                            <input
                                                id="edit_ai_news_start_time"
                                                v-model="activeHoursStartInput"
                                                class="form-control"
                                                type="text"
                                                :placeholder="$gettext('01:00 AM')"
                                            >
                                            <div class="form-text">
                                                {{ $gettext('Format: 01:00 AM') }}
                                            </div>
                                            <div
                                                v-if="activeHoursStartError"
                                                class="invalid-feedback d-block"
                                            >
                                                {{ activeHoursStartError }}
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <label
                                                class="form-label small text-muted"
                                                for="edit_ai_news_end_time"
                                            >
                                                {{ $gettext('End Time') }}
                                            </label>
                                            <input
                                                id="edit_ai_news_end_time"
                                                v-model="activeHoursEndInput"
                                                class="form-control"
                                                type="text"
                                                :placeholder="$gettext('01:00 AM')"
                                            >
                                            <div class="form-text">
                                                {{ $gettext('Format: 01:00 AM') }}
                                            </div>
                                            <div
                                                v-if="activeHoursEndError"
                                                class="invalid-feedback d-block"
                                            >
                                                {{ activeHoursEndError }}
                                            </div>
                                        </div>
                                    </div>

                                    <form-group-multi-check
                                        id="edit_ai_news_active_days"
                                        v-model="form.ai_news_active_days"
                                        class="mt-2"
                                        :options="dayOptions"
                                    >
                                        <template #label>
                                            {{ $gettext('Run On Days') }}
                                        </template>
                                        <template #description>
                                            {{ $gettext('Leave all days unchecked to allow bulletins every day. Select one or more days to limit when scheduled bulletins can run.') }}
                                        </template>
                                    </form-group-multi-check>

                                    <div class="d-flex flex-wrap gap-3 mt-2">
                                        <div class="form-check form-check-inline mb-0">
                                            <input
                                                id="edit_ai_news_top_of_hour"
                                                v-model="form.ai_news_top_of_hour"
                                                class="form-check-input"
                                                type="checkbox"
                                            >
                                            <label
                                                class="form-check-label"
                                                for="edit_ai_news_top_of_hour"
                                            >{{ $gettext('Top of hour') }}</label>
                                        </div>
                                        <div class="form-check form-check-inline mb-0">
                                            <input
                                                id="edit_ai_news_bottom_of_hour"
                                                v-model="form.ai_news_bottom_of_hour"
                                                class="form-check-input"
                                                type="checkbox"
                                            >
                                            <label
                                                class="form-check-label"
                                                for="edit_ai_news_bottom_of_hour"
                                            >{{ $gettext('Bottom of hour') }}</label>
                                        </div>
                                    </div>
                                    <div class="form-text">
                                        {{ $gettext('Stored as a single HH:MM-HH:MM range in the current AzuraCast API.') }}
                                    </div>
                                    <div class="form-text">
                                        {{ $gettext('Top of hour runs at xx:00. Bottom of hour runs at xx:30. Select one or both options.') }}
                                    </div>
                                </div>

                                <form-group-field
                                    id="edit_ai_news_source_urls"
                                    :field="r$.ai_news_source_urls"
                                    class="mb-4"
                                >
                                    <template #label>
                                        {{ $gettext('RSS/Atom Feed Sources') }}
                                    </template>
                                    <template #default="{id, model}">
                                        <textarea
                                            :id="id"
                                            v-model="model.$model"
                                            class="form-control"
                                            rows="5"
                                        />
                                    </template>
                                    <template #description>
                                        {{ $gettext('One source URL per line. Each must be a valid RSS or Atom feed; plain website URLs are not scraped.') }}
                                    </template>
                                </form-group-field>

                                <ul
                                    v-if="fixedSources.length > 0"
                                    class="list-group mb-3"
                                >
                                    <li
                                        v-for="source in fixedSources"
                                        :key="source.key"
                                        class="list-group-item"
                                        :class="{'border-primary': source.active}"
                                    >
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="fw-semibold">{{ source.label }}</span>
                                            <span
                                                class="badge"
                                                :class="sourceStatusBadgeClass(source.status)"
                                            >{{ sourceStatusLabel(source.status) }}</span>
                                        </div>
                                        <div class="text-muted small text-break">
                                            {{ source.url }}
                                        </div>
                                        <div class="text-muted small">
                                            {{ source.message }}
                                        </div>
                                        <div
                                            v-if="source.headlineCount > 0"
                                            class="text-muted small"
                                        >
                                            {{ $gettext('Headlines fetched: %{count}', {count: source.headlineCount}) }}
                                        </div>
                                    </li>
                                </ul>

                                <div class="d-flex gap-2">
                                    <button
                                        type="submit"
                                        class="btn btn-primary"
                                    >
                                        {{ $gettext('Save Settings') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        :disabled="isLoading"
                                        @click="relist"
                                    >
                                        {{ $gettext('Reset') }}
                                    </button>
                                </div>

                                <div
                                    v-if="saveStatusText"
                                    class="text-success small mt-2"
                                >
                                    {{ saveStatusText }}
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </loading>
        </section>
    </form>
</template>

<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref} from "vue";
import {useGettext} from "vue3-gettext";
import {DateTimeMaybeValid} from "luxon";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import FormSelect from "~/components/Form/FormSelect.vue";
import Loading from "~/components/Common/Loading.vue";
import mergeExisting from "~/functions/mergeExisting";
import normalizeStationScheduleDays from "~/functions/normalizeStationScheduleDays";
import {useResettableRef} from "~/functions/useResettableRef.ts";
import {useAxios} from "~/vendor/axios";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useMayNeedRestart} from "~/functions/useMayNeedRestart";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useAppRegle} from "~/vendor/regle.ts";
import {ApiStatus} from "~/entities/ApiInterfaces.ts";
import {useLuxon} from "~/vendor/luxon.ts";

interface AiNewsForm {
    ai_news_enabled: boolean;
    ai_news_intro: string | null;
    ai_news_reporter_name: string | null;
    ai_news_source_urls: string | null;
    ai_news_story_count: number;
    ai_news_active_hours: string | null;
    ai_news_active_days: number[];
    ai_news_top_of_hour: boolean;
    ai_news_bottom_of_hour: boolean;
    ai_news_voice_model_path: string | null;
    ai_news_outro: string | null;
}

interface AiNewsStatusPayload {
    ai_news_last_generation_status?: string | null;
    ai_news_last_generation_time?: string | null;
    ai_news_last_error?: string | null;
}

interface AiNewsHeadlinePreviewItem {
    title: string;
    description: string;
    source_url?: string;
    source_type?: string;
}

interface AiNewsSourceResult {
    url: string;
    status: string;
    message: string;
    headline_count: number;
    source_type?: string;
}

interface AiNewsVoiceOption {
    label: string;
    path: string;
}

interface AiNewsDashboardPayload {
    latest_bulletin?: {
        generated_at?: string | null;
        story_count?: number | null;
        source_urls?: string[];
        source_results?: AiNewsSourceResult[];
        elapsed_seconds?: number | null;
        output_filename?: string | null;
        headline_preview?: AiNewsHeadlinePreviewItem[];
    };
    file_info?: {
        exists: boolean;
        size?: number;
        modified_at?: string | null;
    } | null;
    next_bulletin_time?: string | null;
    current_time_station?: string | null;
    tts_engine?: string | null;
    audio_available?: boolean;
    bulletin_url?: string | null;
}

interface AiNewsTestResponse extends ApiStatus, AiNewsStatusPayload {
    dashboard?: AiNewsDashboardPayload;
}

interface AiNewsResponse extends AiNewsForm, AiNewsStatusPayload {
    dashboard?: AiNewsDashboardPayload;
    voice_options?: AiNewsVoiceOption[];
}

interface LogEntry {
    id: number;
    time: string;
    message: string;
    tone: string;
}

const {getStationApiUrl} = useApiRouter();
const apiUrl = getStationApiUrl('/ai-news');
const testUrl = getStationApiUrl('/ai-news/test');

const isLoading = ref(true);
const isTesting = ref(false);
const saveStatusText = ref('');
const logCounter = ref(0);
const logEntries = ref<LogEntry[]>([]);

const lastStatus = ref<string | null>(null);
const lastTime = ref<string | null>(null);
const lastError = ref<string | null>(null);
const dashboard = ref<AiNewsDashboardPayload | null>(null);
const voiceOptions = ref<AiNewsVoiceOption[]>([]);
const browserNow = ref<DateTimeMaybeValid | null>(null);

const {record: form, reset: resetForm} = useResettableRef<AiNewsForm>(() => ({
    ai_news_enabled: false,
    ai_news_intro: null,
    ai_news_reporter_name: null,
    ai_news_source_urls: null,
    ai_news_story_count: 10,
    ai_news_active_hours: null,
    ai_news_active_days: [],
    ai_news_top_of_hour: true,
    ai_news_bottom_of_hour: false,
    ai_news_voice_model_path: null,
    ai_news_outro: null
}));

const {r$} = useAppRegle(form, {}, {});
const activeHoursStartInput = ref('');
const activeHoursEndInput = ref('');
const activeHoursStartError = computed(() => {
    const start = activeHoursStartInput.value.trim();
    const end = activeHoursEndInput.value.trim();

    if (!start && !end) {
        return '';
    }

    if (!start) {
        return $gettext('Enter a start time or leave both fields blank.');
    }

    if (!parseMeridiemTimeInput(start)) {
        return $gettext('Use format 01:00 AM.');
    }

    return '';
});
const activeHoursEndError = computed(() => {
    const start = activeHoursStartInput.value.trim();
    const end = activeHoursEndInput.value.trim();

    if (!start && !end) {
        return '';
    }

    if (!end) {
        return $gettext('Enter an end time or leave both fields blank.');
    }

    if (!parseMeridiemTimeInput(end)) {
        return $gettext('Use format 01:00 AM.');
    }

    return '';
});

const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {mayNeedRestart} = useMayNeedRestart();
const {$gettext} = useGettext();
const {DateTime, Duration} = useLuxon();

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
const displayDateTimeFormat = {
    month: '2-digit',
    day: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true
} as const;
const displayTimeFormat = {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true
} as const;

const formatBrowserDateTime = (value: string | null | undefined, fallback = '—') => {
    if (!value) {
        return fallback;
    }

    const parsed = DateTime.fromISO(value, {setZone: true});
    if (!parsed.isValid) {
        return fallback;
    }

    return parsed.setZone(browserTimezone).toLocaleString(displayDateTimeFormat);
};

const formatBrowserNow = (value: DateTimeMaybeValid | null, fallback = '—') => {
    if (!value || !value.isValid) {
        return fallback;
    }

    return value.toLocaleString(displayDateTimeFormat);
};

const formatStoredClockTime = (value: string, fallback = '—') => {
    if (!value) {
        return fallback;
    }

    const parsed = DateTime.fromFormat(value, 'HH:mm');
    if (!parsed.isValid) {
        return fallback;
    }

    return parsed.toLocaleString(displayTimeFormat);
};

const parseMeridiemTimeInput = (value: string) => {
    const trimmedValue = value.trim();
    if (!trimmedValue) {
        return null;
    }

    const parsed = DateTime.fromFormat(trimmedValue.toUpperCase(), 'hh:mm a');
    if (!parsed.isValid) {
        return null;
    }

    return parsed;
};

const formatStoredTimeForInput = (value: string) => {
    if (!value) {
        return '';
    }

    const parsed = DateTime.fromFormat(value, 'HH:mm');
    if (!parsed.isValid) {
        return value;
    }

    return parsed.toFormat('hh:mm a');
};

const normalizeMeridiemTimeInput = (value: string) => {
    const parsed = parseMeridiemTimeInput(value);
    return parsed ? parsed.toFormat('HH:mm') : null;
};

const formatActiveHoursRange = (value: string | null | undefined, fallback = '—') => {
    const trimmedValue = value?.trim() ?? '';
    if (!trimmedValue) {
        return fallback;
    }

    const [start = '', end = ''] = trimmedValue.split('-');
    if (!start || !end) {
        return trimmedValue;
    }

    return `${formatStoredClockTime(start, start)} - ${formatStoredClockTime(end, end)}`;
};

const formatRelativeDuration = (targetIso: string | null | undefined) => {
    if (!targetIso || !browserNow.value?.isValid) {
        return '—';
    }

    const target = DateTime.fromISO(targetIso, {setZone: true}).setZone(browserTimezone);
    if (!target.isValid) {
        return '—';
    }

    const diffMillis = target.toMillis() - browserNow.value.toMillis();
    if (diffMillis <= 0) {
        return $gettext('Due now');
    }

    const duration = Duration.fromMillis(diffMillis).shiftTo('hours', 'minutes', 'seconds').normalize();
    const hours = Math.floor(duration.hours);
    const minutes = Math.floor(duration.minutes);
    const seconds = Math.floor(duration.seconds);
    const parts: string[] = [];

    if (hours > 0) {
        parts.push(`${hours}h`);
    }

    if (minutes > 0 || hours > 0) {
        parts.push(`${minutes}m`);
    }

    parts.push(`${seconds}s`);

    return parts.join(' ');
};

const statusText = computed(() => lastStatus.value ?? '—');
const timeText = computed(() => lastTime.value ?? '—');
const latestBulletin = computed(() => dashboard.value?.latest_bulletin ?? null);
const audioAvailable = computed(() => dashboard.value?.audio_available ?? false);
const bulletinUrl = computed(() => dashboard.value?.bulletin_url ?? null);
const bulletinPlaybackUrl = computed(() => {
    if (!bulletinUrl.value) {
        return null;
    }

    const version = latestBulletin.value?.generated_at ?? dashboard.value?.file_info?.modified_at ?? null;
    if (!version) {
        return bulletinUrl.value;
    }

    const separator = bulletinUrl.value.includes('?') ? '&' : '?';
    return `${bulletinUrl.value}${separator}v=${encodeURIComponent(version)}`;
});
const dashboardCurrentTime = computed(() => dashboard.value?.current_time_station ?? null);
const dashboardNextBulletinTime = computed(() => dashboard.value?.next_bulletin_time ?? null);
const dashboardTtsEngine = computed(() => dashboard.value?.tts_engine ?? null);
const voiceSelectOptions = computed(() => {
    const options = voiceOptions.value.map((voice) => ({
        text: voice.label,
        value: voice.path,
    }));

    if (!options.some((option) => option.value === form.value.ai_news_voice_model_path) && form.value.ai_news_voice_model_path) {
        options.push({
            text: $gettext('Custom Voice Path'),
            value: form.value.ai_news_voice_model_path,
        });
    }

    return options;
});
const sourceCatalog = [
    {
        label: $gettext('Worthy News'),
        url: 'https://worthynews.com/',
        badgeClass: 'text-bg-primary'
    },
    {
        label: $gettext('Rapture Ready'),
        url: 'https://www.raptureready.com/',
        badgeClass: 'text-bg-warning'
    }
] as const;
const dayOptions = [
    {value: 1, text: $gettext('Monday')},
    {value: 2, text: $gettext('Tuesday')},
    {value: 3, text: $gettext('Wednesday')},
    {value: 4, text: $gettext('Thursday')},
    {value: 5, text: $gettext('Friday')},
    {value: 6, text: $gettext('Saturday')},
    {value: 7, text: $gettext('Sunday')}
];

const activeHoursParts = computed(() => {
    const value = form.value.ai_news_active_hours?.trim() ?? '';
    const [start = '', end = ''] = value.split('-');

    return {
        start,
        end
    };
});

const activeHoursStart = computed(() => activeHoursParts.value.start);
const activeHoursEnd = computed(() => activeHoursParts.value.end);

const hasBroadcastSlotSelected = computed(() => form.value.ai_news_top_of_hour || form.value.ai_news_bottom_of_hour);
const broadcastSlotLabels = computed(() => {
    const labels: string[] = [];

    if (form.value.ai_news_top_of_hour) {
        labels.push($gettext('Top of hour'));
    }

    if (form.value.ai_news_bottom_of_hour) {
        labels.push($gettext('Bottom of hour'));
    }

    return labels;
});
const activeDayLabels = computed(() => {
    const days = normalizeStationScheduleDays(form.value.ai_news_active_days);

    if (days.length === 0) {
        return $gettext('Every day');
    }

    return dayOptions
        .filter((option) => days.includes(option.value))
        .map((option) => option.text)
        .join(', ');
});

const enabledDescription = computed(() => {
    return form.value.ai_news_enabled
        ? $gettext('The generator is allowed to run during the configured window.')
        : $gettext('Generation is disabled until you re-enable the bulletin.');
});

const statusTone = computed(() => {
    const value = lastStatus.value?.toLowerCase();

    if (value === 'completed') {
        return 'text-success';
    }

    if (value === 'error') {
        return 'text-danger';
    }

    if (form.value.ai_news_enabled) {
        return 'text-warning';
    }

    return 'text-muted';
});

const scheduleText = computed(() => {
    if (!form.value.ai_news_enabled) {
        return $gettext('OFF');
    }

    const activeWindow = form.value.ai_news_active_hours?.trim()
        ? formatActiveHoursRange(form.value.ai_news_active_hours, form.value.ai_news_active_hours)
        : $gettext('All Day');
    const slotSummary = broadcastSlotLabels.value.join(', ') || $gettext('No slots selected');

    return `${activeWindow}\n${activeDayLabels.value}\n${slotSummary}`;
});

const nextBulletinText = computed(() => {
    if (!form.value.ai_news_enabled) {
        return '—';
    }

    if (dashboardNextBulletinTime.value) {
        const datetime = formatBrowserDateTime(dashboardNextBulletinTime.value);
        const remaining = formatRelativeDuration(dashboardNextBulletinTime.value);
        return remaining && remaining !== '—'
            ? `${datetime}\n${remaining}`
            : datetime;
    }

    return form.value.ai_news_active_hours?.trim() ?? $gettext('Within active window');
});

const currentTimeText = computed(() => {
    return formatBrowserNow(browserNow.value);
});

const ttsEngineText = computed(() => {
    return dashboardTtsEngine.value ?? form.value.ai_news_voice_model_path?.trim() ?? $gettext('Default voice model');
});

const latestBulletinText = computed(() => {
    if (audioAvailable.value && bulletinUrl.value) {
        return $gettext('Latest bulletin audio is ready. Use the generated bulletin endpoint to play or download it.');
    }

    if (latestBulletin.value?.generated_at) {
        return $gettext('The latest successful bulletin was generated at: ') + formatBrowserDateTime(latestBulletin.value.generated_at);
    }

    if (lastStatus.value === 'error' && lastError.value) {
        return $gettext('Latest generation failed: ') + lastError.value;
    }

    return $gettext('No bulletin audio has been generated yet.');
});

const fixedSources = computed(() => {
    const configuredUrls = (form.value.ai_news_source_urls ?? '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);
    const sourceResults = latestBulletin.value?.source_results ?? [];
    const resultMap = new Map(sourceResults.map((result) => [result.url, result]));
    const activeUrls = latestBulletin.value?.source_urls ?? configuredUrls;
    const knownSources = sourceCatalog.map((source) => {
        const result = resultMap.get(source.url);

        return {
            key: source.url,
            label: source.label,
            url: source.url,
            active: activeUrls.includes(source.url),
            status: result?.status ?? 'idle',
            message: result?.message ?? $gettext('No fetch attempt recorded yet.'),
            headlineCount: result?.headline_count ?? 0,
        };
    });
    const customSources = configuredUrls
        .filter((url) => !sourceCatalog.some((source) => source.url === url))
        .map((url, index) => {
            const result = resultMap.get(url);

            return {
                key: `custom-${index}-${url}`,
                label: $gettext('Custom Source'),
                url,
                active: activeUrls.includes(url),
                status: result?.status ?? 'idle',
                message: result?.message ?? $gettext('No fetch attempt recorded yet.'),
                headlineCount: result?.headline_count ?? 0,
            };
        });

    return [...knownSources, ...customSources];
});

const metaStoriesText = computed(() => {
    const storyCount = latestBulletin.value?.story_count;

    return (typeof storyCount === 'number')
        ? $gettext('Stories: ') + storyCount
        : $gettext('Stories: not available yet');
});
const metaSourcesText = computed(() => {
    const activeSources = latestBulletin.value?.source_urls ?? [];
    const sourceLabels = activeSources.map((url) => {
        return sourceCatalog.find((source) => source.url === url)?.label ?? url;
    });

    return sourceLabels.length > 0
        ? $gettext('Sources: ') + sourceLabels.join(', ')
        : $gettext('Sources: none configured');
});
const metaTimeText = computed(() => {
    const elapsedSeconds = latestBulletin.value?.elapsed_seconds;

    return (typeof elapsedSeconds === 'number')
        ? $gettext('Generated in ') + elapsedSeconds + 's'
        : $gettext('Generation timing unavailable');
});

const headlinePreviewItems = computed(() => {
    const previewItems = latestBulletin.value?.headline_preview ?? [];

    if (previewItems.length === 0) {
        return [
            {
                id: 'empty',
                source: $gettext('Info'),
                title: $gettext('No headline preview available yet.'),
                summary: $gettext('Run a test bulletin to fetch stories and populate the live preview panel.'),
                tone: 'text-bg-secondary'
            }
        ];
    }

    return previewItems.map((item, index) => {
        const source = sourceCatalog.find((sourceItem) => sourceItem.url === item.source_url) ?? null;

        return {
            id: `${index}-${item.title}`,
            source: source?.label ?? (item.source_type === 'website' ? $gettext('Website') : $gettext('Feed')),
            title: item.title,
            summary: item.description || $gettext('No summary available for this story.'),
            tone: source?.badgeClass ?? 'text-bg-secondary'
        };
    });
});

const statusCards = computed(() => {
    return [
        {
            label: $gettext('Bulletin Schedule'),
            value: scheduleText.value,
            helper: '',
            tone: form.value.ai_news_enabled ? 'text-success' : 'text-danger'
        },
        {
            label: $gettext('Next Bulletin'),
            value: nextBulletinText.value,
            helper: dashboardNextBulletinTime.value ? browserTimezone : '',
            tone: 'text-warning'
        },
        {
            label: $gettext('Last Generated'),
            value: latestBulletin.value?.generated_at ? formatBrowserDateTime(latestBulletin.value.generated_at) : (timeText.value === '—' ? $gettext('Never') : formatBrowserDateTime(timeText.value, timeText.value)),
            helper: latestBulletin.value?.generated_at ? browserTimezone : '',
            tone: 'text-info'
        },
        {
            label: $gettext('Current Time'),
            value: currentTimeText.value,
            helper: browserTimezone,
            tone: ''
        },
        {
            label: $gettext('Stream Output'),
            value: audioAvailable.value ? $gettext('Latest bulletin ready') : statusText.value,
            helper: '',
            tone: audioAvailable.value ? 'text-success' : statusTone.value
        },
        {
            label: $gettext('TTS Engine'),
            value: ttsEngineText.value,
            helper: '',
            tone: 'text-info'
        }
    ];
});

const isGenerateDisabled = computed(() => isTesting.value || !form.value.ai_news_enabled);
const generateButtonText = computed(() => {
    if (isTesting.value) {
        return $gettext('Generating...');
    }

    if (!form.value.ai_news_enabled) {
        return $gettext('Generation Disabled');
    }

    return $gettext('Generate Now');
});
const generateHelpText = computed(() => {
    return form.value.ai_news_enabled
        ? ''
        : $gettext('Re-enable the bulletin before running a manual generation test.');
});

const sourceTypeLabel = (sourceType?: string) => {
    switch (sourceType) {
        case 'website':
            return $gettext('Website');
        case 'feed':
            return $gettext('Feed');
        default:
            return $gettext('Source');
    }
};

const sourceStatusLabel = (status: string) => {
    switch (status) {
        case 'ok':
            return $gettext('Fetched');
        case 'empty':
            return $gettext('Empty');
        case 'skipped':
            return $gettext('Skipped');
        default:
            return $gettext('Standby');
    }
};

const sourceStatusBadgeClass = (status: string) => {
    switch (status) {
        case 'ok':
            return 'text-bg-success';
        case 'empty':
            return 'text-bg-warning';
        case 'skipped':
            return 'text-bg-danger';
        default:
            return 'text-bg-secondary';
    }
};

const appendLog = (message: string, tone = 'text-info') => {
    const timestamp = browserNow.value?.isValid
        ? browserNow.value.toLocaleString(displayTimeFormat)
        : DateTime.now().setZone(browserTimezone).toLocaleString(displayTimeFormat);

    logCounter.value += 1;
    logEntries.value = [
        ...logEntries.value,
        {
            id: logCounter.value,
            time: timestamp,
            message,
            tone
        }
    ];
};

const appendSourceResultsToLog = (sourceResults: AiNewsSourceResult[] = []) => {
    sourceResults.forEach((result) => {
        const label = sourceStatusLabel(result.status);
        const headlineSuffix = result.headline_count > 0
            ? $gettext(' Headlines fetched: ') + result.headline_count
            : '';
        const tone = result.status === 'ok'
            ? 'text-success'
            : (result.status === 'skipped' ? 'text-danger' : 'text-info');

        appendLog(`[${label}] ${sourceTypeLabel(result.source_type)} ${result.url} - ${result.message}${headlineSuffix}`, tone);
    });
};

const setInitialLogs = () => {
    logEntries.value = [];
    appendLog($gettext('Ready. Click "Generate Now" to produce a bulletin with the current station settings.'), 'text-info');
};

const hydrateFromResponse = (data: AiNewsResponse) => {
    resetForm();
    r$.$reset();
    form.value = mergeExisting(form.value, data);
    form.value.ai_news_active_days = normalizeStationScheduleDays(form.value.ai_news_active_days);
    activeHoursStartInput.value = formatStoredTimeForInput(activeHoursStart.value);
    activeHoursEndInput.value = formatStoredTimeForInput(activeHoursEnd.value);

    lastStatus.value = data.ai_news_last_generation_status ?? null;
    lastTime.value = data.ai_news_last_generation_time ?? null;
    lastError.value = data.ai_news_last_error ?? null;
    dashboard.value = data.dashboard ?? null;
    voiceOptions.value = data.voice_options ?? [];

    setInitialLogs();

    if (latestBulletin.value?.generated_at) {
        appendLog($gettext('Latest bulletin completed successfully at ') + formatBrowserDateTime(latestBulletin.value.generated_at), 'text-success');
    } else if (lastStatus.value === 'error' && lastError.value) {
        appendLog($gettext('Latest bulletin failed: ') + lastError.value, 'text-danger');
    }

    appendSourceResultsToLog(data.dashboard?.latest_bulletin?.source_results ?? []);
};

const relist = async () => {
    isLoading.value = true;

    try {
        const {data} = await axios.get<AiNewsResponse>(apiUrl.value);
        hydrateFromResponse(data);
        saveStatusText.value = '';
    } finally {
        isLoading.value = false;
    }
};

const BROWSER_CLOCK_TICK_MS = 1_000;
const timeTicker = window.setInterval(() => {
    browserNow.value = DateTime.now().setZone(browserTimezone);
}, BROWSER_CLOCK_TICK_MS);

onUnmounted(() => {
    window.clearInterval(timeTicker);
});

onMounted(() => {
    browserNow.value = DateTime.now().setZone(browserTimezone);
    void relist();
});

const saveChanges = async () => {
    const {valid} = await r$.$validate();
    if (!valid) {
        return;
    }

    if (activeHoursStartError.value || activeHoursEndError.value) {
        notifyError($gettext('Fix the broadcast window time fields before saving.'));
        return;
    }

    updateActiveHours(activeHoursStartInput.value, activeHoursEndInput.value);

    if (!hasBroadcastSlotSelected.value) {
        notifyError($gettext('Select at least one broadcast slot.'));
        appendLog($gettext('Settings not saved because no broadcast slot was selected.'), 'text-danger');
        return;
    }

    form.value.ai_news_active_days = normalizeStationScheduleDays(form.value.ai_news_active_days);

    const {data} = await axios.put<ApiStatus>(apiUrl.value, form.value);

    notifySuccess(data.message);
    appendLog($gettext('Settings saved successfully.'), 'text-success');
    saveStatusText.value = $gettext('All settings saved');
    mayNeedRestart();
    await relist();
};

const runTest = async () => {
    if (!form.value.ai_news_enabled) {
        notifyError($gettext('Enable AI News before running a manual bulletin generation.'));
        appendLog($gettext('Manual generation blocked while the bulletin is disabled.'), 'text-danger');
        return;
    }

    isTesting.value = true;
    appendLog($gettext('Fetching headlines from configured RSS/Atom feed sources...'), 'text-info');

    try {
        const {data} = await axios.post<AiNewsTestResponse>(testUrl.value);
        notifySuccess(data.message);
        lastStatus.value = data.ai_news_last_generation_status ?? lastStatus.value;
        lastTime.value = data.ai_news_last_generation_time ?? lastTime.value;
        lastError.value = data.ai_news_last_error ?? null;
        dashboard.value = data.dashboard ?? dashboard.value;
        appendLog($gettext('Bulletin generated successfully.'), 'text-success');
        await relist();
    } catch (error: any) {
        const apiMessage = error?.response?.data?.message;
        notifyError(apiMessage);
        lastStatus.value = 'error';
        if (apiMessage) {
            lastError.value = apiMessage;
        }
        appendLog($gettext('Generation failed. Review the latest error status for details.'), 'text-danger');
        await relist();
    } finally {
        isTesting.value = false;
    }
};

const updateActiveHours = (start: string, end: string) => {
    const normalizedStart = normalizeMeridiemTimeInput(start);
    const normalizedEnd = normalizeMeridiemTimeInput(end);

    if (!start.trim() && !end.trim()) {
        form.value.ai_news_active_hours = null;
        return;
    }

    if (!normalizedStart || !normalizedEnd) {
        return;
    }

    form.value.ai_news_active_hours = `${normalizedStart}-${normalizedEnd}`;
};

const refreshHeadlinePreview = () => {
    appendLog($gettext('Headline preview refreshed from the latest backend dashboard payload.'), 'text-info');
};
</script>

<style scoped>
.log-panel {
    min-height: 4.5rem;
    max-height: 12rem;
    overflow-y: auto;
}

.headline-list {
    max-height: 26rem;
    overflow-y: auto;
}

.ai-news-summary-box {
    padding: 1rem;
    border-radius: var(--bs-border-radius);
    background-color: var(--bs-tertiary-bg);
    border: 1px solid var(--bs-border-color);
}

.ai-news-status-callout {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.65rem 0.85rem;
    border-radius: var(--bs-border-radius);
    border: 1px solid transparent;
}

.ai-news-status-success {
    background-color: rgba(var(--bs-success-rgb), 0.12);
    border-color: rgba(var(--bs-success-rgb), 0.35);
    color: var(--bs-success);
}

.ai-news-status-danger {
    background-color: rgba(var(--bs-danger-rgb), 0.12);
    border-color: rgba(var(--bs-danger-rgb), 0.35);
    color: var(--bs-danger);
}

.ai-news-player {
    padding: 0.85rem 1rem;
    border-radius: var(--bs-border-radius);
    background-color: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
}

.ai-news-disabled-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.25rem 0.7rem;
    border-radius: 50rem;
    background-color: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(220, 53, 69, 0.55);
    color: #fff;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.04em;
}

.ai-news-disabled-dot {
    display: inline-block;
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    background-color: var(--bs-danger);
}

.ai-news-live-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.25rem 0.7rem;
    border-radius: 50rem;
    background-color: rgba(255, 255, 255, 0.18);
    color: #fff;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.04em;
}

.ai-news-live-dot {
    position: relative;
    display: inline-block;
    width: 0.55rem;
    height: 0.55rem;
}

.ai-news-live-dot::before,
.ai-news-live-dot::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background-color: #fff;
}

.ai-news-live-dot::before {
    z-index: 1;
    animation: ai-news-live-glow 1.3s ease-in-out infinite;
}

.ai-news-live-dot::after {
    z-index: 0;
    animation: ai-news-live-ping 1.3s cubic-bezier(0, 0, 0.2, 1) infinite;
}

@media (prefers-reduced-motion: reduce) {
    .ai-news-live-dot::before,
    .ai-news-live-dot::after {
        animation: none;
    }
}

@keyframes ai-news-live-glow {
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 0 0.35rem 0.05rem rgba(255, 255, 255, 0.9);
    }
    50% {
        transform: scale(1.25);
        box-shadow: 0 0 0.6rem 0.15rem rgba(255, 255, 255, 1);
    }
}

@keyframes ai-news-live-ping {
    0% {
        transform: scale(1);
        opacity: 0.7;
    }
    75%, 100% {
        transform: scale(2.6);
        opacity: 0;
    }
}
</style>
