<template>
    <div class="calendar-schedule-editor">
        <div class="row g-4 schedule-workspace">
            <div class="col-xl-9">
                <div class="step-heading step-heading-time mb-3">
                    <span class="step-icon step-icon-time"><icon-ic-event /></span>
                    <span>
                        <strong>{{ $gettext('2. When should this playlist play?') }}</strong>
                        <small>{{ $gettext('Set the days, times, date range, timing mode and whether the playlist repeats inside its window.') }}</small>
                    </span>
                </div>

                <form-markup
                    v-if="scheduleItems.length === 0"
                    id="no_scheduled_entries"
                >
                    <template #label>{{ $gettext('Not Scheduled') }}</template>
                    <p class="mb-0">
                        {{ $gettext('This playlist currently has no scheduled times and may play at any time while enabled.') }}
                    </p>
                </form-markup>

                <playlists-form-schedule-row
                    v-for="(row, index) in scheduleItems"
                    :key="index"
                    v-model:row="scheduleItems[index]"
                    :index="index"
                    :source="source"
                    :is-playlist-group="form.source === 'playlists'"
                    :highlight-label="highlightRow && scheduleItems[index] === highlightRow ? highlightLabel : null"
                    @remove="remove(index)"
                    @timing="applyTimingBundle"
                >
                    <template
                        v-if="index === 0"
                        #aside
                    >
                        <calendar-advanced-settings :source="source" />
                    </template>
                </playlists-form-schedule-row>

                <div class="buttons mb-4">
                    <button
                        type="button"
                        class="btn btn-primary add-schedule-button"
                        @click="add"
                    >
                        <icon-ic-add />
                        <span>{{ $gettext('Add Schedule Item') }}</span>
                    </button>
                </div>

                <calendar-advanced-settings
                    v-if="scheduleItems.length === 0"
                    :source="source"
                />
            </div>

            <aside class="col-xl-3 schedule-sidebar">
                <div class="summary-card mb-3">
                    <div class="summary-head">
                        <strong>{{ $gettext('Schedule summary') }}</strong>
                        <span class="summary-pill">{{ countLabel }}</span>
                    </div>
                    <div class="summary-sec">
                        <div class="summary-sec-title">
                            {{ $gettext('When') }}
                        </div>
                        <div
                            v-for="(line, i) in whenLines"
                            :key="i"
                            class="summary-when"
                        >
                            {{ line.title }}
                            <small>{{ line.detail }}</small>
                        </div>
                        <div
                            v-if="whenLines.length === 0"
                            class="summary-when"
                        >
                            {{ $gettext('No times set') }}
                            <small>{{ $gettext('Plays all day while enabled') }}</small>
                        </div>
                    </div>
                    <div class="summary-sec">
                        <div class="summary-sec-title">
                            {{ $gettext('How it plays') }}
                        </div>
                        <div class="summary-row">
                            <span>{{ $gettext('Style') }}</span>
                            <span>{{ styleLabel }}</span>
                        </div>
                        <div class="summary-row">
                            <span>{{ $gettext('Start') }}</span>
                            <span>{{ startLabel }}</span>
                        </div>
                        <div class="summary-row">
                            <span>{{ $gettext('End') }}</span>
                            <span>{{ endLabel }}</span>
                        </div>
                        <div class="summary-row">
                            <span>{{ $gettext('Timing') }}</span>
                            <span>{{ timingLabel }}</span>
                        </div>
                        <div
                            v-if="source === 'playlist' && scheduleItems.length > 0"
                            class="summary-row"
                        >
                            <span>{{ $gettext('In each window') }}</span>
                            <span>{{ scheduleItems[0].loop_once ? $gettext('Plays once') : $gettext('Repeats') }}</span>
                        </div>
                    </div>
                </div>

                <div class="side-card side-card-presets mb-3">
                    <div class="side-card-heading">
                        <span class="side-card-icon icon-presets"><icon-ic-settings /></span>
                        <h3>{{ $gettext('Quick setup — how it plays') }}</h3>
                    </div>
                    <p class="side-card-intro mb-3">
                        {{ $gettext('Pick one. It sets the start, end and timing for every time below.') }}
                    </p>

                    <button
                        type="button"
                        class="preset-button preset-show"
                        :class="{'is-active': activePreset === 'show'}"
                        :disabled="scheduleItems.length === 0"
                        @click="applyPreset('show')"
                    >
                        <span class="preset-icon"><icon-ic-play-arrow /></span>
                        <span class="preset-copy">
                            <strong>{{ $gettext('Scheduled Show / Program') }}</strong>
                            <small>{{ $gettext('Strict exact start • Plays once • Stops at boundary') }}</small>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="preset-button preset-music"
                        :class="{'is-active': activePreset === 'music'}"
                        :disabled="scheduleItems.length === 0"
                        @click="applyPreset('music')"
                    >
                        <span class="preset-icon"><icon-ic-music-note /></span>
                        <span class="preset-copy">
                            <strong>{{ $gettext('Music Rotation Block') }}</strong>
                            <small>{{ $gettext('Waits for current song • Repeats • Flexible end') }}</small>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="preset-button preset-news"
                        :class="{'is-active': activePreset === 'news'}"
                        :disabled="scheduleItems.length === 0"
                        @click="applyPreset('news')"
                    >
                        <span class="preset-icon"><icon-ic-warning /></span>
                        <span class="preset-copy">
                            <strong>{{ $gettext('News / Alert / Priority') }}</strong>
                            <small>{{ $gettext('Starts on time • Overrides requests') }}</small>
                        </span>
                    </button>
                </div>

                <div class="side-card side-card-reminder mb-3">
                    <div class="side-card-heading">
                        <span class="side-card-icon icon-reminder"><icon-ic-info /></span>
                        <h3>{{ $gettext('Important Reminder') }}</h3>
                    </div>
                    <p class="mb-2">
                        {{ $gettext('An enabled playlist with no schedule can still be selected and played all day according to its playlist type and settings.') }}
                    </p>
                    <p class="mb-0">
                        {{ $gettext('If you do not want a playlist to air, either give it a schedule or disable it.') }}
                    </p>
                </div>

                <div class="side-card side-card-terms">
                    <div class="side-card-heading">
                        <span class="side-card-icon icon-terms"><icon-ic-help /></span>
                        <h3>{{ $gettext('Terminology Tips') }}</h3>
                    </div>
                    <ul class="mb-0 ps-3">
                        <li>{{ $gettext('Flexible = waits for the current song and lets the current item finish.') }}</li>
                        <li>{{ $gettext('Strict = exact wall-clock start with a firm scheduled end.') }}</li>
                        <li>{{ $gettext('Program = normal strict show start.') }}</li>
                        <li>{{ $gettext('Priority = strict start plus listener-request priority.') }}</li>
                        <li>{{ $gettext('Play once per block = does not repeat within the time slot.') }}</li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</template>

