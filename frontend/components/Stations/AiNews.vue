<template>
    <form
        class="ai-news-page"
        @submit.prevent="saveChanges"
    >
        <section
            class="ai-news-shell"
            role="region"
            aria-labelledby="hdr_ai_news"
        >
            <header class="ai-news-topbar">
                <div class="ai-news-branding">
                    <span class="logo-dot" />
                    <div>
                        <h2
                            id="hdr_ai_news"
                            class="ai-news-title"
                        >
                            {{ $gettext('Radio Newscaster') }}
                        </h2>
                        <p class="ai-news-subtitle mb-0">
                            {{ $gettext('AI News Bulletin Admin Panel') }}
                        </p>
                    </div>
                </div>

                <div
                    class="live-badge"
                    :class="liveBadgeClass"
                >
                    <span class="live-dot" />
                    {{ liveBadgeText }}
                </div>
            </header>

            <loading :loading="isLoading" lazy>
                <div class="ai-news-container">
                    <div class="reference-note">
                        <p class="mb-2">
                            {{ $gettext('This page mirrors the client dashboard while staying connected to the current AzuraCast AI News APIs.') }}
                        </p>
                        <p class="mb-0">
                            {{ $gettext('Active hours format: HH:MM-HH:MM. Leave blank to run all day. Source URLs should be one per line.') }}
                        </p>
                    </div>

                    <section class="dashboard-card status-strip">
                        <div class="dashboard-card-title">
                            {{ $gettext('System Status') }}
                        </div>

                        <div class="status-grid">
                            <div
                                v-for="item in statusCards"
                                :key="item.label"
                                class="stat-card"
                            >
                                <div class="stat-label">
                                    {{ item.label }}
                                </div>
                                <div
                                    class="stat-value"
                                    :class="item.tone"
                                >
                                    {{ item.value }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="content-grid">
                        <div class="left-column">
                            <section class="dashboard-card">
                                <div class="dashboard-card-title">
                                    {{ $gettext('Generate Bulletin') }}
                                </div>

                                <div class="generate-area">
                                    <button
                                        type="button"
                                        class="big-btn"
                                        :disabled="isTesting"
                                        @click="runTest"
                                    >
                                        <span
                                            v-if="isTesting"
                                            class="spinner"
                                        />
                                        <span v-else>{{ generateButtonIcon }}</span>
                                        <span>{{ generateButtonText }}</span>
                                    </button>
                                </div>

                                <div class="audio-section show">
                                    <div class="player-box">
                                        <div class="player-title">
                                            {{ $gettext('Latest Bulletin') }}
                                        </div>
                                        <div class="audio-placeholder">
                                            {{ latestBulletinText }}
                                        </div>
                                        <div
                                            v-if="audioAvailable && bulletinUrl"
                                            class="audio-link-row"
                                        >
                                            <a
                                                :href="bulletinUrl"
                                                class="btn btn-secondary btn-sm"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                {{ $gettext('Open Latest Bulletin Audio') }}
                                            </a>
                                        </div>
                                        <div class="meta-row">
                                            <div class="meta-item">
                                                {{ metaStoriesText }}
                                            </div>
                                            <div class="meta-item">
                                                {{ metaSourcesText }}
                                            </div>
                                            <div class="meta-item">
                                                {{ metaTimeText }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="log-section">
                                    <div class="dashboard-card-title">
                                        {{ $gettext('Generation Log') }}
                                    </div>
                                    <div class="log-box">
                                        <div
                                            v-for="entry in logEntries"
                                            :key="entry.id"
                                            class="log-line"
                                            :class="entry.type"
                                        >
                                            [{{ entry.time }}] {{ entry.message }}
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="dashboard-card">
                                <div class="headlines-title-row">
                                    <div class="dashboard-card-title mb-0">
                                        {{ $gettext('Live Headlines Preview') }}
                                    </div>
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm"
                                        @click="refreshHeadlinePreview"
                                    >
                                        {{ $gettext('Refresh') }}
                                    </button>
                                </div>

                                <ul class="headline-list">
                                    <li
                                        v-for="headline in headlinePreviewItems"
                                        :key="headline.id"
                                        class="headline-item"
                                    >
                                        <span
                                            class="src-tag"
                                            :class="headline.tone"
                                        >
                                            {{ headline.source }}
                                        </span>
                                        <div>
                                            <div class="hl-title">
                                                {{ headline.title }}
                                            </div>
                                            <div class="hl-summary">
                                                {{ headline.summary }}
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                            </section>
                        </div>

                        <section class="dashboard-card settings-card settings-card-plain">
                            <div class="dashboard-card-title">
                                {{ $gettext('Settings') }}
                            </div>

                            <div class="toggle-row">
                                <div>
                                    <div class="toggle-label">
                                        {{ $gettext('Bulletin Enabled') }}
                                    </div>
                                    <div class="toggle-helper">
                                        {{ enabledDescription }}
                                    </div>
                                </div>

                                <label class="toggle">
                                    <input
                                        v-model="form.ai_news_enabled"
                                        type="checkbox"
                                    >
                                    <span class="slider" />
                                </label>
                            </div>

                            <div class="settings-group settings-group-tight">
                                <label class="toggle-label-row" for="edit_ai_news_station_name">{{ $gettext('Station Name') }}</label>
                                <input
                                    id="edit_ai_news_station_name"
                                    :value="stationDisplayName"
                                    class="form-control form-control-dark"
                                    type="text"
                                    disabled
                                >
                                <div class="field-note">
                                    {{ $gettext('Shown for visual parity with the reference dashboard.') }}
                                </div>
                            </div>

                            <div class="settings-group settings-group-tight">
                                <label class="toggle-label-row" for="edit_ai_news_reporter_name">{{ $gettext('AI Reporter Name') }}</label>
                                <input
                                    id="edit_ai_news_reporter_name"
                                    :value="reporterDisplayName"
                                    class="form-control form-control-dark"
                                    type="text"
                                    disabled
                                >
                                <div class="field-note">
                                    {{ $gettext('The current API does not store a separate reporter name, so this remains a visual reference.') }}
                                </div>
                            </div>

                            <form-group-field
                                id="edit_ai_news_intro"
                                :field="r$.ai_news_intro"
                            >
                                <template #label>
                                    {{ $gettext('Intro Script') }}
                                    <span class="label-helper">{{ $gettext('(read at start of every bulletin)') }}</span>
                                </template>
                                <template #default="{id, model}">
                                    <textarea
                                        :id="id"
                                        v-model="model.$model"
                                        class="form-control form-control-dark"
                                        rows="4"
                                    />
                                </template>
                            </form-group-field>

                            <div class="settings-group settings-group-tight">
                                <label for="edit_ai_news_outro_visual_only">
                                    {{ $gettext('Outro Script') }}
                                    <span class="label-helper">{{ $gettext('(read at end of every bulletin)') }}</span>
                                </label>
                                <textarea
                                    id="edit_ai_news_outro_visual_only"
                                    class="form-control form-control-dark"
                                    rows="3"
                                    :value="outroPlaceholder"
                                    disabled
                                />
                                <div class="field-note">
                                    {{ $gettext('Shown for dashboard parity only. The current backend does not persist an outro script.') }}
                                </div>
                            </div>

                            <form-group-field
                                id="edit_ai_news_voice_model_path"
                                :field="r$.ai_news_voice_model_path"
                            >
                                <template #label>
                                    {{ $gettext('AI Voice') }}
                                </template>
                                <template #default="{id, model}">
                                    <input
                                        :id="id"
                                        v-model="model.$model"
                                        class="form-control form-control-dark"
                                        type="text"
                                        :placeholder="$gettext('am_michael')"
                                    >
                                </template>
                            </form-group-field>

                            <div class="settings-group settings-group-tight">
                                <label>{{ $gettext('Broadcast Window') }}</label>
                                <div class="time-row">
                                    <input
                                        :value="activeHoursStart"
                                        class="form-control form-control-dark"
                                        type="time"
                                        @input="updateActiveHoursStart"
                                    >
                                    <input
                                        :value="activeHoursEnd"
                                        class="form-control form-control-dark"
                                        type="time"
                                        @input="updateActiveHoursEnd"
                                    >
                                </div>
                                <div class="field-note">
                                    {{ $gettext('Stored as a single HH:MM-HH:MM range in the current AzuraCast API.') }}
                                </div>
                            </div>

                            <form-group-field
                                id="edit_ai_news_source_urls"
                                :field="r$.ai_news_source_urls"
                            >
                                <template #label>
                                    {{ $gettext('News Sources') }}
                                </template>
                                <template #default="{id, model}">
                                    <textarea
                                        :id="id"
                                        v-model="model.$model"
                                        class="form-control form-control-dark"
                                        rows="5"
                                    />
                                </template>
                            </form-group-field>

                            <div class="source-checks">
                                <label
                                    v-for="chip in sourceChips"
                                    :key="chip.label"
                                    class="check-label"
                                    :class="{active: chip.active}"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="chip.active"
                                        disabled
                                    >
                                    {{ chip.label }}
                                </label>
                            </div>
                            <div class="field-note mb-4">
                                {{ $gettext('These chips are derived from the configured source URLs for visual parity with the reference dashboard.') }}
                            </div>

                            <div class="btn-row">
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

                            <div class="save-status">
                                {{ saveStatusText }}
                            </div>
                        </section>
                    </div>
                </div>
            </loading>
        </section>
    </form>
</template>

<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref} from "vue";
import {useGettext} from "vue3-gettext";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import Loading from "~/components/Common/Loading.vue";
import mergeExisting from "~/functions/mergeExisting";
import {useResettableRef} from "~/functions/useResettableRef.ts";
import {useAxios} from "~/vendor/axios";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useMayNeedRestart} from "~/functions/useMayNeedRestart";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useAppRegle} from "~/vendor/regle.ts";
import {ApiStatus} from "~/entities/ApiInterfaces.ts";

