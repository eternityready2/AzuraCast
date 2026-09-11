<template>
    <tab :label="$gettext('Schedule & Playback')">
        <div class="schedule-hero mb-4">
            <div>
                <h2 class="h5 mb-1">{{ $gettext('Schedule & Playback') }}</h2>
                <p class="mb-0">{{ $gettext('Set when this playlist airs and how it should behave at the beginning and end of each scheduled block.') }}</p>
            </div>
        </div>

        <div class="row g-4">
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
                    <span class="step-icon">▣</span>
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
                        class="btn btn-sm btn-primary"
                        @click="add"
                    >
                        <icon-ic-add/>
                        <span>{{ $gettext('Add Schedule Item') }}</span>
                    </button>
                </div>

                <form-playout-rules
                    v-if="!form.is_smart_block"
                    :has-schedule="scheduleItems.length > 0"
                />

                <div class="schedule-summary mt-4">
                    <span class="summary-check">✓</span>
                    <span>
                        <strong>{{ $gettext('Schedule Summary') }}</strong>
                        <small v-if="scheduleItems.length > 0">{{ scheduleSummary }}</small>
                        <small v-else>{{ $gettext('No scheduled window is configured. This enabled playlist can be eligible all day.') }}</small>
                    </span>
                </div>
            </div>

            <aside class="col-xl-3">
                <div class="side-card side-card-help mb-3">
                    <h3 class="h6 mb-2">{{ $gettext('Need Help?') }}</h3>
                    <p class="mb-0">
                        {{ $gettext('The common show settings are all on this page. Start with the schedule, then choose how playback begins and ends.') }}
                    </p>
                </div>

                <div class="side-card side-card-presets mb-3">
                    <h3 class="h6 mb-1">{{ $gettext('Quick Setup Presets') }}</h3>
                    <p class="small text-muted mb-3">
                        {{ $gettext('Add a schedule first, then use a preset to configure the most common combinations.') }}
                    </p>

                    <button
                        type="button"
                        class="preset-button preset-show"
                        :disabled="scheduleItems.length === 0"
                        @click="applyPreset('show')"
                    >
                        <strong>{{ $gettext('Scheduled Show / Programme') }}</strong>
                        <small>{{ $gettext('Starts on time • Plays once • Stops at boundary') }}</small>
                    </button>

                    <button
                        type="button"
                        class="preset-button preset-music"
                        :disabled="scheduleItems.length === 0"
                        @click="applyPreset('music')"
                    >
                        <strong>{{ $gettext('Music Rotation Block') }}</strong>
                        <small>{{ $gettext('Waits for current song • Repeats • Flexible end') }}</small>
                    </button>

                    <button
                        type="button"
                        class="preset-button preset-news"
                        :disabled="scheduleItems.length === 0"
                        @click="applyPreset('news')"
                    >
                        <strong>{{ $gettext('News / Alert / Priority') }}</strong>
                        <small>{{ $gettext('Hard start • Plays once • Overrides requests') }}</small>
                    </button>
                </div>

                <div class="side-card side-card-reminder mb-3">
                    <h3 class="h6 mb-2">{{ $gettext('Important Reminder') }}</h3>
                    <p class="mb-2">
                        {{ $gettext('An enabled playlist with no schedule can still be selected and played all day according to its playlist type and settings.') }}
                    </p>
                    <p class="mb-0">
                        {{ $gettext('If you do not want a playlist to air, either give it a schedule or disable it.') }}
                    </p>
                </div>

                <div class="side-card">
                    <h3 class="h6 mb-2">{{ $gettext('Terminology Tips') }}</h3>
                    <ul class="small mb-0 ps-3">
                        <li>{{ $gettext('Programme = interrupts normal rotation when its schedule starts.') }}</li>
                        <li>{{ $gettext('Rotation = waits for the current song before taking over.') }}</li>
                        <li>{{ $gettext('Flexible = prefers smooth timing for this schedule item.') }}</li>
                        <li>{{ $gettext('Strict = makes this schedule item a hard wall-clock start.') }}</li>
                        <li>{{ $gettext('Play once per block = do not start another playlist cycle in the same window.') }}</li>
                    </ul>
                </div>
            </aside>
        </div>
    </tab>