<script setup lang="ts">
// Calendar event editor: the New Schedule for playlists and web streams —
// schedule windows, start/end behavior, presets and summary. (The Playlist
// page itself uses the Classic Schedule.)
import {computed} from "vue";
import {DateTime} from "luxon";
import {storeToRefs} from "pinia";
import PlaylistsFormScheduleRow from "~/components/Stations/Common/CalendarScheduleRow.vue";
import CalendarAdvancedSettings from "~/components/Stations/Common/CalendarAdvancedSettings.vue";
import FormMarkup from "~/components/Form/FormMarkup.vue";
import IconIcAdd from "~icons/ic/baseline-add";
import IconIcEvent from "~icons/ic/baseline-event";
import IconIcHelp from "~icons/ic/baseline-help";
import IconIcInfo from "~icons/ic/baseline-info";
import IconIcMusicNote from "~icons/ic/baseline-music-note";
import IconIcPlayArrow from "~icons/ic/baseline-play-arrow";
import IconIcSettings from "~icons/ic/baseline-settings";
import IconIcWarning from "~icons/ic/baseline-warning";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form.ts";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();
const {form} = storeToRefs(useStationsPlaylistsForm());

withDefaults(defineProps<{
    // The window the calendar event was opened from, and the tag to show on it.
    highlightRow?: Record<string, any> | null,
    highlightLabel?: string | null,
    // Calendar source: a song playlist or a web stream (also a playlist).
    source?: 'playlist' | 'web_stream',
}>(), {
    highlightRow: null,
    highlightLabel: null,
    source: 'playlist',
});

const scheduleItems = defineModel<Array<any>>('scheduleItems', {
    default: () => []
});

const setBackendOption = (option: string, enabled: boolean) => {
    const existing = Array.isArray(form.value.backend_options)
        ? form.value.backend_options.filter((item: string) => item !== option)
        : [];

    if (enabled) {
        existing.push(option);
    }

    form.value.backend_options = existing;
};

const applyTimingBundle = (mode: 'flexible' | 'strict') => {
    if (scheduleItems.value.length === 0) {
        return;
    }

    if (mode === 'strict') {
        setBackendOption('interrupt', true);
        setBackendOption('allow_overrun', false);
        if (!hasOption('prioritize')) {
            setBackendOption('prioritize', false);
        }
        return;
    }

    setBackendOption('interrupt', false);
    setBackendOption('prioritize', false);
    setBackendOption('allow_overrun', true);
};