interface AiNewsForm {
    ai_news_enabled: boolean;
    ai_news_intro: string | null;
    ai_news_source_urls: string | null;
    ai_news_active_hours: string | null;
    ai_news_voice_model_path: string | null;
}

interface AiNewsStatusPayload {
    ai_news_last_generation_status?: string | null;
    ai_news_last_generation_time?: string | null;
    ai_news_last_error?: string | null;
}

interface AiNewsHeadlinePreviewItem {
    title: string;
    description: string;
}

interface AiNewsDashboardPayload {
    latest_bulletin?: {
        generated_at?: string | null;
        story_count?: number | null;
        source_urls?: string[];
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

interface AiNewsResponse extends AiNewsForm, AiNewsStatusPayload {
    dashboard?: AiNewsDashboardPayload;
}

interface LogEntry {
    id: number;
    time: string;
    message: string;
    type: string;
}

const {getStationApiUrl} = useApiRouter();
const apiUrl = getStationApiUrl('/ai-news');
const testUrl = getStationApiUrl('/ai-news/test');

const isLoading = ref(true);
const isTesting = ref(false);
const currentTime = ref(new Date().toLocaleTimeString());
const saveStatusText = ref('');
const logCounter = ref(0);
const logEntries = ref<LogEntry[]>([]);

const lastStatus = ref<string | null>(null);
const lastTime = ref<string | null>(null);
const lastError = ref<string | null>(null);
const dashboard = ref<AiNewsDashboardPayload | null>(null);

const {record: form, reset: resetForm} = useResettableRef<AiNewsForm>(() => ({
    ai_news_enabled: false,
    ai_news_intro: null,
    ai_news_source_urls: null,
    ai_news_active_hours: null,
    ai_news_voice_model_path: null
}));

const {r$} = useAppRegle(form, {}, {});

const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {mayNeedRestart} = useMayNeedRestart();
const {$gettext} = useGettext();

const statusText = computed(() => lastStatus.value ?? '—');
const timeText = computed(() => lastTime.value ?? '—');
const latestBulletin = computed(() => dashboard.value?.latest_bulletin ?? null);
const audioAvailable = computed(() => dashboard.value?.audio_available ?? false);
const bulletinUrl = computed(() => dashboard.value?.bulletin_url ?? null);
const dashboardCurrentTime = computed(() => dashboard.value?.current_time_station ?? null);
const dashboardNextBulletinTime = computed(() => dashboard.value?.next_bulletin_time ?? null);
const dashboardTtsEngine = computed(() => dashboard.value?.tts_engine ?? null);
const stationDisplayName = computed(() => $gettext('Current Station'));
const reporterDisplayName = computed(() => {
    return form.value.ai_news_voice_model_path || $gettext('AI News Voice');
});
const outroPlaceholder = computed(() => {
    return $gettext('Not available in the current AzuraCast AI News configuration.');
});

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

const liveBadgeClass = computed(() => {
    return form.value.ai_news_enabled ? 'is-live' : 'is-off';
});

const liveBadgeText = computed(() => {
    return form.value.ai_news_enabled
        ? $gettext('Streaming Live')
        : $gettext('Bulletin Disabled');
});

const enabledDescription = computed(() => {
    return form.value.ai_news_enabled
        ? $gettext('The generator is allowed to run during the configured window.')
        : $gettext('Generation is disabled until you re-enable the bulletin.')
});

const statusTone = computed(() => {
    const value = lastStatus.value?.toLowerCase();

    if (value === 'completed') {
        return 'tone-green';
    }

    if (value === 'error') {
        return 'tone-red';
    }

    if (form.value.ai_news_enabled) {
        return 'tone-yellow';
    }

    return 'tone-muted';
});

const scheduleText = computed(() => {
    if (!form.value.ai_news_enabled) {
        return $gettext('OFF');
    }

    return form.value.ai_news_active_hours?.trim() || $gettext('All Day');
});

const nextBulletinText = computed(() => {
    if (!form.value.ai_news_enabled) {
        return '—';
    }

    return dashboardNextBulletinTime.value ?? form.value.ai_news_active_hours?.trim() ?? $gettext('Within active window');
});

const currentTimeText = computed(() => {
    if (dashboardCurrentTime.value) {
        return dashboardCurrentTime.value;
    }

    return currentTime.value;
});

const ttsEngineText = computed(() => {
    return dashboardTtsEngine.value ?? form.value.ai_news_voice_model_path?.trim() ?? $gettext('Default voice model');
});

const latestBulletinText = computed(() => {
    if (audioAvailable.value && bulletinUrl.value) {
        return $gettext('Latest bulletin audio is ready. Use the generated bulletin endpoint to play or download it.');
    }

    if (latestBulletin.value?.generated_at) {
        return $gettext('The latest successful bulletin was generated at: ') + latestBulletin.value.generated_at;
    }

    if (lastStatus.value === 'error' && lastError.value) {
        return $gettext('Latest generation failed: ') + lastError.value;
    }

    return $gettext('No bulletin audio has been generated yet.');
});

const sourceLines = computed(() => {
    return (form.value.ai_news_source_urls ?? '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);
});

const sourceChips = computed(() => {
    const lines = sourceLines.value.map((line) => line.toLowerCase());

    return [
        {
            label: $gettext('Worthy News'),
            active: lines.some((line) => line.includes('worthy'))
        },
        {
            label: $gettext('Rapture Ready'),
            active: lines.some((line) => line.includes('rapture'))
        },
        {
            label: $gettext('BBC World (fallback)'),
            active: lines.some((line) => line.includes('bbc'))
        }
    ];
});

const metaStoriesText = computed(() => {
    const storyCount = latestBulletin.value?.story_count;

    return (typeof storyCount === 'number')
        ? $gettext('Stories: ') + storyCount
        : $gettext('Stories: not available yet');
});
const metaSourcesText = computed(() => {
    const activeSources = latestBulletin.value?.source_urls ?? [];

    return activeSources.length > 0
        ? $gettext('Sources: ') + activeSources.join(', ')
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
                tone: 'src-info'
            }
        ];
    }

    return previewItems.map((item, index) => {
        const haystack = `${item.title} ${item.description}`.toLowerCase();
        let source = $gettext('Feed');
        let tone = 'src-info';

        if (haystack.includes('worthy')) {
            source = $gettext('Worthy');
            tone = 'src-worthy';
        } else if (haystack.includes('rapture')) {
            source = $gettext('Rapture');
            tone = 'src-rapture';
        } else if (haystack.includes('bbc')) {
            source = $gettext('BBC');
            tone = 'src-bbc';
        }

        return {
            id: `${index}-${item.title}`,
            source,
            title: item.title,
            summary: item.description || $gettext('No summary available for this story.'),
            tone
        };
    });
});