</template>

<script setup lang="ts">
import {computed} from "vue";
import {storeToRefs} from "pinia";
import PlaylistsFormScheduleRow from "~/components/Stations/Playlists/Form/ScheduleRow.vue";
import FormPlayoutRules from "~/components/Stations/Playlists/Form/PlayoutRules.vue";
import FormMarkup from "~/components/Form/FormMarkup.vue";
import Tab from "~/components/Common/Tab.vue";
import IconIcAdd from "~icons/ic/baseline-add";
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
            item.strict_start = false;
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
};

const remove = (index: number) => {
    scheduleItems.value.splice(index, 1);
};

const hasOption = (option: string): boolean => (
    Array.isArray(form.value.backend_options) && form.value.backend_options.includes(option)
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
    const timingMode = first.strict_start
        ? $gettext('Strict scheduling')
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
    padding: 1rem 1.1rem;
    border-radius: .75rem;
    background: linear-gradient(90deg, #1688f8, #0d6efd);
    color: #fff;
}

.schedule-hero p {
    color: rgba(255, 255, 255, .82);
    font-size: .82rem;
}

.schedule-overview {
    padding: .85rem 1rem;
    border: 1px solid;
    border-radius: .7rem;
}

.schedule-overview strong,
.schedule-overview span,
.step-heading strong,
.step-heading small,
.schedule-summary strong,
.schedule-summary small,
.preset-button strong,
.preset-button small {
    display: block;
}

.schedule-overview strong {
    font-size: .92rem;
}

.schedule-overview span {
    margin-top: .2rem;
    font-size: .78rem;
    line-height: 1.4;
}

.schedule-overview.is-scheduled {
    border-color: #3477b7;
    background: rgba(38, 136, 255, .08);
}

.schedule-overview.is-unscheduled {
    border-color: #b7791f;
    background: rgba(245, 158, 11, .08);
}

.step-heading {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .8rem 1rem;
    border-radius: .7rem;
}

.step-heading-time {
    background: rgba(13, 110, 253, .1);
    color: var(--bs-primary-text-emphasis);
}

.step-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    flex: 0 0 2rem;
    border-radius: .5rem;
    background: #0d6efd;
    color: #fff;
}

.step-heading strong {
    font-size: 1rem;
}

.step-heading small {
    margin-top: .15rem;
    color: var(--bs-secondary-color);
}

.side-card {
    padding: 1rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: var(--bs-tertiary-bg);
    font-size: .8rem;
    line-height: 1.45;
}

.side-card-help {
    border-color: rgba(13, 110, 253, .4);
    background: rgba(13, 110, 253, .06);
}

.side-card-presets {
    border-color: rgba(245, 158, 11, .45);
    background: rgba(245, 158, 11, .05);
}

.side-card-reminder {
    border-color: rgba(13, 110, 253, .28);
}

.preset-button {
    width: 100%;
    padding: .75rem .8rem;
    margin-bottom: .55rem;
    border: 0;
    border-radius: .55rem;
    color: #fff;
    text-align: left;
}

.preset-button:last-child {
    margin-bottom: 0;
}

.preset-button:disabled {
    opacity: .45;
    cursor: not-allowed;
}

.preset-button small {
    margin-top: .15rem;
    color: rgba(255, 255, 255, .85);
    font-size: .7rem;
}

.preset-show {
    background: #198754;
}

.preset-music {
    background: #0d6efd;
}

.preset-news {
    background: #7c3aed;
}

.schedule-summary {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    padding: .9rem 1rem;
    border: 1px solid rgba(25, 135, 84, .45);
    border-radius: .7rem;
    background: rgba(25, 135, 84, .08);
}

.summary-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    flex: 0 0 2rem;
    border-radius: 50%;
    background: #198754;
    color: #fff;
    font-weight: 800;
}

.schedule-summary small {
    margin-top: .2rem;
    color: var(--bs-secondary-color);
    line-height: 1.45;
}

@media (max-width: 1199.98px) {
    aside {
        margin-top: .5rem;
    }
}
</style>
