<template>
    <tab :label="$gettext('Schedule & Playback')">
        <div class="schedule-hero mb-4">
            <div>
                <h2 class="h4 mb-1">{{ $gettext('Schedule & Playback') }}</h2>
                <p class="mb-0">{{ $gettext('Set when this playlist airs and how it should behave at the beginning and end of each scheduled block.') }}</p>
            </div>
        </div>

        <div
            class="row g-4 schedule-workspace"
            :class="timingModeClass"
            @change="onWorkspaceChange"
        >
            <div class="col-xl-9">
                <div
                    class="schedule-overview mb-4"
                    :class="scheduleItems.length > 0 ? 'is-scheduled' : 'is-unscheduled'"
                >
                    <strong>
                        {{ scheduleItems.length > 0 ? $gettext('Scheduled Playlist') : $gettext('No Schedule Set') }}
                    </strong>
                    <span v-if="scheduleItems.length > 0">
                        {{ $gettext('This playlist is restricted to the scheduled windows below. Its start and end behavior are configured on this same page.') }}
                    </span>
                    <span v-else>
                        {{ $gettext('Important: an enabled playlist with no schedule can be selected all day. Add a scheduled time if this content should only air at specific times.') }}
                    </span>
                </div>

                <div class="step-heading step-heading-time mb-3">
                    <span class="step-icon step-icon-time"><icon-ic-event /></span>
                    <span>
                        <strong>{{ $gettext('1. When should this playlist play?') }}</strong>
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
                    @remove="remove(index)"
                />

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

                <form-playout-rules
                    v-if="!form.is_smart_block"
                    :has-schedule="scheduleItems.length > 0"
                />

                <div class="schedule-summary mt-4">
                    <span class="summary-check"><icon-ic-check-circle /></span>
                    <span>
                        <strong>{{ $gettext('Schedule Summary') }}</strong>
                        <small v-if="scheduleItems.length > 0">{{ scheduleSummary }}</small>
                        <small v-else>{{ $gettext('No scheduled window is configured. This enabled playlist can be eligible all day.') }}</small>
                    </span>
                </div>
            </div>

            <aside class="col-xl-3 schedule-sidebar">
                <div class="side-card side-card-help mb-3">
                    <div class="side-card-heading">
                        <span class="side-card-icon icon-help"><icon-ic-help /></span>
                        <h3>{{ $gettext('Need Help?') }}</h3>
                    </div>
                    <p class="mb-0">
                        {{ $gettext('This simplified editor puts the most important settings in one place. Start with the schedule, then choose how playback begins and ends.') }}
                    </p>
                </div>

                <div class="side-card side-card-presets mb-3">
                    <div class="side-card-heading">
                        <span class="side-card-icon icon-presets"><icon-ic-settings /></span>
                        <h3>{{ $gettext('Quick Setup Presets') }}</h3>
                    </div>
                    <p class="side-card-intro mb-3">
                        {{ $gettext('Use a preset to quickly configure common playlist types.') }}
                    </p>

                    <button
                        type="button"
                        class="preset-button preset-show"
                        :disabled="scheduleItems.length === 0"
                        @click="applyPreset('show')"
                    >
                        <span class="preset-icon"><icon-ic-play-arrow /></span>
                        <span class="preset-copy">
                            <strong>{{ $gettext('Scheduled Show / Programme') }}</strong>
                            <small>{{ $gettext('Strict exact start • Plays once • Stops at boundary') }}</small>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="preset-button preset-music"
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
                        <li>{{ $gettext('Programme = normal strict show start.') }}</li>
                        <li>{{ $gettext('Priority = strict start plus listener-request priority.') }}</li>
                        <li>{{ $gettext('Play once per block = does not repeat within the time slot.') }}</li>
                    </ul>
                </div>
            </aside>
        </div>
    </tab>
</template>

<script setup lang="ts">
import {computed, watch} from "vue";
import {storeToRefs} from "pinia";
import PlaylistsFormScheduleRow from "~/components/Stations/Playlists/Form/ScheduleRow.vue";
import FormPlayoutRules from "~/components/Stations/Playlists/Form/PlayoutRules.vue";
import FormMarkup from "~/components/Form/FormMarkup.vue";
import Tab from "~/components/Common/Tab.vue";
import IconIcAdd from "~icons/ic/baseline-add";
import IconIcCheckCircle from "~icons/ic/baseline-check-circle";
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

const timingModeClass = computed(() => `timing-${effectiveTimingMode.value}`);

const onWorkspaceChange = (event: Event) => {
    const target = event.target as HTMLInputElement | null;
    if (!target || target.type !== 'radio') {
        return;
    }

    if (target.id.startsWith('scheduling_flexible_')) {
        applyTimingBundle('flexible');
    } else if (target.id.startsWith('scheduling_strict_')) {
        applyTimingBundle('strict');
    }
};

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
    applyTimingBundle('flexible');
};