const statusCards = computed(() => {
    return [
        {
            label: $gettext('Bulletin Schedule'),
            value: scheduleText.value,
            tone: form.value.ai_news_enabled ? 'tone-green' : 'tone-red'
        },
        {
            label: $gettext('Next Bulletin'),
            value: nextBulletinText.value,
            tone: 'tone-yellow'
        },
        {
            label: $gettext('Last Generated'),
            value: latestBulletin.value?.generated_at ?? (timeText.value === '—' ? $gettext('Never') : timeText.value),
            tone: 'tone-blue'
        },
        {
            label: $gettext('Current Time'),
            value: currentTimeText.value,
            tone: 'tone-default'
        },
        {
            label: $gettext('Stream Output'),
            value: audioAvailable.value ? $gettext('Latest bulletin ready') : statusText.value,
            tone: audioAvailable.value ? 'tone-green' : statusTone.value
        },
        {
            label: $gettext('TTS Engine'),
            value: ttsEngineText.value,
            tone: 'tone-blue'
        }
    ];
});

const generateButtonIcon = computed(() => '▶');
const generateButtonText = computed(() => {
    return isTesting.value
        ? $gettext('Generating...')
        : $gettext('Generate Now');
});

const appendLog = (message: string, type = 'log-info') => {
    const timestamp = new Date().toLocaleTimeString();

    logCounter.value += 1;
    logEntries.value = [
        ...logEntries.value,
        {
            id: logCounter.value,
            time: timestamp,
            message,
            type
        }
    ];
};