const effectiveTimingMode = computed<'flexible' | 'strict' | 'mixed'>(() => {
    if (scheduleItems.value.length === 0) {
        return 'flexible';
    }

    const strictCount = scheduleItems.value.filter((item) => Boolean(item.strict_start)).length;
    if (strictCount === 0) {
        return 'flexible';
    }
    if (strictCount === scheduleItems.value.length) {
        return 'strict';
    }
    return 'mixed';
});

const applyPreset = (preset: 'show' | 'music' | 'news') => {
    if (scheduleItems.value.length === 0) {
        return;
    }

    if (preset === 'show') {
        setBackendOption('interrupt', true);
        setBackendOption('prioritize', false);
        setBackendOption('allow_overrun', false);
        scheduleItems.value.forEach((item) => {
            item.loop_once = true;
            item.strict_start = true;
            item.prevent_requests = false;
        });
        return;
    }

    if (preset === 'music') {
        setBackendOption('interrupt', false);
        setBackendOption('prioritize', false);
        setBackendOption('allow_overrun', true);
        scheduleItems.value.forEach((item) => {
            item.loop_once = false;
            item.strict_start = false;
            item.prevent_requests = false;
        });
        return;
    }

    setBackendOption('interrupt', true);
    setBackendOption('prioritize', true);
    setBackendOption('allow_overrun', false);
    scheduleItems.value.forEach((item) => {
        item.loop_once = true;
        item.strict_start = true;
        item.prevent_requests = true;
    });
};

const add = () => {
    scheduleItems.value.push({
        start_time: null,
        end_time: null,
        start_date: null,
        end_date: null,
        days: [],
        loop_once: false,
        prevent_requests: false,
        reset_queue_at_start: false,
        reset_queue_recursive: false,
        strict_start: false,
        recurrence_type: 'weekly',
        recurrence_interval: 1,
        recurrence_monthly_pattern: null,
        recurrence_monthly_day: null,
        recurrence_monthly_week: null,
        recurrence_monthly_day_of_week: null,
        recurrence_end_type: 'never',
        recurrence_end_after: null,
        recurrence_end_date: null
    });
};

const remove = (index: number) => {
    scheduleItems.value.splice(index, 1);
};

const hasOption = (option: string): boolean => (
    Array.isArray(form.value.backend_options) && form.value.backend_options.includes(option)
);

// The playlist's start/end behavior (interrupt, overrun, priority) changes only
// when the user picks a timing mode or a preset. Applied on open, or when a row
// was added, it silently rewrote a show's settings on the next Save.

const formatTime = (timeCode: number | string | null | undefined): string => {
    if (timeCode === null || timeCode === undefined || timeCode === '') {
        return '—';
    }

    const padded = String(timeCode).padStart(4, '0');
    const hour24 = Number(padded.slice(0, 2));
    const minute = padded.slice(2, 4);
    const suffix = hour24 >= 12 ? 'PM' : 'AM';
    const hour12 = hour24 % 12 || 12;
    return `${hour12}:${minute} ${suffix}`;
};

const dayShort: Record<number, string> = {
    1: $gettext('Mon'),
    2: $gettext('Tue'),
    3: $gettext('Wed'),
    4: $gettext('Thu'),
    5: $gettext('Fri'),
    6: $gettext('Sat'),
    7: $gettext('Sun'),
};

const formatDays = (days: number[]): string => {
    const sorted = Array.isArray(days) ? [...days].sort((a, b) => a - b) : [];
    const key = sorted.join(',');
    if (key === '1,2,3,4,5') {
        return $gettext('Mon–Fri');
    }
    if (key === '6,7') {
        return $gettext('Sat–Sun');
    }
    if (sorted.length === 7) {
        return $gettext('Every day');
    }
    if (sorted.length === 0) {
        return $gettext('No days set');
    }
    return sorted.map((day) => dayShort[day] ?? String(day)).join(', ');
};

const formatDate = (date: string | null, format = 'LLL d, yyyy'): string => (
    date ? DateTime.fromISO(date).toFormat(format) : '—'
);

const ordinal = (n: number): string => {
    const s = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
};

const repeatsLabel = (item: Record<string, any>): string => {
    switch (item.recurrence_type) {
        case 'biweekly':
            return $gettext('Repeats every 2 weeks');
        case 'custom':
            return $gettext('Repeats every %{n} weeks', {n: String(item.recurrence_interval ?? 1)});
        case 'monthly':
            return $gettext('Repeats monthly');
        default:
            return $gettext('Repeats weekly');
    }
};