const remove = (index: number) => {
    scheduleItems.value.splice(index, 1);
};

const hasOption = (option: string): boolean => (
    Array.isArray(form.value.backend_options) && form.value.backend_options.includes(option)
);

watch(
    effectiveTimingMode,
    (mode) => {
        if (mode === 'flexible' || mode === 'strict') {
            applyTimingBundle(mode);
        }
    },
    {immediate: true}
);

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

const formatDays = (days: number[]): string => {
    if (!Array.isArray(days) || days.length === 0) {
        return $gettext('Any day');
    }

    const labels: Record<number, string> = {
        1: $gettext('Mon'),
        2: $gettext('Tue'),
        3: $gettext('Wed'),
        4: $gettext('Thu'),
        5: $gettext('Fri'),
        6: $gettext('Sat'),
        7: $gettext('Sun'),
    };

    return days.map((day) => labels[day] ?? String(day)).join(', ');
};

const scheduleSummary = computed(() => {
    const first = scheduleItems.value[0];
    if (!first) {
        return '';
    }

    const startBehavior = hasOption('interrupt')
        ? (hasOption('prioritize') ? $gettext('Priority start') : $gettext('Starts on time'))
        : $gettext('Waits for current song');
    const repeatBehavior = first.loop_once
        ? $gettext('Plays once per block')
        : $gettext('Repeats during block');
    const endBehavior = hasOption('allow_overrun')
        ? $gettext('Lets current item finish')
        : $gettext('Stops at boundary');
    const timingMode = effectiveTimingMode.value === 'strict'
        ? $gettext('Strict scheduling')
        : effectiveTimingMode.value === 'mixed'
            ? $gettext('Mixed Flexible/Strict scheduling')
            : $gettext('Flexible scheduling');

    const dateRange = first.start_date && first.end_date
        ? `${first.start_date} – ${first.end_date}`
        : $gettext('Date range not complete');

    const windowText = `${formatDays(first.days)} ${formatTime(first.start_time)} – ${formatTime(first.end_time)}`;
    const extraWindows = scheduleItems.value.length > 1
        ? $gettext(' • %{count} schedule windows total', {count: scheduleItems.value.length})
        : '';

    return `${windowText} • ${dateRange} • ${startBehavior} • ${repeatBehavior} • ${endBehavior} • ${timingMode}${extraWindows}`;
});
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

/* The simple editor presents Flexible and Strict as coherent bundles. The
   opposite playout choices stay visible for clarity but are muted and cannot
   be selected until the operator switches Scheduling Mode. Mixed legacy rows
   remain editable without forced lockout. */
.timing-flexible :deep(.choice-grid-start .choice-option:nth-child(1)),
.timing-flexible :deep(.choice-grid-start .choice-option:nth-child(3)),
.timing-flexible :deep(.choice-grid-end .choice-option:nth-child(1)),
.timing-strict :deep(.choice-grid-start .choice-option:nth-child(2)),
.timing-strict :deep(.choice-grid-end .choice-option:nth-child(2)) {
    opacity: .42;
    pointer-events: none;
    filter: grayscale(.45);
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
.schedule-summary strong,
.schedule-summary small,
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

.icon-help,
.icon-reminder,
.icon-terms {
    background: rgba(13, 110, 253, .14);
    color: #2688ff;
}

.icon-presets {
    background: rgba(245, 158, 11, .18);
    color: #f59e0b;
}

.side-card-help {
    border-color: rgba(13, 110, 253, .42);
    background: rgba(13, 110, 253, .10);
}

.side-card-help h3,
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

.preset-news {
    background: linear-gradient(135deg, #7c3aed, #9333ea);
}

.side-card-terms li + li {
    margin-top: .35rem;
}

.schedule-summary {
    display: flex;
    align-items: flex-start;
    gap: .8rem;
    padding: 1rem 1.1rem;
    border: 1px solid rgba(25, 135, 84, .48);
    border-radius: .7rem;
    background: rgba(25, 135, 84, .10);
}

.summary-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.6rem;
    height: 2.6rem;
    flex: 0 0 2.6rem;
    border-radius: 50%;
    background: #198754;
    color: #fff;
    font-size: 1.55rem;
}

.schedule-summary strong {
    color: #23a866;
    font-size: 1rem;
}

.schedule-summary small {
    margin-top: .2rem;
    color: var(--bs-secondary-color);
    font-size: .84rem;
    line-height: 1.45;
}

@media (max-width: 1199.98px) {
    .schedule-sidebar {
        position: static;
        margin-top: .5rem;
    }
}
</style>