const setInitialLogs = () => {
    logEntries.value = [];
    appendLog($gettext('Ready. Click "Generate Now" to produce a bulletin with the current station settings.'), 'log-info');
};

const hydrateFromResponse = (data: AiNewsResponse) => {
    resetForm();
    r$.$reset();
    form.value = mergeExisting(form.value, data);

    lastStatus.value = data.ai_news_last_generation_status ?? null;
    lastTime.value = data.ai_news_last_generation_time ?? null;
    lastError.value = data.ai_news_last_error ?? null;
    dashboard.value = data.dashboard ?? null;

    setInitialLogs();

    if (latestBulletin.value?.generated_at) {
        appendLog($gettext('Latest bulletin completed successfully at ') + latestBulletin.value.generated_at, 'log-ok');
    } else if (lastStatus.value === 'error' && lastError.value) {
        appendLog($gettext('Latest bulletin failed: ') + lastError.value, 'log-err');
    }
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

const timeTicker = window.setInterval(() => {
    currentTime.value = new Date().toLocaleTimeString();
}, 1000);

onUnmounted(() => {
    window.clearInterval(timeTicker);
});

onMounted(relist);

const saveChanges = async () => {
    const {valid} = await r$.$validate();
    if (!valid) {
        return;
    }

    const {data} = await axios.put<ApiStatus>(apiUrl.value, form.value);

    notifySuccess(data.message);
    appendLog($gettext('Settings saved successfully.'), 'log-ok');
    saveStatusText.value = $gettext('All settings saved');
    mayNeedRestart();
    await relist();
};

const runTest = async () => {
    isTesting.value = true;
    appendLog($gettext('Fetching headlines from configured sources...'), 'log-info');

    try {
        const {data} = await axios.post<ApiStatus>(testUrl.value);
        notifySuccess(data.message);
        appendLog($gettext('Bulletin generated successfully.'), 'log-ok');
        await relist();
    } catch {
        notifyError();
        appendLog($gettext('Generation failed. Review the latest error status for details.'), 'log-err');
    } finally {
        isTesting.value = false;
    }
};

const updateActiveHours = (start: string, end: string) => {
    const normalizedStart = start.trim();
    const normalizedEnd = end.trim();

    if (normalizedStart.length === 0 && normalizedEnd.length === 0) {
        form.value.ai_news_active_hours = null;
        return;
    }

    form.value.ai_news_active_hours = `${normalizedStart}-${normalizedEnd}`;
};

const updateActiveHoursStart = (event: Event) => {
    const target = event.target as HTMLInputElement;
    updateActiveHours(target.value, activeHoursEnd.value);
};

const updateActiveHoursEnd = (event: Event) => {
    const target = event.target as HTMLInputElement;
    updateActiveHours(activeHoursStart.value, target.value);
};

const refreshHeadlinePreview = () => {
    appendLog($gettext('Headline preview refreshed from the latest backend dashboard payload.'), 'log-info');
};
</script>

<style scoped>
.ai-news-page {
    color: #e2e8f0;
}

.ai-news-shell {
    overflow: hidden;
    border: 1px solid #2a2d3e;
    border-radius: 14px;
    background: #0f1117;
}

.ai-news-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #2a2d3e;
    background: #1a1d27;
}

