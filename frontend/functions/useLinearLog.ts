import {computed, onMounted, onUnmounted, ref} from "vue";
import type {
    LinearLogAiDjShift,
    LinearLogGap,
    LinearLogItem,
    LinearLogMediaOption,
    LinearLogResponse,
    LinearLogStatus,
} from "~/entities/LinearLog";
import {useApiRouter} from "~/functions/useApiRouter";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";

function errorMessage(error: unknown, fallback: string): string {
    if (typeof error !== "object" || error === null) {
        return fallback;
    }

    const candidate = error as {
        message?: string;
        response?: {data?: {message?: string}};
    };

    return candidate.response?.data?.message ?? candidate.message ?? fallback;
}

export function useLinearLog() {
    const {$gettext} = useTranslate();
    const {axios} = useAxios();
    const {getStationApiUrl} = useApiRouter();

    const statusUrl = getStationApiUrl("/reports/linear-log");
    const buildUrl = getStationApiUrl("/reports/linear-log/build");
    const settingsUrl = getStationApiUrl("/reports/linear-log/settings");
    const mediaUrl = getStationApiUrl("/reports/linear-log/media");
    const entriesUrl = getStationApiUrl("/reports/linear-log/entries");

    const initialLoading = ref(true);
    const buildError = ref("");
    const status = ref<LinearLogStatus>("idle");
    const featureEnabled = ref(true);
    const playoutEnabled = ref(false);
    const hoursAhead = ref(24);
    const snapshotHours = ref(24);
    const builtAt = ref<number | null>(null);
    const coverageStart = ref<number | null>(null);
    const coverageEnd = ref<number | null>(null);
    const allItems = ref<LinearLogItem[]>([]);
    const gaps = ref<LinearLogGap[]>([]);
    const aiDjShifts = ref<LinearLogAiDjShift[]>([]);
    const nowTs = ref(Math.floor(Date.now() / 1000));

    let initializedHours = false;
    let pollTimer: number | null = null;

    const isBuilding = computed(() => status.value === "queued" || status.value === "building");

    function clearPoll(): void {
        if (pollTimer !== null) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function schedulePoll(delayMs = 2000): void {
        clearPoll();
        pollTimer = window.setTimeout(() => void loadSnapshot(false), delayMs);
    }

    async function loadSnapshot(showLoader = true): Promise<void> {
        if (showLoader && allItems.value.length === 0) {
            initialLoading.value = true;
        }

        try {
            const {data} = await axios.get<LinearLogResponse>(statusUrl.value);
            status.value = data.status;
            featureEnabled.value = data.enabled;
            playoutEnabled.value = data.playout_enabled ?? false;
            snapshotHours.value = data.hours || data.configured_hours || 24;
            builtAt.value = data.built_at;
            coverageStart.value = data.coverage_start;
            coverageEnd.value = data.coverage_end;
            allItems.value = data.entries ?? [];
            gaps.value = data.gaps ?? [];
            aiDjShifts.value = data.ai_dj_shifts ?? [];
            buildError.value = data.error ?? "";
            nowTs.value = Math.floor(Date.now() / 1000);

            if (!initializedHours) {
                hoursAhead.value = data.hours || data.configured_hours || 24;
                initializedHours = true;
            }

            if (data.enabled && (data.status === "queued" || data.status === "building")) {
                schedulePoll();
            } else if (data.enabled) {
                // Times are re-computed live from what is playing; keep them current.
                schedulePoll(30000);
            } else {
                clearPoll();
            }
        } catch (error: unknown) {
            clearPoll();
            buildError.value = errorMessage(error, $gettext("Unable to load the Linear Log."));
        } finally {
            initialLoading.value = false;
        }
    }

    async function requestBuild(): Promise<void> {
        if (!featureEnabled.value || isBuilding.value) {
            return;
        }

        clearPoll();
        buildError.value = "";
        status.value = "queued";

        try {
            await axios.post(buildUrl.value, {hours: hoursAhead.value});
            snapshotHours.value = hoursAhead.value;
            schedulePoll();
        } catch (error: unknown) {
            status.value = "failed";
            buildError.value = errorMessage(error, $gettext("Unable to queue the Linear Log build."));
        }
    }

    const isSavingSettings = ref(false);

    async function setEnabled(enabled: boolean): Promise<void> {
        isSavingSettings.value = true;
        buildError.value = "";
        try {
            await axios.put(settingsUrl.value, {
                linear_log_enabled: enabled,
                linear_log_hours: hoursAhead.value,
            });
            await loadSnapshot(false);
            if (enabled) {
                await requestBuild();
            }
        } catch (error: unknown) {
            buildError.value = errorMessage(error, $gettext("Unable to save the Playout Log setting."));
        } finally {
            isSavingSettings.value = false;
        }
    }

    // Hand edits on a planned log line (lock, unlock, up, down, remove, replace).
    const isEditing = ref(false);

    async function editEntry(entryId: number, edit: string, body: Record<string, unknown> = {}): Promise<boolean> {
        isEditing.value = true;
        buildError.value = "";
        try {
            await axios.post(`${entriesUrl.value}/${entryId}/${edit}`, body);
            status.value = "queued";
            schedulePoll();
            return true;
        } catch (error: unknown) {
            buildError.value = errorMessage(error, $gettext("Unable to change the log line."));
            return false;
        } finally {
            isEditing.value = false;
        }
    }

    async function searchMedia(query: string): Promise<LinearLogMediaOption[]> {
        const {data} = await axios.get<LinearLogMediaOption[]>(mediaUrl.value, {params: {q: query}});
        return data;
    }

    onMounted(() => void loadSnapshot());
    onUnmounted(clearPoll);

    return {
        initialLoading,
        buildError,
        status,
        featureEnabled,
        playoutEnabled,
        hoursAhead,
        snapshotHours,
        builtAt,
        coverageStart,
        coverageEnd,
        allItems,
        gaps,
        aiDjShifts,
        nowTs,
        isBuilding,
        loadSnapshot,
        requestBuild,
        isSavingSettings,
        setEnabled,
        isEditing,
        editEntry,
        searchMedia,
    };
}
