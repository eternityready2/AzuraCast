<template>
    <section
        class="schedule-card card mb-4"
        :class="{'is-this-event': !!highlightLabel}"
    >
        <div class="card-header schedule-card-header d-flex align-items-center">
            <div class="flex-fill d-flex align-items-center gap-2">
                <h2 class="card-title h5 mb-0">
                    {{ headerTitle }}
                </h2>
                <span
                    v-if="highlightLabel"
                    class="this-event-badge"
                >{{ highlightLabel }}</span>
            </div>
            <div class="flex-shrink-0">
                <button
                    type="button"
                    class="btn btn-sm btn-outline-light"
                    @click="doRemove()"
                >
                    <icon-ic-remove/>
                    <span>{{ $gettext('Remove') }}</span>
                </button>
            </div>
        </div>

        <div class="card-body p-3 p-lg-4">
            <!-- Times, duration and dates on one line -->
            <div class="time-row">
                <div class="form-group">
                    <label
                        class="form-label"
                        :for="'edit_form_start_time_'+index"
                    >
                        {{ $gettext('Start Time') }} <span class="text-danger">*</span>
                    </label>
                    <am-pm-time-input
                        v-model="row.start_time"
                        :input-id="'edit_form_start_time_'+index"
                        :aria-label="$gettext('Start Time')"
                    />
                </div>

                <div class="form-group">
                    <label
                        class="form-label"
                        :for="'edit_form_end_time_'+index"
                    >
                        {{ $gettext('End Time') }} <span class="text-danger">*</span>
                    </label>
                    <am-pm-time-input
                        v-model="row.end_time"
                        :input-id="'edit_form_end_time_'+index"
                        :aria-label="$gettext('End Time')"
                    />
                    <div class="field-hint">
                        {{ $gettext('Overnight is fine: end before start runs past midnight.') }}
                    </div>
                </div>

                <div class="form-group duration-group">
                    <label class="form-label">{{ $gettext('Duration') }}</label>
                    <div class="input-group input-group-sm">
                        <input
                            v-model.number="durationHours"
                            type="number"
                            min="0"
                            max="24"
                            class="form-control"
                            placeholder="HH"
                            :aria-label="$gettext('Hours')"
                        >
                        <span class="input-group-text">:</span>
                        <input
                            v-model.number="durationMinutes"
                            type="number"
                            min="0"
                            max="59"
                            class="form-control"
                            placeholder="MM"
                            :aria-label="$gettext('Minutes')"
                        >
                    </div>
                    <div class="field-hint">
                        {{ $gettext('Hours:Minutes') }}
                    </div>
                </div>

                <form-group-field
                    :id="'edit_form_start_date_'+index"
                    class="date-group"
                    :field="r$.start_date"
                    input-type="date"
                    :label="isOneTime ? $gettext('Date') : $gettext('Start Date')"
                />

                <form-group-field
                    v-if="!isOneTime"
                    :id="'edit_form_end_date_'+index"
                    class="date-group"
                    :field="r$.end_date"
                    input-type="date"
                    :label="$gettext('End Date')"
                    :required="row.recurrence_end_type !== 'after'"
                    :input-attrs="{ disabled: row.recurrence_end_type === 'after' }"
                />
            </div>

            <!-- One time or repeating -->
            <div class="schedule-mode">
                <span class="schedule-mode-label">{{ $gettext('Schedule') }}</span>
                <div
                    class="seg"
                    role="group"
                    :aria-label="$gettext('Schedule')"
                >
                    <button
                        type="button"
                        :class="{on: isOneTime}"
                        :aria-pressed="isOneTime"
                        @click="setOneTime"
                    >
                        {{ $gettext('One time') }}
                    </button>
                    <button
                        type="button"
                        :class="{on: !isOneTime}"
                        :aria-pressed="!isOneTime"
                        @click="setRepeating"
                    >
                        {{ $gettext('Repeating') }}
                    </button>
                </div>
            </div>

            <!-- Row 1: which days | how it repeats -->
            <div
                v-if="!isOneTime"
                class="box-row"
            >
                <form-group-multi-check
                    :id="'edit_form_days_'+index"
                    class="days-panel"
                    :field="r$.days"
                    :label="$gettext('Days of Week')"
                    :options="dayOptions"
                    :required="!isMonthlyDatePattern"
                    stacked
                >
                    <template #description>
                        <span class="days-hint">{{ daysHint }}</span>
                        <span class="quick-pick">
                            <span>{{ $gettext('Quick pick:') }}</span>
                            <button
                                v-for="pick in quickPicks"
                                :key="pick.label"
                                type="button"
                                @click="row.days = [...pick.days]"
                            >
                                {{ pick.label }}
                            </button>
                        </span>
                    </template>
                </form-group-multi-check>

                <div class="panel repeats-panel">
                    <div class="panel-title">
                        {{ $gettext('Repeats & ends') }}
                        <small>{{ $gettext('How often this time repeats and when it stops.') }}</small>
                    </div>
                    <div class="repeats-grid">
                        <div class="cell-repeats">
                            <div class="mini-label">
                                {{ $gettext('Repeats') }}
                            </div>
                            <div
                                class="seg seg-sm"
                                role="group"
                                :aria-label="$gettext('Repeats')"
                            >
                                <button
                                    v-for="opt in recurrenceTypeOptions"
                                    :key="opt.value"
                                    type="button"
                                    :class="{on: recurrenceType === opt.value}"
                                    :aria-pressed="recurrenceType === opt.value"
                                    @click="setRecurrenceType(opt.value)"
                                >
                                    {{ opt.text }}
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="row.recurrence_type === 'custom'"
                            class="cell-extra-1"
                        >
                            <label
                                class="mini-label"
                                :for="'edit_form_recurrence_interval_'+index"
                            >{{ $gettext('Every (weeks)') }}</label>
                            <input
                                :id="'edit_form_recurrence_interval_'+index"
                                v-model.number="row.recurrence_interval"
                                type="number"
                                min="1"
                                max="52"
                                class="form-control form-control-sm mini-input"
                            >
                        </div>

                        <template v-if="row.recurrence_type === 'monthly'">
                            <div class="cell-extra-1">
                                <label
                                    class="mini-label"
                                    :for="'edit_form_recurrence_monthly_pattern_'+index"
                                >{{ $gettext('Pattern') }}</label>
                                <select
                                    :id="'edit_form_recurrence_monthly_pattern_'+index"
                                    v-model="row.recurrence_monthly_pattern"
                                    class="form-select form-select-sm mini-select"
                                >
                                    <option
                                        v-for="opt in recurrenceMonthlyPatternOptions"
                                        :key="opt.value"
                                        :value="opt.value"
                                    >
                                        {{ opt.text }}
                                    </option>
                                </select>
                            </div>

                            <div
                                v-if="row.recurrence_monthly_pattern === 'date'"
                                class="cell-extra-2"
                            >
                                <label
                                    class="mini-label"
                                    :for="'edit_form_recurrence_monthly_day_'+index"
                                >{{ $gettext('Day of month') }}</label>
                                <input
                                    :id="'edit_form_recurrence_monthly_day_'+index"
                                    v-model.number="row.recurrence_monthly_day"
                                    type="number"
                                    min="1"
                                    max="31"
                                    class="form-control form-control-sm mini-input"
                                    :class="{'is-invalid': !row.recurrence_monthly_day}"
                                >
                            </div>

                            <div
                                v-if="row.recurrence_monthly_pattern === 'day_of_week'"
                                class="cell-extra-2"
                            >
                                <label
                                    class="mini-label"
                                    :for="'edit_form_recurrence_monthly_week_'+index"
                                >{{ $gettext('Week') }}</label>
                                <select
                                    :id="'edit_form_recurrence_monthly_week_'+index"
                                    v-model.number="row.recurrence_monthly_week"
                                    class="form-select form-select-sm mini-select"
                                    :class="{'is-invalid': !row.recurrence_monthly_week}"
                                >
                                    <option
                                        v-for="opt in recurrenceMonthlyWeekOptions"
                                        :key="opt.value"
                                        :value="opt.value"
                                    >
                                        {{ opt.text }}
                                    </option>
                                </select>
                            </div>
                        </template>

                        <div class="cell-ends">
                            <div class="mini-label">
                                {{ $gettext('Ends') }}
                            </div>
                            <div
                                class="seg seg-sm"
                                role="group"
                                :aria-label="$gettext('Ends')"
                            >
                                <button
                                    v-for="opt in recurrenceEndTypeOptions"
                                    :key="opt.value"
                                    type="button"
                                    :class="{on: row.recurrence_end_type === opt.value}"
                                    :aria-pressed="row.recurrence_end_type === opt.value"
                                    @click="row.recurrence_end_type = opt.value"
                                >
                                    {{ opt.text }}
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="row.recurrence_end_type === 'after'"
                            class="cell-ends-extra"
                        >
                            <label
                                class="mini-label"
                                :for="'edit_form_recurrence_end_after_'+index"
                            >{{ $gettext('Number of times') }}</label>
                            <input
                                :id="'edit_form_recurrence_end_after_'+index"
                                v-model.number="row.recurrence_end_after"
                                type="number"
                                min="1"
                                class="form-control form-control-sm mini-input"
                                :class="{'is-invalid': !row.recurrence_end_after}"
                            >
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 2: options for this time | playlist-wide settings (first card only) -->
            <div
                v-if="hasTimeOptions || $slots.aside"
                class="box-row"
            >
                <div
                    v-if="hasTimeOptions"
                    class="panel options-panel"
                    :class="{'is-wide': !$slots.aside}"
                >
                    <div class="panel-title">
                        {{ $gettext('Options for this time') }}
                        <small>{{ optionsSummary }}</small>
                    </div>

                    <div class="seg-wrap">
                        <div v-if="shows.timing">
                            <div class="mini-label">
                                {{ $gettext('Timing') }}
                                <span
                                    class="info-tip"
                                    tabindex="0"
                                    role="button"
                                    :aria-label="$gettext('More info')"
                                >i<span class="info-tip-body">
                                    <strong>{{ $gettext('Flexible') }}</strong>
                                    <span>{{ $gettext('Normal AzuraCast-style schedule timing. This schedule row does not add its own hard-start override.') }}</span>
                                    <span>• {{ $gettext('Start Behavior still decides whether the playlist normally interrupts.') }}</span>
                                    <span>• {{ $gettext('Best for normal music flow and schedules that do not need a special exact-time override.') }}</span>
                                    <strong>{{ $gettext('Strict / Exact Time') }}</strong>
                                    <span>{{ $gettext('Adds a hard wall-clock start override to this specific schedule row.') }}</span>
                                    <span>• {{ $gettext('May cut current audio at the scheduled start if needed.') }}</span>
                                    <span>• {{ $gettext('Overrides a Rotation / wait start for this schedule time only.') }}</span>
                                </span></span>
                            </div>
                            <div
                                class="seg"
                                role="group"
                                :aria-label="$gettext('Timing')"
                            >
                                <button
                                    :id="'scheduling_flexible_'+index"
                                    type="button"
                                    :class="{on: !row.strict_start}"
                                    :aria-pressed="!row.strict_start"
                                    @click="setTiming('flexible')"
                                >
                                    {{ $gettext('Flexible') }}
                                </button>
                                <button
                                    :id="'scheduling_strict_'+index"
                                    type="button"
                                    :class="{on: row.strict_start}"
                                    :aria-pressed="row.strict_start"
                                    @click="setTiming('strict')"
                                >
                                    {{ $gettext('Strict') }}
                                </button>
                            </div>
                            <div class="field-hint">
                                {{ row.strict_start
                                    ? $gettext('Starts exactly on time — may cut the current song.')
                                    : $gettext('Waits for the current song, then starts.') }}
                            </div>
                        </div>

                        <div v-if="shows.loop">
                            <div class="mini-label">
                                {{ $gettext('If it runs out before the end time') }}
                                <span
                                    class="info-tip"
                                    tabindex="0"
                                    role="button"
                                    :aria-label="$gettext('More info')"
                                >i<span class="info-tip-body">
                                    <strong>{{ $gettext('Stop — Play once per scheduled block (No Loop)') }}</strong>
                                    <span>{{ $gettext('Recommended for shows and programs. The playlist will not start a second cycle in this time slot.') }}</span>
                                    <strong>{{ $gettext('Play it again — Repeat until end of block (Loop)') }}</strong>
                                    <span>{{ $gettext('Useful for music rotation. The playlist may begin another cycle while this schedule remains active.') }}</span>
                                </span></span>
                            </div>
                            <div
                                class="seg"
                                role="group"
                                :aria-label="$gettext('If it runs out before the end time')"
                            >
                                <button
                                    :id="'scheduling_once_'+index"
                                    type="button"
                                    :class="{on: row.loop_once}"
                                    :aria-pressed="row.loop_once"
                                    @click="row.loop_once = true"
                                >
                                    {{ $gettext('Stop') }}
                                </button>
                                <button
                                    :id="'scheduling_repeat_'+index"
                                    type="button"
                                    :class="{on: !row.loop_once}"
                                    :aria-pressed="!row.loop_once"
                                    @click="row.loop_once = false"
                                >
                                    {{ $gettext('Play it again') }}
                                </button>
                            </div>
                            <div class="field-hint">
                                {{ row.loop_once
                                    ? $gettext('Normal rotation fills the rest of the time.')
                                    : $gettext('Starts the playlist over until the end time.') }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="shows.hold"
                        class="form-check form-switch option-switch"
                    >
                        <input
                            :id="'scheduling_prevent_requests_'+index"
                            v-model="row.prevent_requests"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                        >
                        <label
                            class="form-check-label"
                            :for="'scheduling_prevent_requests_'+index"
                        >
                            <strong>{{ $gettext('Hold listener requests during this time') }}</strong>
                            <small>{{ $gettext('Requests wait in the queue until this time ends.') }}</small>
                        </label>
                    </div>

                    <div
                        v-if="shows.reset"
                        class="form-check form-switch option-switch"
                    >
                        <input
                            :id="'scheduling_reset_queue_'+index"
                            v-model="row.reset_queue_at_start"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                        >
                        <label
                            class="form-check-label"
                            :for="'scheduling_reset_queue_'+index"
                        >
                            <strong>{{ $gettext('Start from the beginning') }}</strong>
                            <small>{{ $gettext('Restarts the playlist each time this slot begins, instead of picking up where it left off.') }}</small>
                        </label>
                    </div>

                    <div
                        v-if="shows.reset && isPlaylistGroup && row.reset_queue_at_start"
                        class="form-check form-switch option-switch option-switch-nested"
                    >
                        <input
                            :id="'scheduling_reset_queue_recursive_'+index"
                            v-model="row.reset_queue_recursive"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                        >
                        <label
                            class="form-check-label"
                            :for="'scheduling_reset_queue_recursive_'+index"
                        >
                            <strong>{{ $gettext('Also restart the playlists inside it') }}</strong>
                            <small>{{ $gettext('Restarts every playlist in this group too.') }}</small>
                        </label>
                    </div>
                </div>

                <slot name="aside" />
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
// "Scheduled Time" card of the calendar event editor. Shows only the options
// the chosen source actually uses (see the backend AutoDJ schedulers).
import FormGroupField from "~/components/Form/FormGroupField.vue";
import AmPmTimeInput from "~/components/Common/AmPmTimeInput.vue";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import {applyIf, minLength, minValue, required, requiredIf, withMessage} from "@regle/rules";
import {computed, ref, watch} from "vue";
import {DateTime} from "luxon";
import {useTranslate} from "~/vendor/gettext";
import {useAppScopedRegle} from "~/vendor/regle.ts";
import IconIcRemove from "~icons/ic/baseline-remove";

export type CalendarSource = 'playlist' | 'web_stream' | 'smart_block' | 'clock_wheel';

interface PlaylistScheduleRow {
    start_time: number | null,
    end_time: number | null,
    start_date: string | null,
    end_date: string | null,
    days: number[],
    loop_once: boolean,
    prevent_requests: boolean,
    reset_queue_at_start: boolean,
    reset_queue_recursive: boolean,
    strict_start: boolean,
    recurrence_type: string | null,
    recurrence_interval: number,
    recurrence_monthly_pattern: string | null,
    recurrence_monthly_day: number | null,
    recurrence_monthly_week: number | null,
    recurrence_monthly_day_of_week: number | null,
    recurrence_end_type: string,
    recurrence_end_after: number | null,
    recurrence_end_date: string | null,
}

const props = defineProps<{
    index: number,
    source?: CalendarSource,
    isPlaylistGroup?: boolean,
    // Set on the window the calendar event was opened from ("This event" / "New").
    highlightLabel?: string | null,
}>();

const {index} = props;

const row = defineModel<PlaylistScheduleRow>('row', {required: true});

const emit = defineEmits<{
    (e: 'remove'): void,
    (e: 'timing', mode: 'flexible' | 'strict'): void,
}>();

const {$gettext} = useTranslate();

// Which per-time options the source really uses:
// - Hold requests: playlist schedules only (QueueBuilder reads playlist rows).
// - Start from the beginning: song playlists and playlist groups only.
// - If it runs out (loop_once): song playlists; forced off for clock wheels.
// - Timing: playlists, web streams and clock wheels (clock_wheel_mode).
// Smart Blocks use only the time window.
const shows = computed(() => {
    const source = props.source ?? 'playlist';
    return {
        timing: source !== 'smart_block',
        loop: source === 'playlist',
        hold: source === 'playlist' || source === 'web_stream',
        reset: source === 'playlist',
    };
});

const hasTimeOptions = computed(() => Object.values(shows.value).some(Boolean));

const optionsSummary = computed(() => {
    const parts = [];
    if (shows.value.timing) {
        parts.push($gettext('Timing'));
    }
    if (shows.value.loop) {
        parts.push($gettext('if it runs out early'));
    }
    if (shows.value.hold) {
        parts.push($gettext('listener requests'));
    }
    if (shows.value.reset) {
        parts.push($gettext('start from the beginning'));
    }
    return parts.join(', ');
});

const setTiming = (mode: 'flexible' | 'strict') => {
    row.value.strict_start = mode === 'strict';
    emit('timing', mode);
};

// ── Times and duration ──────────────────────────────────────────────────────
const toMinutes = (code: number | null): number | null => {
    if (code === null || code === undefined) {
        return null;
    }
    const n = Number(code);
    return Math.floor(n / 100) * 60 + (n % 100);
};

const toTimeCode = (minutes: number): number => {
    const m = ((minutes % 1440) + 1440) % 1440;
    return Math.floor(m / 60) * 100 + (m % 60);
};

const durationTotal = computed((): number | null => {
    const start = toMinutes(row.value.start_time);
    const end = toMinutes(row.value.end_time);
    if (start === null || end === null) {
        return null;
    }
    let total = end - start;
    if (total <= 0) {
        total += 1440;
    }
    return total;
});

const setDuration = (hours: number, minutes: number) => {
    const start = toMinutes(row.value.start_time);
    const total = (Number(hours) || 0) * 60 + (Number(minutes) || 0);
    if (start !== null && total > 0) {
        row.value.end_time = toTimeCode(start + total);
    }
};

const durationHours = computed({
    get: () => durationTotal.value === null ? null : Math.floor(durationTotal.value / 60),
    set: (value) => setDuration(Number(value), durationMinutes.value ?? 0),
});

const durationMinutes = computed({
    get: () => durationTotal.value === null ? null : durationTotal.value % 60,
    set: (value) => setDuration(durationHours.value ?? 0, Number(value)),
});

// ── One time / repeating ────────────────────────────────────────────────────
// A one-time window is a single-day date range; the weekday is that date's.
const weekdayOf = (date: string | null): number | null => {
    if (!date) {
        return null;
    }
    const weekday = DateTime.fromISO(date).weekday;
    return weekday || null;
};

const isOneTime = ref(!!row.value.start_date && row.value.start_date === row.value.end_date);
let savedEndDate: string | null = null;
let savedDays: number[] = [];

const applyOneTime = () => {
    row.value.end_date = row.value.start_date;
    row.value.recurrence_type = 'weekly';
    row.value.recurrence_interval = 1;
    row.value.recurrence_end_type = 'never';
    const weekday = weekdayOf(row.value.start_date);
    row.value.days = weekday ? [weekday] : [];
};

const setOneTime = () => {
    if (isOneTime.value) {
        return;
    }
    savedEndDate = row.value.end_date;
    savedDays = [...row.value.days];
    isOneTime.value = true;
    applyOneTime();
};

const setRepeating = () => {
    if (!isOneTime.value) {
        return;
    }
    isOneTime.value = false;
    if (savedEndDate && savedEndDate !== row.value.start_date) {
        row.value.end_date = savedEndDate;
    }
    if (savedDays.length > 0) {
        row.value.days = [...savedDays];
    }
};

watch(() => row.value.start_date, () => {
    if (isOneTime.value) {
        applyOneTime();
    }
});

// ── Days ────────────────────────────────────────────────────────────────────
const isMonthlyDatePattern = computed(
    () => row.value.recurrence_type === 'monthly' && row.value.recurrence_monthly_pattern === 'date'
);

const isMonthlyDayOfWeekPattern = computed(
    () => row.value.recurrence_type === 'monthly' && row.value.recurrence_monthly_pattern === 'day_of_week'
);

const requiresDaysOfWeek = computed(() => !isMonthlyDatePattern.value);

const dayOptions = [
    {value: 1, text: $gettext('Monday')},
    {value: 2, text: $gettext('Tuesday')},
    {value: 3, text: $gettext('Wednesday')},
    {value: 4, text: $gettext('Thursday')},
    {value: 5, text: $gettext('Friday')},
    {value: 6, text: $gettext('Saturday')},
    {value: 7, text: $gettext('Sunday')}
];

const dayShort: Record<number, string> = {
    1: $gettext('Mon'), 2: $gettext('Tue'), 3: $gettext('Wed'), 4: $gettext('Thu'),
    5: $gettext('Fri'), 6: $gettext('Sat'), 7: $gettext('Sun'),
};

const quickPicks = [
    {label: $gettext('Weekdays'), days: [1, 2, 3, 4, 5]},
    {label: $gettext('Weekends'), days: [6, 7]},
    {label: $gettext('Every day'), days: [1, 2, 3, 4, 5, 6, 7]},
    {label: $gettext('Clear'), days: []},
];


const daysHint = computed(() => {
    if (isMonthlyDatePattern.value) {
        return $gettext('Not used for a monthly "day of month" schedule.');
    }
    return isMonthlyDayOfWeekPattern.value
        ? $gettext('Pick the weekday for this pattern.')
        : $gettext('Select at least one day of the week.');
});

// ── Repeats & ends ──────────────────────────────────────────────────────────
const recurrenceType = computed(() => row.value.recurrence_type ?? 'weekly');

const setRecurrenceType = (type: string) => {
    row.value.recurrence_type = type;
    if (type === 'monthly' && !row.value.recurrence_monthly_pattern) {
        row.value.recurrence_monthly_pattern = 'date';
    }
};

const recurrenceTypeOptions = [
    {value: 'weekly', text: $gettext('Weekly')},
    {value: 'biweekly', text: $gettext('Every 2 weeks')},
    {value: 'monthly', text: $gettext('Monthly')},
    {value: 'custom', text: $gettext('Custom')}
];

const recurrenceMonthlyPatternOptions = [
    {value: 'date', text: $gettext('Day of month (15th)')},
    {value: 'day_of_week', text: $gettext('Weekday (3rd Mon)')}
];

const recurrenceMonthlyWeekOptions = [
    {value: 1, text: $gettext('1st')},
    {value: 2, text: $gettext('2nd')},
    {value: 3, text: $gettext('3rd')},
    {value: 4, text: $gettext('4th')},
    {value: 5, text: $gettext('Last')}
];

const recurrenceEndTypeOptions = [
    {value: 'never', text: $gettext('On end date')},
    {value: 'after', text: $gettext('After a number of times')}
];

const {r$} = useAppScopedRegle(
    row,
    {
        start_time: {required},
        end_time: {required},
        start_date: {required},
        end_date: {
            required: requiredIf(() => row.value.recurrence_end_type !== 'after'),
        },
        days: {
            minLength: withMessage(
                applyIf(requiresDaysOfWeek, minLength(1)),
                () => $gettext('Select at least one day of the week.')
            ),
        },
        recurrence_end_after: {
            required: requiredIf(() => row.value.recurrence_end_type === 'after'),
            minValue: minValue(1),
        },
        recurrence_monthly_day: {
            required: requiredIf(
                () => row.value.recurrence_type === 'monthly' && row.value.recurrence_monthly_pattern === 'date'
            ),
        },
        recurrence_monthly_week: {
            required: requiredIf(() => isMonthlyDayOfWeekPattern.value),
        },
    },
    {
        namespace: 'stations-playlists'
    }
);


watch(
    () => row.value.reset_queue_at_start,
    (enabled) => {
        if (!enabled) {
            row.value.reset_queue_recursive = false;
        }
    }
);

watch(
    () => row.value.recurrence_type,
    (newType: string | null) => {
        if (newType === 'biweekly') {
            row.value.recurrence_interval = 2;
        } else if (newType === 'weekly') {
            row.value.recurrence_interval = 1;
        }
    }
);

// A monthly "day of month" schedule ignores weekdays. They stay as the user
// set them here; the save leaves them out (CreateEventModal newSchedulePayload).

// ── Card title ──────────────────────────────────────────────────────────────
const formatTime = (code: number | null): string => {
    const m = toMinutes(code);
    if (m === null) {
        return '—';
    }
    const h = Math.floor(m / 60);
    return `${h % 12 || 12}:${String(m % 60).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
};

const ordinal = (n: number): string => {
    const s = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
};

const formatDayList = (days: number[]): string => {
    const key = [...days].sort((a, b) => a - b).join(',');
    if (key === '1,2,3,4,5') {
        return $gettext('Mon–Fri');
    }
    if (key === '6,7') {
        return $gettext('Sat–Sun');
    }
    if (days.length === 7) {
        return $gettext('Every day');
    }
    if (days.length === 0) {
        return $gettext('No days set');
    }
    return [...days].sort((a, b) => a - b)
        .map((d) => dayShort[d] ?? '')
        .join(', ');
};

const headerTitle = computed(() => {
    const times = `${formatTime(row.value.start_time)} – ${formatTime(row.value.end_time)}`;

    if (isOneTime.value) {
        const date = row.value.start_date
            ? DateTime.fromISO(row.value.start_date).toFormat('ccc, LLL d, yyyy')
            : '—';
        return `${$gettext('One time')} · ${date} · ${times}`;
    }

    if (row.value.recurrence_type === 'monthly') {
        if (isMonthlyDatePattern.value) {
            const day = Number(row.value.recurrence_monthly_day) || 0;
            const when = day
                ? $gettext('Monthly on the %{day}', {day: ordinal(day)})
                : $gettext('Monthly · pick a day');
            return `${when} · ${times}`;
        }
        const week = recurrenceMonthlyWeekOptions.find((o) => o.value === Number(row.value.recurrence_monthly_week));
        const when = $gettext('Monthly on the %{week} %{days}', {
            week: week ? week.text.toLowerCase() : $gettext('(pick week)'),
            days: row.value.days.length ? formatDayList(row.value.days) : $gettext('(pick weekday)'),
        });
        return `${when} · ${times}`;
    }

    return `${formatDayList(row.value.days)} · ${times}`;
});

const doRemove = () => {
    emit('remove');
};
</script>

<style scoped>
.schedule-card {
    border: 1px solid rgba(13, 110, 253, .45);
    border-radius: .75rem;
}

.schedule-card-header {
    padding: .8rem 1rem;
    border-bottom: 1px solid rgba(13, 110, 253, .45);
    border-top-left-radius: .7rem;
    border-top-right-radius: .7rem;
    background: rgba(13, 110, 253, .14);
    color: #cfe2ff;
}

.schedule-card-header .btn {
    color: #cfe2ff;
    border-color: rgba(207, 226, 255, .5);
}

.schedule-card.is-this-event {
    /* Stop below the site's fixed top bar when scrolled to. */
    scroll-margin-top: 5.5rem;
    border: 2px solid #ffc107;
    box-shadow: 0 0 0 .2rem rgba(255, 193, 7, .2);
}

.this-event-badge {
    padding: .15rem .55rem;
    border-radius: 999px;
    background: #ffc107;
    color: #212529;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .03em;
    text-transform: uppercase;
}

/* Times, duration and dates */
.time-row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    column-gap: 1.5rem;
    row-gap: 1rem;
    margin-bottom: 1rem;
}

.time-row > .form-group,
.time-row > :deep(.form-group) {
    flex: 0 0 auto;
    width: auto;
    margin: 0;
}

/* The "11:00 AM" readout sits under the pickers without widening the field. */
.time-row :deep(.am-pm-time-segments) {
    position: relative;
    width: max-content;
    padding-bottom: 1.55rem;
}

.time-row :deep(.am-pm-time-segments > .d-flex) {
    flex-wrap: nowrap !important;
}

.time-row :deep(.am-pm-time-segments__preview) {
    position: absolute;
    bottom: 0;
    left: 0;
    white-space: nowrap;
}

.time-row :deep(.date-group input[type=date]) {
    width: 9.5rem;
}

.time-row :deep(.date-group .form-text) {
    display: none;
}

.time-row :deep(.date-group label .text-danger) {
    margin-left: .25em;
}

.duration-group .input-group {
    width: 8.5rem;
}

.duration-group input {
    padding-left: .5rem;
    padding-right: .25rem;
}

.field-hint {
    max-width: 15rem;
    margin-top: .3rem;
    color: var(--bs-secondary-color);
    font-size: .78rem;
}

.field-error {
    margin-top: .3rem;
    color: var(--bs-form-invalid-color);
    font-size: .78rem;
}

/* One time / Repeating */
.schedule-mode {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.schedule-mode-label {
    min-width: 6.5rem;
    font-weight: 600;
}

/* Segmented buttons */
.seg {
    display: inline-flex;
    overflow: hidden;
    border: 1px solid #0d6efd;
    border-radius: .45rem;
}

.seg button {
    padding: .35rem .9rem;
    border: 0;
    background: transparent;
    color: #6ea8fe;
    font-size: .8rem;
    font-weight: 700;
}

.seg button.on {
    background: #0d6efd;
    color: #fff;
}

.seg-sm button {
    height: 26.6px;
    padding: 0 8px;
    font-size: 11.2px;
}

/* Two boxes side by side, same columns on every row */
.box-row {
    display: grid;
    grid-template-columns: minmax(0, 5fr) minmax(0, 6fr);
    gap: 1rem;
    align-items: stretch;
    margin-bottom: 1rem;
}

.box-row > :only-child,
.options-panel.is-wide {
    grid-column: 1 / -1;
}

.panel {
    min-width: 0;
    padding: 7.7px 9.1px;
    border: 1px solid rgb(108, 117, 125);
    border-radius: 9.1px;
    background: rgb(43, 48, 53);
}

.panel-title {
    margin-bottom: 6px;
    color: #fff;
    font-size: 13.4px;
    font-weight: 700;
}

.panel-title small {
    display: block;
    color: var(--bs-secondary-color);
    font-size: 11.2px;
    font-weight: 400;
}

.repeats-panel .panel-title small {
    display: inline;
    margin-left: .5rem;
}

.mini-label {
    display: block;
    margin: 0 0 4.9px;
    color: #fff;
    font-size: 11.2px;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
}

/* Days: the shared weekday pills, stretched to fill the box */
.days-panel {
    min-width: 0;
    margin: 0 !important;
    padding-top: 4.7px !important;
    padding-bottom: 19px !important;
}

.days-panel :deep(> label),
.days-panel :deep(.form-label) {
    margin-bottom: 6px;
    color: #fff;
    font-size: 13.4px;
    font-weight: 700;
}

.days-panel :deep(.form-check) {
    flex: 1 1 0 !important;
    min-width: 0;
}

.days-panel :deep(.form-check-label) {
    width: 100% !important;
    min-width: 0 !important;
}

.days-panel :deep(> div) {
    flex-wrap: nowrap !important;
    gap: 5.88px !important;
}

.days-panel :deep(.form-text) {
    display: block;
    margin-top: calc(.3rem + 3px);
    color: var(--bs-secondary-color);
    font-size: .78rem;
}

.days-panel :deep(.invalid-feedback ~ .form-text .days-hint),
.days-panel :deep(.invalid-feedback.d-block ~ .form-text .days-hint) {
    display: none;
}

.days-hint {
    display: block;
}



.quick-pick {
    display: flex;
    align-items: center;
    gap: .25rem;
    margin-top: 10px;
    color: var(--bs-secondary-color);
    font-size: .8rem;
}

.quick-pick span {
    margin-right: .25rem;
}

.quick-pick button {
    padding: .2rem .35rem;
    border: 0;
    background: transparent;
    color: #6ea8fe;
    font-size: .8rem;
    font-weight: 600;
}

.quick-pick button:hover {
    text-decoration: underline;
}

/* Repeats & ends: buttons on the left, their extra boxes inline on the right */
.repeats-grid {
    display: grid;
    grid-template-columns: repeat(3, max-content);
    column-gap: .5rem;
    row-gap: .45rem;
    align-items: end;
}

.cell-repeats {
    grid-row: 1;
    grid-column: 1;
}

.cell-extra-1 {
    grid-row: 1;
    grid-column: 2;
}

.cell-extra-2 {
    grid-row: 1;
    grid-column: 3;
}

.cell-ends {
    grid-row: 2;
    grid-column: 1;
}

.cell-ends-extra {
    grid-row: 2;
    grid-column: 2;
}

.mini-input,
.mini-select {
    height: 26.6px;
    min-height: 0;
    padding-top: 0;
    padding-bottom: 0;
    font-size: 11.2px;
}

/* Missing value: red border only. Bootstrap's warning icon would fill these
   small boxes and hide the number. */
.mini-input.is-invalid,
.mini-select.is-invalid {
    border-color: var(--bs-form-invalid-border-color);
    box-shadow: none;
}

.mini-input.is-invalid {
    padding-right: .4rem;
    background-image: none;
}

.mini-select.is-invalid {
    padding-right: 1.75rem;
    background-image: var(--bs-form-select-bg-img);
    background-position: right .45rem center;
    background-size: 16px 12px;
}

.mini-input {
    width: 4.25rem;
    padding-left: .5rem;
    padding-right: .4rem;
}

.mini-select {
    width: auto;
    padding-left: .5rem;
    padding-right: 1.75rem;
    background-position: right .45rem center;
}

/* Options for this time */
.seg-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem 2rem;
    margin-bottom: .6rem;
}

.option-switch {
    margin: .4rem 0;
    padding-left: 2.75em;
}

.option-switch-nested {
    margin-left: 1.5rem;
}

.option-switch strong,
.option-switch small {
    display: block;
}

.option-switch strong {
    font-size: .84rem;
}

.option-switch small {
    color: var(--bs-secondary-color);
    font-size: .78rem;
}

/* "i" icon with a hover / focus explanation */
.info-tip {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.05rem;
    height: 1.05rem;
    margin-left: .35rem;
    border-radius: 50%;
    background: #0d6efd;
    color: #fff;
    font-size: .68rem;
    font-style: normal;
    font-weight: 700;
    vertical-align: middle;
    cursor: help;
}

.info-tip-body {
    position: absolute;
    top: 1.5rem;
    left: 0;
    z-index: 50;
    display: none;
    width: 24rem;
    max-width: 80vw;
    padding: .75rem .85rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .5rem;
    background: var(--bs-body-bg);
    box-shadow: 0 .5rem 1.25rem rgba(0, 0, 0, .35);
    color: var(--bs-body-color);
    font-size: .8rem;
    font-weight: 400;
    line-height: 1.45;
    text-align: left;
    white-space: normal;
    cursor: default;
}

.info-tip:hover .info-tip-body,
.info-tip:focus .info-tip-body,
.info-tip:focus-within .info-tip-body {
    display: block;
}

.info-tip-body strong,
.info-tip-body span {
    display: block;
}

.info-tip-body strong {
    margin-top: .35rem;
    color: var(--bs-emphasis-color);
}

.info-tip-body strong:first-child {
    margin-top: 0;
}

@media (max-width: 991.98px) {
    .box-row {
        grid-template-columns: 1fr;
    }
}
</style>