.ai-news-branding {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.logo-dot {
    flex: 0 0 auto;
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background: #4f8ef7;
    box-shadow: 0 0 12px rgba(79, 142, 247, 0.75);
}

.ai-news-title {
    margin: 0;
    color: #e2e8f0;
    font-size: 1.2rem;
    font-weight: 700;
}

.ai-news-subtitle {
    color: #94a3b8;
    font-size: 0.85rem;
}

.live-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.35rem 0.85rem;
    border: 1px solid #22c55e;
    border-radius: 999px;
    background: rgba(34, 197, 94, 0.1);
    color: #22c55e;
    font-size: 0.8rem;
    font-weight: 600;
}

.live-badge.is-off {
    border-color: #ef4444;
    background: rgba(239, 68, 68, 0.08);
    color: #fca5a5;
}

.live-dot {
    width: 0.45rem;
    height: 0.45rem;
    border-radius: 999px;
    background: currentColor;
    box-shadow: 0 0 8px currentColor;
}

.ai-news-container {
    max-width: 1100px;
    margin: 0 auto;
    padding: 1.5rem;
}

.reference-note {
    margin-bottom: 1.25rem;
    color: #94a3b8;
    font-size: 0.85rem;
    line-height: 1.5;
}

.reference-note p {
    margin: 0;
}

