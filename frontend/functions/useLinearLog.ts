import {computed, onMounted, onUnmounted, ref, watch} from "vue";
import {useIntervalFn} from "@vueuse/core";
import useNowPlaying from "~/functions/useNowPlaying.ts";
import {useStationData} from "~/functions/useStationQuery.ts";
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
    const rulesUrl = getStationApiUrl("/reports/linear-log/rules/apply");

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
            const {data} = await axios.get<LinearLogResponse>(statusUrl.value, {
                params: {_: Date.now()},
            });
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

    // Standing operator override rules, saved with the log's own settings.
    const rules = ref({
        linear_log_rule_enforce_windows: true,
        linear_log_rule_drop_outside_window: true,
        linear_log_rule_refill_dropped: true,
    });
    const rulesLoaded = ref(false);
    const ruleResult = ref("");

    async function loadRules(): Promise<void> {
        try {
            const {data} = await axios.get<Record<string, boolean | number>>(settingsUrl.value);
            rules.value = {
                linear_log_rule_enforce_windows: !!data.linear_log_rule_enforce_windows,
                linear_log_rule_drop_outside_window: !!data.linear_log_rule_drop_outside_window,
                linear_log_rule_refill_dropped: !!data.linear_log_rule_refill_dropped,
            };
            rulesLoaded.value = true;
        } catch {
            // Leave the defaults in place; the log itself still loads.
        }
    }

    async function setRule(key: keyof typeof rules.value, value: boolean): Promise<void> {
        const previous = rules.value[key];
        rules.value = {...rules.value, [key]: value};
        try {
            await axios.put(settingsUrl.value, {[key]: value});
        } catch (error: unknown) {
            rules.value = {...rules.value, [key]: previous};
            buildError.value = errorMessage(error, $gettext("Unable to save the log rule."));
        }
    }

    const isApplyingRules = ref(false);

    /** Run the rules against the saved log now and report what they corrected. */
    async function applyRules(): Promise<void> {
        isApplyingRules.value = true;
        buildError.value = "";
        ruleResult.value = "";
        try {
            const {data} = await axios.post<{
                checked: number;
                dropped: number;
                skipped_locked: number;
                reasons: string[];
                rebuilding: boolean;
            }>(rulesUrl.value, {});

            if (data.dropped === 0) {
                ruleResult.value = $gettext("Checked %{count} planned lines; nothing broke the rules.")
                    .replace("%{count}", String(data.checked));
            } else {
                ruleResult.value = $gettext("Took %{dropped} of %{checked} lines out of the plan: %{reasons}")
                    .replace("%{dropped}", String(data.dropped))
                    .replace("%{checked}", String(data.checked))
                    .replace("%{reasons}", data.reasons.join("; "));
            }

            if (data.skipped_locked > 0) {
                ruleResult.value += " " + $gettext("%{locked} locked lines were left as you set them.")
                    .replace("%{locked}", String(data.skipped_locked));
            }

            if (data.rebuilding) {
                status.value = "queued";
                schedulePoll();
            } else {
                await loadSnapshot(false);
            }
        } catch (error: unknown) {
            buildError.value = errorMessage(error, $gettext("Unable to apply the log rules."));
        } finally {
            isApplyingRules.value = false;
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

    // ON AIR follows the same live now-playing stream as the sidebar, not the
    // forecast, and the log re-times the moment the song changes.
    const stationData = useStationData();
    const {np} = useNowPlaying(computed(() => ({
        stationShortName: stationData.value.shortName,
        useStatic: false,
        useSse: true,
    })));

    const liveSongId = computed(() => np.value.now_playing?.song?.id || null);
    const livePlayedAt = computed(() => np.value.now_playing?.played_at || null);

    watch([liveSongId, livePlayedAt], ([nextId, nextAt], [prevId, prevAt]) => {
        const changed = (nextId && nextId !== prevId) || (nextAt && prevAt && nextAt !== prevAt);
        if (changed && featureEnabled.value && !isBuilding.value) {
            void loadSnapshot(false);
        }
    });

    useIntervalFn(() => {
        nowTs.value = Math.floor(Date.now() / 1000);
    }, 1000);

    const onAirItem = computed<LinearLogItem | null>(() => {
        if (liveSongId.value && livePlayedAt.value) {
            let match: LinearLogItem | null = null;
            let bestDiff = 300;
            for (const item of allItems.value) {
                // A swapped line is the song that actually aired in its slot.
                if (item.log_status === "dropped") {
                    continue;
                }
                if (item.song_id !== liveSongId.value) {
                    continue;
                }
                const start = item.aired_at ?? item.played_at;
                if (!start) {
                    continue;
                }
                const diff = Math.abs(start - livePlayedAt.value);
                if (diff < bestDiff) {
                    match = item;
                    bestDiff = diff;
                }
            }
            if (match) {
                return match;
            }
        }

        // No live match (stream offline, or the song is not in the log): fall
        // back to the most recently started line that hasn't finished yet.
        let best: LinearLogItem | null = null;
        let bestStart = -Infinity;
        for (const item of allItems.value) {
            if (item.log_status === "dropped") {
                continue;
            }
            const start = item.aired_at ?? item.played_at;
            if (start === null || start === undefined || start > nowTs.value) {
                continue;
            }
            const end = start + Math.ceil(item.duration || 0);
            if (end < nowTs.value) {
                continue;
            }
            if (start > bestStart) {
                best = item;
                bestStart = start;
            }
        }
        return best;
    });

    onMounted(() => {
        void loadSnapshot();
        void loadRules();
    });
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
        onAirItem,
        isBuilding,
        loadSnapshot,
        requestBuild,
        isSavingSettings,
        setEnabled,
        isEditing,
        editEntry,
        searchMedia,
        rules,
        rulesLoaded,
        setRule,
        applyRules,
        isApplyingRules,
        ruleResult,
    };
}
