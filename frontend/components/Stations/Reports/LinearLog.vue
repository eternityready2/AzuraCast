<template>
    <div class="linear-log-page">
        <section
            class="linear-log-card"
            :class="{'is-disabled': !initialLoading && !featureEnabled}"
        >
            <tabs
                nav-tabs-class="nav-tabs linear-log-tabs"
                content-class="mt-0"
                destroy-on-hide
            >
                <tab :label="$gettext('Linear Log')">
                    <header class="linear-log-header">
                        <div>
                            <h1>{{ pageTitle }}</h1>
                            <p>{{ $gettext('Upcoming scheduled programming built by AutoDJ') }}</p>
                        </div>

                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <div class="form-check form-switch mb-0 me-2">
                                <input
                                    id="linear_log_enabled"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    :checked="featureEnabled"
                                    :disabled="initialLoading || isSavingSettings || isBuilding"
                                    @change="setEnabled(($event.target as HTMLInputElement).checked)"
                                >
                                <label class="form-check-label fw-semibold" for="linear_log_enabled">
                                    {{ $gettext('Enable 24-Hour Playout Log') }}
                                </label>
                            </div>
                            <span
                                v-if="!initialLoading"
                                class="badge state-badge"
                                :class="featureEnabled ? 'text-bg-success' : 'text-bg-danger'"
                            >
                                {{ featureEnabled ? $gettext('ON') : $gettext('OFF') }}
                            </span>

                            <label class="visually-hidden" for="linear_log_hours">{{ $gettext('Hours') }}</label>
                            <select
                                id="linear_log_hours"
                                v-model.number="hoursAhead"
                                class="form-select form-select-sm hours-select"
                                :disabled="isBuilding"
                            >
                                <option :value="24">24 {{ $gettext('hours') }}</option>
                                <option :value="48">48 {{ $gettext('hours') }}</option>
                            </select>

                            <button
                                type="button"
                                class="btn btn-light btn-sm fw-semibold"
                                :disabled="isBuilding || !featureEnabled"
                                @click="requestBuild"
                            >
                                <span
                                    v-if="isBuilding"
                                    class="spinner-border spinner-border-sm me-1"
                                    role="status"
                                    aria-hidden="true"
                                />
                                {{ isBuilding ? $gettext('BUILDING') : $gettext('BUILD AND REFRESH') }}
                            </button>

                            <a
                                class="btn btn-light btn-sm fw-semibold"
                                :href="exportUrl"
                            >
                                {{ $gettext('EXPORT CSV') }}
                            </a>
                            <a
                                class="btn btn-light btn-sm fw-semibold"
                                :href="exportUrl + '?format=print'"
                                target="_blank"
                                rel="noopener"
                            >
                                {{ $gettext('PRINT') }}
                            </a>
                        </div>
                    </header>

                    <div
                        v-if="!initialLoading && !featureEnabled"
                        class="alert alert-danger disabled-banner rounded-0 border-start-0 border-end-0 mb-0"
                        role="status"
                    >
                        <div>
                            <div class="fw-bold fs-5">
                                {{ $gettext('24-Hour Playout Log is OFF') }}
                            </div>
                            <div>
                                {{ $gettext('No new snapshots are being built. Anything shown below is the last snapshot from before it was turned off and is not being updated.') }}
                            </div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-danger fw-semibold"
                            :disabled="isSavingSettings"
                            @click="setEnabled(true)"
                        >
                            {{ $gettext('Turn On') }}
                        </button>
                    </div>

                    <div v-if="buildError" class="alert alert-danger rounded-0 border-start-0 border-end-0 mb-0">
                        <strong>{{ $gettext('Linear Log build failed.') }}</strong>
                        {{ buildError }}
                    </div>

                    <div v-else-if="isBuilding" class="build-status">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" />
                        <span>
                            {{ status === 'queued'
                                ? $gettext('The Linear Log build is queued in the background.')
                                : $gettext('AutoDJ is calculating the isolated playout projection in the background.') }}
                        </span>
                        <span v-if="allItems.length" class="text-body-secondary">
                            {{ $gettext('The last completed snapshot remains visible below.') }}
                        </span>
                    </div>

                    <div
                        v-if="gapCount > 0 && gapsAhead.length === 0"
                        class="alert alert-warning rounded-0 border-start-0 border-end-0 mb-0"
                    >
                        <div class="fw-semibold">
                            {{ gapCount }} {{ $gettext('projected gap(s) detected') }} — {{ totalGapDuration }}
                        </div>
                        <div class="small mt-1">
                            {{ $gettext('A gap means AutoDJ could not find an eligible item for that simulated time after applying schedules, rotation, duplicate prevention, DMCA and other playout rules. The preview advanced up to five minutes (never past the top of the hour) and kept calculating instead of silently truncating the day.') }}
                        </div>
                    </div>

                    <div
                        v-if="gapsAhead.length"
                        class="alert rounded-0 border-start-0 border-end-0 mb-0 log-alert"
                        :class="gapsAhead.some((gap) => !gap.projected) ? 'alert-danger' : 'alert-warning'"
                    >
                        <div class="fw-semibold">
                            {{ gapsAhead.length }} {{ $gettext('gap(s) in the next 24 hours') }} — {{ secondsToHms(gapsAheadSeconds) }}
                        </div>
                        <ul class="gap-list">
                            <li v-for="gap in gapsAhead" :key="gap.start">
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm gap-jump"
                                    :title="$gettext('Go to this gap in the log')"
                                    @click="jumpToGap(gap)"
                                >{{ formatGapTime(gap.start) }}</button>
                                <span>{{ gapSummary(gap) }}</span>
                                <span v-if="gap.projected" class="badge text-bg-secondary ms-1">{{ $gettext('PROJECTED') }}</span>
                            </li>
                        </ul>
                        <div v-if="gapsAhead.some((gap) => gap.projected)" class="small">
                            {{ $gettext('A projected gap is more than three hours away. The log is still topped up and re-timed before then, so it may close by itself.') }}
                        </div>
                    </div>

                    <div
                        v-for="alert in otherAlerts"
                        :key="`${alert.type}-${alert.at}`"
                        class="alert rounded-0 border-start-0 border-end-0 mb-0 log-alert"
                        :class="alert.level === 'danger' ? 'alert-danger' : 'alert-warning'"
                    >
                        <span class="fw-semibold">{{ alertLabel(alert) }}</span>
                        {{ alert.message }}
                    </div>

                    <div class="filter-bar">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="filter-label">{{ $gettext('Show') }}</span>
                            <button
                                v-for="filter in typeFilters"
                                :key="filter.key"
                                type="button"
                                class="btn btn-sm"
                                :class="activeTypes.includes(filter.key) ? filter.activeClass : 'btn-outline-secondary'"
                                @click="toggleType(filter.key)"
                            >
                                {{ filter.label }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm"
                                :class="showDropped ? 'btn-dark filter-on-dark' : 'btn-outline-secondary'"
                                :title="$gettext('Lines taken out of the log, with the reason')"
                                @click="showDropped = !showDropped"
                            >
                                {{ $gettext('Dropped') }}
                            </button>

                            <div class="ms-md-auto d-flex gap-2 flex-wrap">
                                <input
                                    v-model="searchQuery"
                                    type="search"
                                    class="form-control form-control-sm search-box"
                                    :placeholder="$gettext('Search title, artist or source')"
                                >

                                <div class="dropdown">
                                    <button
                                        class="btn btn-outline-secondary btn-sm dropdown-toggle"
                                        type="button"
                                        data-bs-toggle="dropdown"
                                    >
                                        {{ $gettext('Columns') }}
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li v-for="column in columnOptions" :key="column.key">
                                            <label class="dropdown-item d-flex align-items-center gap-2 mb-0">
                                                <input
                                                    v-model="visibleColumns"
                                                    class="form-check-input mt-0"
                                                    type="checkbox"
                                                    :value="column.key"
                                                >
                                                {{ column.label }}
                                            </label>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rules-bar">
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <span class="filter-label">{{ $gettext('Operator rules') }}</span>

                            <div class="form-check form-switch mb-0">
                                <input
                                    id="linear_log_rule_enforce_windows"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    :checked="rules.linear_log_rule_enforce_windows"
                                    @change="setRule('linear_log_rule_enforce_windows', ($event.target as HTMLInputElement).checked)"
                                >
                                <label class="form-check-label" for="linear_log_rule_enforce_windows">
                                    {{ $gettext('Take out music that leaks into a scheduled block') }}
                                </label>
                            </div>

                            <div class="form-check form-switch mb-0">
                                <input
                                    id="linear_log_rule_drop_outside_window"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    :checked="rules.linear_log_rule_drop_outside_window"
                                    @change="setRule('linear_log_rule_drop_outside_window', ($event.target as HTMLInputElement).checked)"
                                >
                                <label class="form-check-label" for="linear_log_rule_drop_outside_window">
                                    {{ $gettext('Take out lines planned outside their own window (starting too early)') }}
                                </label>
                            </div>

                            <div class="form-check form-switch mb-0">
                                <input
                                    id="linear_log_rule_refill_dropped"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    :checked="rules.linear_log_rule_refill_dropped"
                                    @change="setRule('linear_log_rule_refill_dropped', ($event.target as HTMLInputElement).checked)"
                                >
                                <label class="form-check-label" for="linear_log_rule_refill_dropped">
                                    {{ $gettext('Refill what the rules take out') }}
                                </label>
                            </div>

                        </div>

                        <div class="small mt-2 text-body-secondary">
                            {{ $gettext('These rules run by themselves on every build and every minute, and never touch a line you locked by hand.') }}
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2 mt-2 block-lock">
                            <span class="filter-label">{{ $gettext('Lock a block') }}</span>
                            <select
                                v-model="blockFrom"
                                class="form-select form-select-sm"
                                :aria-label="$gettext('From hour')"
                            >
                                <option :value="null">{{ $gettext('From hour') }}</option>
                                <option v-for="group in hourGroups" :key="group.epochHour" :value="group.epochHour">
                                    {{ group.label }}
                                </option>
                            </select>
                            <span class="small text-body-secondary">{{ $gettext('through') }}</span>
                            <select
                                v-model="blockThrough"
                                class="form-select form-select-sm"
                                :aria-label="$gettext('Through hour')"
                            >
                                <option :value="null">{{ $gettext('Through hour') }}</option>
                                <option v-for="group in hourGroups" :key="group.epochHour" :value="group.epochHour">
                                    {{ group.label }}
                                </option>
                            </select>
                            <button
                                type="button"
                                class="btn btn-outline-secondary btn-sm"
                                :disabled="isEditing || isBuilding || blockFrom === null || blockThrough === null"
                                @click="lockBlock(true)"
                            >
                                {{ $gettext('Lock') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-outline-secondary btn-sm"
                                :disabled="isEditing || isBuilding || blockFrom === null || blockThrough === null"
                                @click="lockBlock(false)"
                            >
                                {{ $gettext('Unlock') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="allItems.length" class="stats-bar">
                        <span><strong>{{ airableItems.length }}</strong> {{ $gettext('items') }}</span>
                        <span><strong>{{ totalDurationFormatted }}</strong> {{ $gettext('program runtime') }}</span>
                        <span><strong>{{ snapshotHours }}</strong> {{ $gettext('hour snapshot') }}</span>
                        <span v-if="zoneLabel">{{ $gettext('Station time') }} <strong>{{ zoneLabel }}</strong></span>
                        <span v-if="builtAt">
                            {{ $gettext('Built') }} <strong>{{ formatDateTime(builtAt) }}</strong>
                        </span>
                        <span v-if="nextBuildAt">
                            {{ $gettext('Next build') }} <strong>{{ formatDateTime(nextBuildAt) }}</strong>
                        </span>
                        <span v-if="coverageEnd">
                            {{ $gettext('Coverage through') }} <strong>{{ formatDateTime(coverageEnd) }}</strong>
                        </span>
                        <span
                            v-if="onAirItem"
                            class="on-air-indicator"
                        >
                            <span class="on-air-badge">{{ $gettext('ON AIR') }}</span>
                            <strong>{{ displayTitle(onAirItem) }}</strong>
                        </span>
                        <span v-if="nextUpItem">
                            <span class="next-up-badge">{{ $gettext('NEXT') }}</span>
                            <strong>{{ displayTitle(nextUpItem) }}</strong>
                            {{ $gettext('at') }} <strong>{{ formatTime(nextUpItem.played_at) }}</strong>
                        </span>
                    </div>

                    <linear-log-ai-dj-shifts :shifts="aiDjShifts" />

                    <div v-if="coverageWarning" class="coverage-warning">
                        <strong>{{ $gettext('Projection ended before the requested horizon.') }}</strong>
                        {{ coverageWarning }}
                    </div>

                    <div v-if="initialLoading" class="loading-state">
                        <div class="spinner-border text-primary" role="status" />
                        <div class="mt-3 fw-semibold">{{ $gettext('Loading Linear Log...') }}</div>
                    </div>

                    <div v-else-if="0 === allItems.length" class="empty-state">
                        <h2>{{ $gettext('No Linear Log Snapshot Yet') }}</h2>
                        <p>
                            {{ $gettext('Build the log to calculate an isolated AutoDJ projection. The preview does not write a 24-hour fake queue into live station playback.') }}
                        </p>
                        <button
                            type="button"
                            class="btn btn-primary mt-3"
                            :disabled="isBuilding || !featureEnabled"
                            @click="requestBuild"
                        >
                            {{ $gettext('Build Linear Log') }}
                        </button>
                    </div>

                    <linear-log-schedule
                        v-else
                        :groups="hourGroups"
                        :visible-columns="visibleColumns"
                        :now-ts="nowTs"
                        :on-air-item="onAirItem"
                        :busy="isEditing || isBuilding"
                        :highlight-gap="highlightGap"
                        @edit="onEdit"
                        @replace="openReplace"
                        @lock-lines="onLockLines"
                    />

                    <div
                        v-if="replaceItem"
                        class="replace-backdrop"
                        role="dialog"
                        aria-modal="true"
                        @click.self="closeReplace"
                    >
                        <div class="replace-dialog card shadow">
                            <div class="card-header d-flex align-items-center">
                                <strong>{{ $gettext('Replace log line') }}</strong>
                                <button type="button" class="btn-close ms-auto" :aria-label="$gettext('Close')" @click="closeReplace" />
                            </div>
                            <div class="card-body">
                                <div class="small text-body-secondary mb-2">
                                    {{ formatTime(replaceItem.played_at) }} &middot; {{ displayTitle(replaceItem) }}
                                </div>
                                <input
                                    v-model="replaceQuery"
                                    type="search"
                                    class="form-control form-control-sm mb-2"
                                    :placeholder="$gettext('Search the library by title or artist')"
                                    @input="onReplaceSearch"
                                >
                                <div class="list-group replace-results">
                                    <button
                                        v-for="option in replaceOptions"
                                        :key="option.id"
                                        type="button"
                                        class="list-group-item list-group-item-action d-flex gap-2"
                                        :disabled="isEditing"
                                        @click="applyReplace(option.id)"
                                    >
                                        <span class="flex-grow-1 text-start">
                                            <strong>{{ option.title || option.text }}</strong>
                                            <span v-if="option.artist" class="d-block small text-body-secondary">{{ option.artist }}</span>
                                        </span>
                                        <span class="small font-monospace">{{ formatLength(option.length) }}</span>
                                    </button>
                                    <div v-if="replaceQuery.length >= 2 && !replaceOptions.length" class="small text-body-secondary p-2">
                                        {{ $gettext('No matches.') }}
                                    </div>
                                </div>
                                <div class="small text-body-secondary mt-2">
                                    {{ $gettext('The replacement is locked so a rebuild keeps it.') }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <footer class="linear-log-footer">
                        {{ $gettext('Strict scheduled programs use the authoritative strict-playlist forecast, so their projected songs match the same source used by Playing Next and Upcoming Song Queue. AI DJ work shifts are shown, but speech remains live-generated and is never synthesized or enqueued by this preview.') }}
                    </footer>
                </tab>

                <tab :label="$gettext('Upcoming Song Queue')">
                    <linear-log-queue-tab />
                </tab>
            </tabs>
        </section>
    </div>
</template>

<script setup lang="ts">
import {computed, nextTick, ref} from "vue";
import LinearLogAiDjShifts from "~/components/Stations/Reports/LinearLogAiDjShifts.vue";
import LinearLogSchedule from "~/components/Stations/Reports/LinearLogSchedule.vue";
import LinearLogQueueTab from "~/components/Stations/Reports/LinearLogQueueTab.vue";
import Tabs from "~/components/Common/Tabs.vue";
import Tab from "~/components/Common/Tab.vue";
import type {
    LinearLogAlert,
    LinearLogGapAhead,
    LinearLogHourGroup,
    LinearLogItem,
    LinearLogMediaOption,
} from "~/entities/LinearLog";
import {useLinearLog} from "~/functions/useLinearLog";
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";
import {useTranslate} from "~/vendor/gettext";
import {useApiRouter} from "~/functions/useApiRouter";

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const exportUrl = getStationApiUrl("/reports/linear-log/export");
const {
    initialLoading,
    buildError,
    status,
    featureEnabled,
    hoursAhead,
    snapshotHours,
    builtAt,
    nextBuildAt,
    coverageStart,
    coverageEnd,
    allItems,
    gaps,
    alerts,
    gapsAhead,
    aiDjShifts,
    nowTs,
    onAirItem,
    isBuilding,
    requestBuild,
    isSavingSettings,
    setEnabled,
    isEditing,
    editEntry,
    rules,
    setRule,
    searchMedia,
} = useLinearLog();

async function onEdit(item: LinearLogItem, edit: string): Promise<void> {
    if (!item.log_entry_id) return;
    if (edit === "remove" && !window.confirm($gettext("Remove this line from the log?"))) return;
    await editEntry(item.log_entry_id, edit);
}

// A whole hour, or a block of hours, in one go.
async function onLockLines(entryIds: number[], lock: boolean): Promise<void> {
    if (entryIds.length === 0) return;
    await editEntry(entryIds[0], lock ? "lock-lines" : "unlock-lines", {entry_ids: entryIds});
}

const blockFrom = ref<number | null>(null);
const blockThrough = ref<number | null>(null);

function blockLineIds(): number[] {
    if (blockFrom.value === null || blockThrough.value === null) return [];
    const from = Math.min(blockFrom.value, blockThrough.value);
    const through = Math.max(blockFrom.value, blockThrough.value);
    return hourGroups.value
        .filter((group) => group.epochHour >= from && group.epochHour <= through)
        .flatMap((group) => group.lockableIds);
}

async function lockBlock(lock: boolean): Promise<void> {
    await onLockLines(blockLineIds(), lock);
}

function alertLabel(alert: LinearLogAlert): string {
    const labels: Record<string, string> = {
        hole: $gettext("Hole in the log:"),
        short_hour: $gettext("Short hour:"),
        autodj: $gettext("AutoDJ stepped in:"),
    };
    return labels[alert.type] ?? "";
}

// A hole or a short hour the gap list shows, with a way to reach it, does not
// need a second line; every other alert keeps its own.
const otherAlerts = computed(() => alerts.value.filter(
    (alert) => !gapsAhead.value.some((gap) => gap.type === alert.type && gap.start === alert.at),
));

const gapsAheadSeconds = computed(() => gapsAhead.value.reduce((sum, gap) => sum + gap.seconds, 0));

function gapSummary(gap: LinearLogGapAhead): string {
    const parts = [
        secondsToHms(gap.seconds),
        gap.type === "short_hour" ? $gettext("before the Station ID") : $gettext("nothing planned"),
    ];
    if (gap.show) {
        parts.push(`${$gettext("in")} ${gap.show}`);
    }
    return parts.join(" · ");
}

// The GAP row the list jumps to, lit up for a moment once it is on screen.
const highlightGap = ref<number | null>(null);
let highlightTimer: number | null = null;

async function jumpToGap(gap: LinearLogGapAhead): Promise<void> {
    highlightGap.value = gap.start;
    await nextTick();
    document.getElementById(`log-gap-${gap.start}`)?.scrollIntoView({behavior: "smooth", block: "center"});

    if (highlightTimer !== null) window.clearTimeout(highlightTimer);
    highlightTimer = window.setTimeout(() => {
        highlightGap.value = null;
    }, 4000);
}

const replaceItem = ref<LinearLogItem | null>(null);
const replaceQuery = ref("");
const replaceOptions = ref<LinearLogMediaOption[]>([]);
let replaceTimer: number | null = null;

function openReplace(item: LinearLogItem): void {
    replaceItem.value = item;
    replaceQuery.value = "";
    replaceOptions.value = [];
}

function closeReplace(): void {
    replaceItem.value = null;
}

function onReplaceSearch(): void {
    if (replaceTimer !== null) window.clearTimeout(replaceTimer);
    replaceTimer = window.setTimeout(() => {
        const query = replaceQuery.value.trim();
        if (query.length < 2) {
            replaceOptions.value = [];
            return;
        }
        // setTimeout expects a void return, so the search is kicked off rather
        // than awaited here.
        void searchMedia(query).then((options) => {
            replaceOptions.value = options;
        });
    }, 250);
}

async function applyReplace(mediaId: number): Promise<void> {
    const item = replaceItem.value;
    if (!item?.log_entry_id) return;
    if (await editEntry(item.log_entry_id, "replace", {media_id: mediaId})) {
        closeReplace();
    }
}

function formatLength(seconds: number): string {
    const total = Math.round(seconds ?? 0);
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, "0")}`;
}

const pageTitle = computed(() => `${snapshotHours.value || hoursAhead.value}-${$gettext("Hour Playout Log")}`);
const searchQuery = ref("");

const columnOptions = [
    {key: "time", label: $gettext("Time")},
    {key: "title", label: $gettext("Title / Artist")},
    {key: "source", label: $gettext("Playlist / Source")},
    {key: "type", label: $gettext("Type")},
    {key: "rules", label: $gettext("Rules")},
    {key: "aired", label: $gettext("Aired at")},
    {key: "status", label: $gettext("Status")},
    {key: "edit", label: $gettext("Edit")},
    {key: "duration", label: $gettext("Duration")},
];
const visibleColumns = ref(["time", "title", "source", "type", "rules", "status", "edit", "duration"]);

const typeFilters = [
    {key: "programme", label: $gettext("Scheduled Program"), activeClass: "btn-primary"},
    {key: "music", label: $gettext("Music"), activeClass: "btn-success"},
    {key: "talk", label: $gettext("Talk"), activeClass: "btn-warning"},
    {key: "id", label: $gettext("Station ID"), activeClass: "btn-danger"},
    {key: "promo", label: $gettext("Promo"), activeClass: "btn-info"},
    {key: "jingle", label: $gettext("Jingle"), activeClass: "btn-secondary"},
    {key: "podcast", label: $gettext("Podcast"), activeClass: "btn-primary"},
    {key: "stream", label: $gettext("Stream"), activeClass: "btn-dark filter-on-dark"},
    {key: "request", label: $gettext("Request"), activeClass: "btn-outline-primary"},
    {key: "clock_wheel", label: $gettext("Clock Wheel"), activeClass: "btn-primary"},
];
const activeTypes = ref(typeFilters.map((item) => item.key));

function toggleType(key: string): void {
    activeTypes.value = activeTypes.value.includes(key)
        ? activeTypes.value.filter((item) => item !== key)
        : [...activeTypes.value, key];
}

function resolveType(item: LinearLogItem): string {
    if (item.source_type === "scheduled_programme" || item.media_type === "programme") return "programme";
    if (item.is_request) return "request";
    if (item.clock_wheel) return "clock_wheel";
    if (item.top_of_hour_legal_id || item.media_type === "id") return "id";
    if (item.autodj_custom_uri) return "stream";
    return item.media_type || "music";
}

// A dropped line stays in the log as a record of what was taken out and why
// (see log_note). It is listed, struck through, unless the operator hides it.
const showDropped = ref(true);

const filteredItems = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    return allItems.value.filter((item) => {
        if (item.log_status === "dropped" && !showDropped.value) return false;
        if (!activeTypes.value.includes(resolveType(item))) return false;
        if (!query) return true;

        return [
            item.title,
            item.artist,
            item.album,
            item.text,
            item.playlist,
            item.clock_wheel,
            item.autodj_custom_uri,
            ...(item.playlist_chain ?? []),
        ].filter(Boolean).join(" ").toLowerCase().includes(query);
    });
});

function secondsToHms(total: number): string {
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = Math.floor(total % 60);
    return hours > 0 ? `${hours}h ${minutes}m ${seconds}s` : `${minutes}m ${seconds}s`;
}

// What airs. A dropped line never does, and whatever refilled its slot is its
// own line, so counting both would double-count that time in the totals.
const airableItems = computed(() => filteredItems.value.filter((item) => item.log_status !== "dropped"));

const totalDurationFormatted = computed(() => secondsToHms(
    airableItems.value.reduce((sum, item) => sum + (item.duration ?? 0), 0),
));
const gapCount = computed(() => gaps.value.length);
const totalGapDuration = computed(() => secondsToHms(gaps.value.reduce((sum, gap) => sum + gap.duration, 0)));
// What is on the air, and what follows it, is a fact about the station, not
// about the operator's current search box or type filter -- both read from the
// unfiltered log so narrowing the table can never blank the badges out.
const nextUpItem = computed(
    () => allItems.value.find((item) => (item.played_at ?? 0) >= nowTs.value && item.is_live_queue) ?? null,
);
function displayTitle(item: LinearLogItem): string {
    return item.title || item.text || $gettext("Untitled");
}

// Station time, like the station clock and Upcoming Song Queue, not the viewer's PC.
const {formatTimestampAsTime, formatTimestampAsDateTime, timestampToDateTime} = useStationDateTimeFormatter();

const zoneLabel = computed(() => timestampToDateTime(nowTs.value).offsetNameShort ?? "");

function formatTime(timestamp: number | null): string {
    if (!timestamp) return "-";
    return formatTimestampAsTime(timestamp, {
        hour: "numeric",
        minute: "2-digit",
        second: "2-digit",
    });
}

function formatGapTime(timestamp: number): string {
    return formatTimestampAsDateTime(timestamp, {
        weekday: "short",
        hour: "numeric",
        minute: "2-digit",
        second: "2-digit",
    });
}

function formatDateTime(timestamp: number): string {
    return formatTimestampAsDateTime(timestamp, {
        weekday: "short",
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
    });
}

const coverageWarning = computed(() => {
    if (!coverageStart.value || !coverageEnd.value || !builtAt.value) return "";

    const requestedEnd = coverageStart.value + (snapshotHours.value * 3600);
    const shortBy = requestedEnd - coverageEnd.value;
    if (shortBy <= 300) return "";

    return `${$gettext("Missing approximately")} ${secondsToHms(shortBy)}.`;
});

function isTopOfHourId(item: LinearLogItem): boolean {
    return item.top_of_hour_legal_id || item.media_type === "id";
}

// Over/under per hour, as on an FM log: how far the last line before the
// Station ID runs past it, or ends short of it. Read from the whole log, not
// the filtered table, so a search or a type filter cannot change the answer.
const overUnderByHour = computed(() => {
    const airable = allItems.value
        .filter((item) => item.log_status !== "dropped" && !!item.played_at)
        .sort((a, b) => (a.played_at ?? 0) - (b.played_at ?? 0));

    const byHour = new Map<number, LinearLogItem[]>();
    for (const item of airable) {
        const hour = Math.floor((item.played_at ?? 0) / 3600) * 3600;
        byHour.set(hour, [...(byHour.get(hour) ?? []), item]);
    }

    const logEnds = airable.reduce((end, item) => Math.max(end, (item.played_at ?? 0) + (item.duration ?? 0)), 0);

    const result = new Map<number, number>();
    for (const [hour, items] of byHour) {
        const hourEnd = hour + 3600;
        // An hour that has aired is history, and one the log stops inside is
        // not short, only unplanned.
        if (hourEnd <= nowTs.value || logEnds < hourEnd) continue;

        // The ID that closes this hour starts a second before the next one.
        const closingId = items.find((item) => isTopOfHourId(item) && (item.played_at ?? 0) >= hourEnd - 120);
        const target = closingId?.played_at ?? hourEnd;

        const lines = items.filter((item) => item !== closingId && (item.played_at ?? 0) < target);
        if (lines.length === 0) continue;

        const lastEnd = Math.max(...lines.map((item) => (item.played_at ?? 0) + (item.duration ?? 0)));
        result.set(hour, Math.round(lastEnd - target));
    }
    return result;
});

const hourGroups = computed<LinearLogHourGroup[]>(() => {
    const groups = new Map<number, LinearLogItem[]>();
    for (const item of filteredItems.value) {
        const timestamp = item.played_at ?? 0;
        const hour = Math.floor(timestamp / 3600) * 3600;
        const items = groups.get(hour) ?? [];
        items.push(item);
        groups.set(hour, items);
    }

    // A gap is a fact about the log, so its row shows whatever is filtered out,
    // even in an hour the filters leave empty.
    const gapsByHour = new Map<number, LinearLogGapAhead[]>();
    for (const gap of gapsAhead.value) {
        const hour = Math.floor(gap.start / 3600) * 3600;
        gapsByHour.set(hour, [...(gapsByHour.get(hour) ?? []), gap]);
        if (!groups.has(hour)) groups.set(hour, []);
    }

    const currentHour = Math.floor(nowTs.value / 3600) * 3600;
    return [...groups.entries()].sort(([a], [b]) => a - b).map(([epochHour, items]) => {
        // A dropped line is listed ahead of the line that took its slot.
        const sorted = [...items].sort((a, b) => (a.played_at ?? 0) - (b.played_at ?? 0)
            || Number(b.log_status === "dropped") - Number(a.log_status === "dropped"));
        const airable = sorted.filter((item) => item.log_status !== "dropped");
        const total = airable.reduce((sum, item) => sum + (item.duration ?? 0), 0);
        const lockable = sorted.filter((item) => !!item.log_entry_id
            && ["planned", "queued"].includes(item.log_status ?? ""));

        const gapsBefore: Record<string, LinearLogGapAhead[]> = {};
        const gapsAfter: LinearLogGapAhead[] = [];
        for (const gap of gapsByHour.get(epochHour) ?? []) {
            const next = sorted.find((item) => (item.played_at ?? 0) >= gap.start);
            if (next) {
                gapsBefore[next.id] = [...(gapsBefore[next.id] ?? []), gap];
            } else {
                gapsAfter.push(gap);
            }
        }

        return {
            epochHour,
            label: formatDateTime(epochHour),
            isCurrent: epochHour === currentHour,
            items: sorted,
            gapsBefore,
            gapsAfter,
            overUnder: overUnderByHour.value.get(epochHour) ?? null,
            airableCount: airable.length,
            totalDurationFormatted: secondsToHms(total),
            hasId: sorted.some((item) => item.top_of_hour_legal_id || item.media_type === "id"),
            lockableIds: lockable.map((item) => item.log_entry_id as number),
            allLocked: lockable.length > 0 && lockable.every((item) => item.is_locked),
        };
    });
});
</script>

<style scoped>
.rules-bar{padding:.6rem 1rem;border-top:1px solid rgba(var(--bs-body-color-rgb),.08);background:rgba(var(--bs-body-color-rgb),.02)}
.rules-bar .form-check-label{font-size:.82rem}
.replace-backdrop{position:fixed;inset:0;z-index:1080;display:flex;align-items:flex-start;justify-content:center;padding:10vh 16px 16px;background:rgba(0,0,0,.45)}
.replace-dialog{width:100%;max-width:520px}
.replace-results{max-height:50vh;overflow-y:auto}
.linear-log-page{max-width:1400px;margin:0 auto;color:var(--bs-body-color)}
.linear-log-card{overflow:hidden;border:1px solid var(--bs-border-color);border-radius:.8rem;background:var(--bs-body-bg);box-shadow:0 .3rem 1rem rgba(0,0,0,.07)}
.linear-log-card.is-disabled .linear-log-header{background:linear-gradient(90deg,#5c636a 0%,#6c757d 100%)}
.linear-log-card.is-disabled .disabled-banner ~ *{opacity:.45;filter:grayscale(1)}
.disabled-banner{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;border-left:6px solid var(--bs-danger)!important}
.state-badge{font-size:.8rem;letter-spacing:.05em;padding:.4em .7em}
.linear-log-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:1rem 1.15rem;color:#fff;background:linear-gradient(90deg,#0a6fc2 0%,#2196f3 100%)}
.linear-log-header h1{margin:0;color:#fff;font-size:1.35rem;font-weight:750}
.linear-log-header p{margin:.15rem 0 0;color:rgba(255,255,255,.9);font-size:.83rem}
.hours-select{width:auto;min-width:110px}
.build-status{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;padding:.65rem 1rem;border-bottom:1px solid var(--bs-border-color);background:var(--bs-primary-bg-subtle);font-size:.85rem}
.filter-bar{padding:.75rem 1rem;border-bottom:1px solid var(--bs-border-color);background:color-mix(in srgb,var(--bs-body-bg) 94%,var(--bs-secondary-bg) 6%)}
.filter-label{font-size:.8rem;font-weight:700}
.search-box{width:230px}
.stats-bar{display:flex;flex-wrap:wrap;gap:1.2rem;padding:.65rem 1rem;border-bottom:1px solid var(--bs-border-color);background:color-mix(in srgb,var(--bs-secondary-bg) 65%,var(--bs-body-bg));font-size:.8rem}
.on-air-indicator{display:inline-flex;align-items:center;gap:.4rem}
.on-air-badge{display:inline-block;padding:.15rem .5rem;border-radius:.25rem;background:var(--bs-danger);color:#fff;font-weight:700;font-size:.72rem;letter-spacing:.04em;animation:on-air-flash 1.1s ease-in-out infinite}
.next-up-badge{display:inline-block;padding:.15rem .5rem;border-radius:.25rem;background:var(--bs-secondary-bg);color:var(--bs-secondary-color);border:1px solid var(--bs-border-color);font-weight:700;font-size:.72rem;letter-spacing:.04em;margin-right:.4rem}
@keyframes on-air-flash{0%,100%{opacity:1}50%{opacity:.35}}
@media (prefers-reduced-motion: reduce){.on-air-badge{animation:none}}
.filter-on-dark{border:1px solid var(--bs-secondary-color);box-shadow:inset 0 0 0 1px rgba(255,255,255,.28)}
.log-alert{padding:.6rem 1rem;font-size:.86rem}
.gap-list{margin:.3rem 0;padding-left:1.1rem}
.gap-jump{padding:0 .35rem 0 0;font-size:inherit;font-weight:600;vertical-align:baseline}
.block-lock select{width:auto;max-width:11rem}
.coverage-warning{padding:.6rem 1rem;border-bottom:1px solid var(--bs-warning-border-subtle);background:var(--bs-warning-bg-subtle);color:var(--bs-warning-text-emphasis);font-size:.82rem}
.loading-state,.empty-state{padding:4rem 1.5rem;text-align:center}
.empty-state p{max-width:760px;margin:.5rem auto 0;color:var(--bs-secondary-color)}
.empty-state h2{font-size:1.1rem}
.linear-log-footer{padding:.7rem 1rem;border-top:1px solid var(--bs-border-color);background:var(--bs-tertiary-bg);color:var(--bs-secondary-color);font-size:.76rem}
@media(max-width:767px){.linear-log-header{align-items:flex-start;flex-direction:column}.search-box{width:100%}}
</style>