.dashboard-card {
    padding: 1.5rem;
    border: 1px solid #2a2d3e;
    border-radius: 12px;
    background: #1a1d27;
}

.status-strip {
    margin-bottom: 1.5rem;
}

.dashboard-card-title {
    margin-bottom: 1rem;
    color: #6b7280;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.status-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}

.stat-card {
    padding: 0.875rem 1rem;
    border: 1px solid #2a2d3e;
    border-radius: 8px;
    background: #0f1117;
}

.stat-label {
    margin-bottom: 0.25rem;
    color: #6b7280;
    font-size: 0.7rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.stat-value {
    color: #e2e8f0;
    font-size: 1.05rem;
    font-weight: 600;
}

.tone-green {
    color: #22c55e;
}

.tone-yellow {
    color: #f59e0b;
}

.tone-blue {
    color: #4f8ef7;
}

.tone-red {
    color: #ef4444;
}

.tone-muted {
    color: #94a3b8;
}

.content-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 1.5rem;
}

.left-column {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.generate-area {
    padding: 0.75rem 0;
    text-align: center;
}

.big-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 2.25rem;
    border: 0;
    border-radius: 8px;
    background: #4f8ef7;
    color: #fff;
    font-size: 1.1rem;
    font-weight: 700;
    box-shadow: 0 0 20px rgba(79, 142, 247, 0.3);
    transition: filter 0.2s ease, box-shadow 0.2s ease;
}

.big-btn:hover:not(:disabled) {
    filter: brightness(1.08);
    box-shadow: 0 0 30px rgba(79, 142, 247, 0.45);
}

.big-btn:disabled {
    opacity: 0.6;
    box-shadow: none;
}

.spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 999px;
    animation: spin 0.7s linear infinite;
}

.audio-section {
    margin-top: 1.5rem;
}

.player-box,
.log-box {
    border: 1px solid #2a2d3e;
    border-radius: 8px;
    background: #0f1117;
}

.player-box {
    padding: 1rem 1.25rem;
}

.player-title {
    margin-bottom: 0.75rem;
    color: #94a3b8;
    font-size: 0.8rem;
}

.audio-placeholder {
    padding: 0.75rem 0.9rem;
    border: 1px dashed #334155;
    border-radius: 6px;
    color: #cbd5e1;
    font-size: 0.9rem;
    line-height: 1.5;
}

.meta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 0.75rem;
    color: #94a3b8;
    font-size: 0.78rem;
}