const whenLines = computed(() => scheduleItems.value.map((item) => {
    const times = `${formatTime(item.start_time)} – ${formatTime(item.end_time)}`;

    if (item.start_date && item.start_date === item.end_date) {
        return {
            title: `${$gettext('One time')} · ${formatDate(item.start_date, 'ccc, LLL d, yyyy')}`,
            detail: `${times} · ${$gettext('Does not repeat')}`,
        };
    }

    let days = formatDays(item.days);
    if (item.recurrence_type === 'monthly' && item.recurrence_monthly_pattern === 'date') {
        days = item.recurrence_monthly_day
            ? $gettext('Monthly on the %{day}', {day: ordinal(Number(item.recurrence_monthly_day))})
            : $gettext('Monthly · pick a day');
    } else if (item.recurrence_type === 'monthly') {
        const week = Number(item.recurrence_monthly_week);
        const weekText = week === 5 ? $gettext('last') : (week >= 1 && week <= 4 ? ordinal(week) : $gettext('(pick week)'));
        const dayText = Array.isArray(item.days) && item.days.length > 0 ? formatDays(item.days) : $gettext('(pick weekday)');
        days = $gettext('Monthly on the %{week} %{days}', {week: weekText, days: dayText});
    }

    const until = item.recurrence_end_type === 'after'
        ? $gettext('%{n} times from %{date}', {n: String(item.recurrence_end_after ?? '?'), date: formatDate(item.start_date)})
        : `${formatDate(item.start_date)} → ${formatDate(item.end_date)}`;

    return {
        title: `${days} · ${times}`,
        detail: `${until} · ${repeatsLabel(item)}`,
    };
}));

const countLabel = computed(() => {
    const count = scheduleItems.value.length;
    if (count === 0) {
        return $gettext('Not scheduled');
    }
    return count === 1 ? $gettext('1 time') : $gettext('%{n} times', {n: String(count)});
});

// The Quick setup preset that matches the playlist's start behavior.
const activePreset = computed((): 'show' | 'music' | 'news' => {
    if (!hasOption('interrupt')) {
        return 'music';
    }
    return hasOption('prioritize') ? 'news' : 'show';
});

const styleLabel = computed(() => ({
    show: $gettext('Scheduled Show / Program'),
    music: $gettext('Music Rotation Block'),
    news: $gettext('News / Alert / Priority'),
})[activePreset.value]);

const startLabel = computed(() => ({
    show: $gettext('On time'),
    music: $gettext('After current song'),
    news: $gettext('On time, top priority'),
})[activePreset.value]);

const endLabel = computed(() => (
    hasOption('allow_overrun') ? $gettext('After current song') : $gettext('At end time')
));

const timingLabel = computed(() => ({
    strict: $gettext('Strict'),
    flexible: $gettext('Flexible'),
    mixed: $gettext('Mixed'),
})[effectiveTimingMode.value]);
</script>

<style scoped>
.schedule-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.05rem 1.2rem;
    border-radius: .75rem;
    background: linear-gradient(90deg, #0d6efd, #1688f8);
    color: #fff;
    box-shadow: 0 .2rem .65rem rgba(13, 110, 253, .18);
}

.schedule-hero p {
    color: rgba(255, 255, 255, .9);
    font-size: .92rem;
    line-height: 1.4;
}

.schedule-workspace {
    align-items: flex-start;
}

.schedule-overview {
    padding: 1rem 1.1rem;
    border: 1px solid;
    border-radius: .7rem;
}

.schedule-overview strong,
.schedule-overview span,
.step-heading strong,
.step-heading small,
.preset-copy strong,
.preset-copy small {
    display: block;
}

.schedule-overview strong {
    font-size: 1rem;
}

.schedule-overview span {
    margin-top: .2rem;
    font-size: .88rem;
    line-height: 1.45;
}

.schedule-overview.is-scheduled {
    border-color: rgba(13, 110, 253, .55);
    background: rgba(13, 110, 253, .1);
}

.schedule-overview.is-unscheduled {
    border-color: rgba(245, 158, 11, .55);
    background: rgba(245, 158, 11, .1);
}

.step-heading {
    display: flex;
    align-items: center;
    gap: .85rem;
    padding: .9rem 1rem;
    border-radius: .7rem;
}

.step-heading-time {
    border: 1px solid rgba(13, 110, 253, .2);
    background: rgba(13, 110, 253, .12);
    color: #1688f8;
}

.step-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.7rem;
    height: 2.7rem;
    flex: 0 0 2.7rem;
    border-radius: .55rem;
    color: #fff;
    font-size: 1.55rem;
}