.meta-item {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.log-section {
    margin-top: 1.25rem;
}

.log-box {
    min-height: 70px;
    max-height: 180px;
    padding: 0.75rem 0.9rem;
    overflow-y: auto;
    color: #94a3b8;
    font-family: monospace;
    font-size: 0.78rem;
}

.log-line + .log-line {
    margin-top: 0.25rem;
}

.log-ok {
    color: #22c55e;
}

.log-err {
    color: #ef4444;
}

.log-info {
    color: #60a5fa;
}

.headlines-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.headline-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.headline-item {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid #2a2d3e;
}

.headline-item:last-child {
    padding-bottom: 0;
    border-bottom: 0;
}

.src-tag {
    flex: 0 0 auto;
    margin-top: 0.1rem;
    padding: 0.15rem 0.45rem;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.src-worthy {
    background: rgba(79, 142, 247, 0.15);
    color: #4f8ef7;
}

.src-rapture {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
}

.src-bbc {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.src-info {
    background: rgba(148, 163, 184, 0.15);
    color: #cbd5e1;
}

.hl-title {
    color: #e2e8f0;
    font-size: 0.88rem;
    line-height: 1.4;
    word-break: break-word;
}

.hl-summary {
    margin-top: 0.2rem;
    color: #94a3b8;
    font-size: 0.78rem;
    line-height: 1.45;
}

.settings-card :deep(.form-group) {
    margin-bottom: 1rem;
}

.settings-card-plain :deep(.form-group) {
    margin-bottom: 1rem;
}

.settings-card-plain :deep(.form-group-label) {
    margin-bottom: 0;
}

.settings-card-plain :deep(.form-group .form-label) {
    margin-bottom: 0.4rem;
    color: #94a3b8;
    font-size: 0.8rem;
}

.settings-card :deep(label) {
    display: block;
    margin-bottom: 0.4rem;
    color: #94a3b8;
    font-size: 0.8rem;
}

.toggle-label-row {
    margin-bottom: 0.4rem;
}

.label-helper {
    color: #6b7280;
    font-weight: 400;
}

.settings-group {
    margin-bottom: 1rem;
}

.settings-group-tight {
    margin-bottom: 1rem;
}

.settings-group-tight:last-of-type {
    margin-bottom: 0;
}

.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.25rem;
}

.toggle-label {
    color: #e2e8f0;
    font-weight: 600;
}

.toggle-helper,
.field-note,
.save-status {
    color: #94a3b8;
    font-size: 0.8rem;
}

.field-note {
    margin-top: 0.4rem;
    line-height: 1.45;
}

.toggle {
    position: relative;
    flex: 0 0 auto;
    width: 48px;
    height: 26px;
    cursor: pointer;
}

.toggle input {
    width: 0;
    height: 0;
    opacity: 0;
}

.slider {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: #374151;
    transition: background 0.2s ease;
}

.slider::before {
    content: "";
    position: absolute;
    left: 4px;
    bottom: 4px;
    width: 18px;
    height: 18px;
    border-radius: 999px;
    background: #fff;
    transition: transform 0.2s ease;
}

.toggle input:checked + .slider {
    background: #4f8ef7;
}

.toggle input:checked + .slider::before {
    transform: translateX(22px);
}

.form-control-dark {
    border: 1px solid #2a2d3e;
    background: #0f1117;
    color: #e2e8f0;
}

.form-control-dark:focus {
    border-color: #4f8ef7;
    background: #0f1117;
    color: #e2e8f0;
    box-shadow: 0 0 0 0.2rem rgba(79, 142, 247, 0.15);
}

.form-control-dark:disabled {
    background: #111827;
    color: #94a3b8;
    opacity: 1;
}

.time-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
}

.source-checks {
    display: flex;
    flex-wrap: wrap;
    gap: 0.9rem;
    margin-bottom: 0.25rem;
}

.check-label {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.15rem 0;
    color: #94a3b8;
    font-size: 0.85rem;
}

.check-label.active {
    color: #e2e8f0;
}

.check-label input {
    width: 14px;
    height: 14px;
    accent-color: #4f8ef7;
}

.btn-row {
    display: flex;
    gap: 0.625rem;
    margin-top: 1.25rem;
}

.save-status {
    min-height: 1.25rem;
    margin-top: 0.75rem;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 900px) {
    .status-grid,
    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .ai-news-topbar {
        align-items: flex-start;
        flex-direction: column;
        padding: 1rem;
    }

    .ai-news-container {
        padding: 1rem;
    }

    .status-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .time-row,
    .btn-row {
        grid-template-columns: 1fr;
        flex-direction: column;
    }
}
</style>