.step-icon-time {
    background: #0d6efd;
}

.step-heading strong {
    font-size: 1.08rem;
}

.step-heading small {
    margin-top: .15rem;
    color: var(--bs-secondary-color);
    font-size: .84rem;
}

.add-schedule-button {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    font-weight: 600;
}

.schedule-sidebar {
    position: sticky;
    top: 1rem;
}

.side-card {
    padding: 1rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: var(--bs-tertiary-bg);
    font-size: .92rem;
    line-height: 1.48;
}

.side-card-heading {
    display: flex;
    align-items: center;
    gap: .7rem;
    margin-bottom: .65rem;
}

.side-card-heading h3 {
    margin: 0;
    font-size: 1.03rem;
    font-weight: 750;
    line-height: 1.2;
}

.side-card-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.7rem;
    height: 2.7rem;
    flex: 0 0 2.7rem;
    border-radius: 50%;
    font-size: 1.55rem;
}

.icon-reminder,
.icon-terms {
    background: rgba(13, 110, 253, .14);
    color: #2688ff;
}

.icon-presets {
    background: rgba(245, 158, 11, .18);
    color: #f59e0b;
}

.side-card-reminder h3,
.side-card-terms h3 {
    color: #2688ff;
}

.side-card-presets {
    border-color: rgba(245, 158, 11, .52);
    background: rgba(245, 158, 11, .10);
}

.side-card-presets h3 {
    color: #f59e0b;
}

.side-card-reminder,
.side-card-terms {
    border-color: rgba(13, 110, 253, .32);
    background: rgba(13, 110, 253, .055);
}

.side-card-intro {
    color: var(--bs-secondary-color);
    font-size: .86rem;
    line-height: 1.4;
}

.preset-button {
    display: flex;
    align-items: center;
    gap: .7rem;
    width: 100%;
    min-height: 4.25rem;
    padding: .72rem .78rem;
    margin-bottom: .6rem;
    border: 0;
    border-radius: .58rem;
    color: #fff;
    text-align: left;
    box-shadow: 0 .15rem .4rem rgba(0, 0, 0, .12);
}

.preset-button:last-child {
    margin-bottom: 0;
}

.preset-button:disabled {
    opacity: .45;
    cursor: not-allowed;
}

.preset-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    flex: 0 0 2rem;
    font-size: 1.6rem;
}

.preset-copy {
    min-width: 0;
}

.preset-copy strong {
    font-size: .9rem;
    line-height: 1.2;
}

.preset-copy small {
    margin-top: .18rem;
    color: rgba(255, 255, 255, .9);
    font-size: .75rem;
    line-height: 1.25;
}

.preset-show {
    background: linear-gradient(135deg, #198754, #11a55a);
}

.preset-music {
    background: linear-gradient(135deg, #0d6efd, #1688f8);
}

.preset-button.is-active {
    outline: 3px solid #fff;
    outline-offset: 2px;
}

.summary-card {
    overflow: hidden;
    border: 1px solid var(--bs-border-color);
    border-radius: .6rem;
    background: var(--bs-tertiary-bg);
}

.summary-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .65rem .9rem;
    border-bottom: 1px solid var(--bs-border-color);
}

.summary-head strong {
    color: var(--bs-emphasis-color);
    font-size: .8rem;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.summary-pill {
    padding: .15rem .5rem;
    border-radius: 999px;
    background: rgba(25, 135, 84, .18);
    color: #4fd18b;
    font-size: .7rem;
    font-weight: 700;
}

.summary-sec {
    padding: .55rem .9rem;
    border-bottom: 1px solid var(--bs-border-color);
}

.summary-sec:last-child {
    border-bottom: 0;
}

.summary-sec-title {
    margin-bottom: .3rem;
    color: var(--bs-secondary-color);
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.summary-when {
    padding: .12rem 0;
    color: var(--bs-emphasis-color);
    font-size: .82rem;
    font-weight: 600;
}

.summary-when small {
    display: block;
    color: var(--bs-secondary-color);
    font-size: .76rem;
    font-weight: 400;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    gap: .75rem;
    padding: .12rem 0;
    font-size: .82rem;
}

.summary-row span:first-child {
    color: var(--bs-secondary-color);
    white-space: nowrap;
}

.summary-row span:last-child {
    color: var(--bs-emphasis-color);
    font-weight: 600;
    text-align: right;
}

.preset-news {
    background: linear-gradient(135deg, #7c3aed, #9333ea);
}

.side-card-terms li + li {
    margin-top: .35rem;
}





@media (max-width: 1199.98px) {
    .schedule-sidebar {
        position: static;
        margin-top: .5rem;
    }
}
</style>
