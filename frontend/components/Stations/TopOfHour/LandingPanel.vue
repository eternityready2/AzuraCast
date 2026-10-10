<template>
    <div>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <h3 class="h6 mb-0">{{ $gettext('How Each Hour Ended') }}</h3>
            <div class="d-flex align-items-center gap-2">
                <select
                    v-if="!dateRange"
                    v-model.number="days"
                    class="form-select form-select-sm w-auto"
                    :aria-label="$gettext('Period')"
                    :disabled="isLoading"
                >
                    <option
                        v-for="option in dayOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.text }}
                    </option>
                </select>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    :disabled="!report || report.failures.length === 0"
                    @click="downloadCsv"
                >
                    {{ $gettext('Download CSV') }}
                </button>
            </div>
        </div>

        <loading :loading="isLoading && !report">
            <template v-if="report">
                <div class="row gx-3 gy-0">
                    <div
                        v-for="group in groups"
                        :key="group.title"
                        :class="groupClass(group.boxes.length)"
                    >
                        <div class="text-uppercase text-secondary small fw-semibold mt-3 mb-2">
                            {{ group.title }}
                        </div>
                        <div class="row g-2">
                            <div
                                v-for="box in group.boxes"
                                :key="box.label"
                                :class="boxClass(group.boxes.length)"
                            >
                                <div class="border rounded p-2 text-center h-100">
                                    <div v-if="box.list && box.list.length > 0" class="small text-start mb-1">
                                        <div
                                            v-for="item in box.list"
                                            :key="item.text"
                                            class="d-flex gap-2"
                                        >
                                            <span class="fw-semibold text-warning text-nowrap">{{ item.count }}×</span>
                                            <span class="text-truncate" :title="item.text">{{ item.text }}</span>
                                        </div>
                                    </div>
                                    <div v-else class="fs-4 fw-semibold" :class="box.tone">
                                        {{ boxValue(box) ?? '—' }}<span v-if="boxValue(box) != null" class="fs-6">{{ box.unit ?? '%' }}</span>
                                        <span
                                            v-if="box.trend"
                                            class="fs-6 ms-1"
                                            :class="box.trend.tone"
                                            :title="box.trend.title"
                                        >{{ box.trend.text }}</span>
                                    </div>
                                    <div class="small">{{ box.label }}</div>
                                    <div class="small text-secondary">{{ box.detail }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="small text-secondary mt-2">
                    {{ $gettext('Hours that led into the ID with a show or feed are not counted (%{shows} in this period). Green means %{target}% or better.', {shows: String(report.show_hours), target: String(report.target_percent)}) }}
                </div>

                <div class="row gx-4 gy-0">
                    <div v-if="report.daily.length > 0" class="col-12 col-xl-7">
                        <h4 class="h6 mt-4 mb-2">{{ $gettext('Day by Day') }}</h4>
                        <div
                            v-for="day in report.daily"
                            :key="day.date"
                            class="d-flex align-items-center gap-2 mb-1 small"
                        >
                            <span class="landing-day text-nowrap">{{ dayLabel(day.date) }}</span>
                            <div
                                class="progress flex-fill landing-day-bar"
                                role="progressbar"
                                :aria-label="dayLabel(day.date)"
                                :aria-valuenow="day.clean_percent ?? 0"
                                aria-valuemin="0"
                                aria-valuemax="100"
                            >
                                <div
                                    class="progress-bar"
                                    :class="barTone(day.clean_percent, report.target_percent)"
                                    :style="{width: `${day.clean_percent ?? 0}%`}"
                                />
                            </div>
                            <span class="landing-day-value text-nowrap">
                                {{ day.clean_percent ?? '—' }}<template v-if="day.clean_percent != null">%</template>
                                <span class="text-secondary">({{ day.clean_count }}/{{ day.music_hours }})</span>
                            </span>
                            <span class="landing-day-note text-nowrap text-warning">{{ dayNote(day) }}</span>
                        </div>
                        <div class="small text-secondary mt-2">
                            {{ $gettext('Share of music hours that ended cleanly before the ID, per day.') }}
                        </div>
                    </div>
                    <div v-if="report.worst_hours.length > 0" class="col-12 col-xl-5">
                        <h4 class="h6 mt-4 mb-2">{{ $gettext('Times of Day With the Most Problems') }}</h4>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0 w-auto">
                                <thead>
                                    <tr>
                                        <th>{{ $gettext('Time of day') }}</th>
                                        <th class="text-end">{{ $gettext('Problem hours') }}</th>
                                        <th class="text-end">{{ $gettext('Hours counted') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="hour in report.worst_hours"
                                        :key="hour.hour"
                                    >
                                        <td>{{ hourLabel(hour.hour) }}</td>
                                        <td class="text-end fw-semibold text-warning">{{ hour.missed }}</td>
                                        <td class="text-end">{{ hour.music_hours }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <h4 class="h6 mt-4 mb-2">
                    {{ $gettext('Hours With a Problem') }}
                    <span class="text-secondary fw-normal">({{ report.failures.length }})</span>
                </h4>
                <div v-if="report.failures.length === 0" class="small text-secondary">
                    {{ $gettext('None in this period.') }}
                </div>
                <div v-else class="table-responsive landing-failures">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ $gettext('ID aired') }}</th>
                                <th>{{ $gettext('What happened') }}</th>
                                <th>{{ $gettext('What played before the ID') }}</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            <template
                                v-for="failure in report.failures"
                                :key="failure.id_at"
                            >
                                <tr>
                                    <td class="text-nowrap">{{ formatIsoAsDateTime(failure.id_at) }}</td>
                                    <td>
                                        <div
                                            v-for="line in failureLines(failure)"
                                            :key="line"
                                        >
                                            {{ line }}
                                        </div>
                                    </td>
                                    <td>{{ failure.title }}</td>
                                    <td class="text-end">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-link p-0"
                                            @click="openFailure = (openFailure === failure.id_at) ? null : failure.id_at"
                                        >
                                            {{ openFailure === failure.id_at ? $gettext('Hide') : $gettext('Show what aired') }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="openFailure === failure.id_at">
                                    <td colspan="4" class="bg-body-tertiary">
                                        <div
                                            v-for="item in failure.aired"
                                            :key="item.at"
                                            class="small d-flex gap-3"
                                            :class="{'fw-semibold': item.is_id}"
                                        >
                                            <span class="text-nowrap">{{ formatIsoAsTime(item.at) }}</span>
                                            <span class="flex-fill">{{ item.title }}</span>
                                            <span class="text-nowrap text-secondary">{{ onAirLabel(item.seconds) }}</span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="small text-secondary mt-2">
                    {{ $gettext('Calculated from your play history. Nothing extra is stored.') }}
                </div>
            </template>
        </loading>
    </div>
</template>

<script setup lang="ts">
import Loading from '~/components/Common/Loading.vue';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import type {DateRange} from '~/components/Stations/Reports/Overview/CommonMetricsView.vue';
import type {TopOfHourLanding, TopOfHourLandingDay, TopOfHourLandingFailure} from '~/entities/TopOfHour.ts';
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';
import {useAxios} from '~/vendor/axios.ts';
import {useTranslate} from '~/vendor/gettext';
import {useLuxon} from '~/vendor/luxon';
import {computed, ref, watch} from 'vue';

interface LandingBox {
    label: string;
    percent: number | null;
    /** For a box that is not a percentage: the number to show and its unit. */
    value?: string | null;
    unit?: string;
    /** For a box that lists a few items instead of one number. */
    list?: {text: string, count: number}[];
    detail: string;
    tone: string;
    trend?: {text: string, tone: string, title: string};
}

const props = defineProps<{
    apiUrl: string,
    dateRange?: DateRange,
}>();

const {axios} = useAxios();
const {$gettext} = useTranslate();
const {notifyError} = useNotify();
const {DateTime} = useLuxon();
const {formatIsoAsDateTime, formatIsoAsTime} = useStationDateTimeFormatter();

const report = ref<TopOfHourLanding | null>(null);
const isLoading = ref(false);
const days = ref(7);
const openFailure = ref<string | null>(null);

const dayOptions = [
    {value: 1, text: $gettext('Last 24 hours')},
    {value: 3, text: $gettext('Last 3 days')},
    {value: 7, text: $gettext('Last 7 days')},
    {value: 14, text: $gettext('Last 14 days')},
    {value: 30, text: $gettext('Last 30 days')},
];

const load = async () => {
    isLoading.value = true;
    try {
        const params = props.dateRange
            ? {
                start: DateTime.fromJSDate(props.dateRange.startDate).toISO(),
                end: DateTime.fromJSDate(props.dateRange.endDate).toISO(),
            }
            : {days: days.value};

        const {data} = await axios.get<TopOfHourLanding>(props.apiUrl, {params});
        report.value = data;
        openFailure.value = null;
    } catch {
        notifyError();
    } finally {
        isLoading.value = false;
    }
};

watch([days, () => props.dateRange], load, {immediate: true});

const ofHours = (count: number, total: number): string => $gettext(
    '%{count} of %{total} hours',
    {count: String(count), total: String(total)}
);

// Higher is better: green at the target, yellow within ten points of it.
const goodTone = (percent: number | null, target: number): string => {
    if (percent === null) return 'text-secondary';
    if (percent >= target) return 'text-success';
    return percent >= target - 10 ? 'text-warning' : 'text-danger';
};

const boxValue = (box: LandingBox): string | number | null => (box.unit !== undefined)
    ? (box.value ?? null)
    : box.percent;

const dayLabel = (date: string): string => DateTime.fromISO(date).toLocaleString(
    {weekday: 'short', month: 'short', day: 'numeric'}
);

// Blank on a normal day; names the ID problem on a day that had one.
const dayNote = (day: TopOfHourLandingDay): string => {
    const notes: string[] = [];
    if (day.id_early_count > 0) {
        notes.push($gettext('Early IDs: %{count}', {count: String(day.id_early_count)}));
    }
    if (day.id_late_count > 0) {
        notes.push($gettext('Late IDs: %{count}', {count: String(day.id_late_count)}));
    }
    return notes.join(', ');
};

const barTone = (percent: number | null, target: number): string => {
    if (percent === null) return 'bg-secondary';
    if (percent >= target) return 'bg-success';
    return percent >= target - 10 ? 'bg-warning' : 'bg-danger';
};

// Seconds that should stay small: green under the two-second miss line.
const secondsTone = (seconds: number | null): string => {
    if (seconds === null) return 'text-secondary';
    return seconds < 2 ? 'text-success' : 'text-warning';
};

// Should be zero.
const badTone = (count: number): string => (count > 0) ? 'text-warning' : 'text-success';

// Short groups share a row instead of each leaving half of one empty: a group
// is as wide as its boxes need, and every box keeps the same width.
const groupClass = (boxCount: number): string => `col-12 col-xl-${Math.min(12, boxCount * 3)}`;
const boxClass = (boxCount: number): string => `col-6 col-md-4 col-xl-${Math.max(3, Math.floor(12 / Math.min(4, boxCount)))}`;

const groups = computed<{title: string, boxes: LandingBox[]}[]>(() => {
    const r = report.value;
    if (!r) return [];

    const clean: LandingBox = {
        label: $gettext('Ended cleanly'),
        percent: r.clean_percent,
        detail: ofHours(r.clean_count, r.music_hours),
        tone: goodTone(r.clean_percent, r.target_percent),
    };
    if (r.clean_percent !== null && r.previous_clean_percent !== null) {
        const change = Math.round((r.clean_percent - r.previous_clean_percent) * 10) / 10;
        clean.trend = {
            text: (change >= 0 ? '▲ ' : '▼ ') + String(Math.abs(change)),
            tone: change >= 0 ? 'text-success' : 'text-danger',
            title: $gettext(
                'The period before this one: %{percent}% of %{total} hours',
                {percent: String(r.previous_clean_percent), total: String(r.previous_music_hours)}
            ),
        };
    }

    return [
        {
            title: $gettext('Before the ID'),
            boxes: [
                clean,
                {
                    label: $gettext('Cut or faded'),
                    percent: r.cut_percent,
                    detail: ofHours(r.cut_count, r.music_hours),
                    tone: badTone(r.cut_count),
                },
                {
                    label: $gettext('Songs cut most'),
                    percent: null,
                    value: r.cut_songs.length > 0 ? null : '0',
                    unit: '',
                    list: r.cut_songs.map((song) => ({text: song.title, count: song.count})),
                    detail: r.cut_songs.length > 0
                        ? $gettext('Cut or faded by the ID more than once')
                        : $gettext('No song was cut more than once'),
                    tone: 'text-success',
                },
                {
                    label: $gettext('Ended too soon (silence)'),
                    percent: r.early_percent,
                    detail: ofHours(r.early_count, r.music_hours),
                    tone: badTone(r.early_count),
                },
            ],
        },
        {
            title: $gettext('How the last song was fitted'),
            boxes: [
                {
                    label: $gettext('Swap worked'),
                    percent: r.swap_clean_percent,
                    detail: $gettext(
                        '%{count} of %{total} hours where the swap chose the last song',
                        {count: String(r.swap_clean_count), total: String(r.swap_hours)}
                    ),
                    tone: goodTone(r.swap_clean_percent, r.target_percent),
                },
                {
                    label: $gettext('Speed change worked'),
                    percent: r.tempo_clean_percent,
                    detail: $gettext(
                        '%{count} of %{total} hours where the song was sped up or slowed down',
                        {count: String(r.tempo_clean_count), total: String(r.tempo_hours)}
                    ),
                    tone: goodTone(r.tempo_clean_percent, r.target_percent),
                },
                {
                    label: $gettext('Promos back to back'),
                    percent: r.promo_stack_percent,
                    detail: ofHours(r.promo_stack_count, r.id_hours),
                    tone: badTone(r.promo_stack_count),
                },
                {
                    label: $gettext('Clean hours in a row'),
                    percent: null,
                    value: String(r.streak_current),
                    unit: '',
                    detail: $gettext('Right now. Best in this period: %{best}', {best: String(r.streak_best)}),
                    tone: r.streak_current > 0 ? 'text-success' : 'text-warning',
                },
            ],
        },
        {
            title: $gettext('The ID'),
            boxes: [
                {
                    label: $gettext('ID started early'),
                    percent: r.id_early_percent,
                    detail: $gettext(
                        '%{count} of %{total} hours (%{shows} after a show or feed)',
                        {
                            count: String(r.id_early_count),
                            total: String(r.id_hours),
                            shows: String(r.id_early_show_count),
                        }
                    ),
                    tone: badTone(r.id_early_count),
                },
                {
                    label: $gettext('ID started late'),
                    percent: r.id_late_percent,
                    detail: ofHours(r.id_late_count, r.id_hours),
                    tone: badTone(r.id_late_count),
                },
                {
                    label: $gettext('How far off the ID started'),
                    percent: null,
                    value: r.id_average_offset_seconds === null ? null : String(r.id_average_offset_seconds),
                    unit: 's',
                    detail: $gettext(
                        'Average distance from its set time. Furthest: %{worst}s',
                        {worst: String(r.id_worst_offset_seconds)}
                    ),
                    tone: secondsTone(r.id_average_offset_seconds),
                },
                {
                    label: $gettext('Next song started during the ID'),
                    percent: r.under_id_percent,
                    detail: ofHours(r.under_id_count, r.after_id_hours),
                    tone: badTone(r.under_id_count),
                },
            ],
        },
        {
            title: $gettext('After the ID'),
            boxes: [
                {
                    label: $gettext('Gap after the ID'),
                    percent: null,
                    value: r.after_id_average_gap_seconds === null ? null : String(r.after_id_average_gap_seconds),
                    unit: 's',
                    detail: $gettext(
                        'Average wait before the next item starts. Longest: %{worst}s',
                        {worst: String(r.after_id_worst_gap_seconds)}
                    ),
                    tone: secondsTone(r.after_id_average_gap_seconds),
                },
                {
                    label: $gettext('Cut song came back after the ID'),
                    percent: r.resumed_after_cut_percent,
                    detail: ofHours(r.resumed_after_cut_count, r.music_hours),
                    tone: badTone(r.resumed_after_cut_count),
                },
                {
                    label: $gettext('Same song played again after the ID'),
                    percent: r.replayed_percent,
                    detail: ofHours(r.replayed_count, r.music_hours),
                    tone: 'text-secondary',
                },
                {
                    label: $gettext('Next song started late'),
                    percent: r.late_start_percent,
                    detail: ofHours(r.late_start_count, r.after_id_hours),
                    tone: badTone(r.late_start_count),
                },
            ],
        },
    ];
});

const hourLabel = (hour: number): string => $gettext(
    '%{hour} hour',
    {hour: DateTime.fromObject({hour}).toLocaleString({hour: 'numeric'})}
);

const onAirLabel = (seconds: number): string => $gettext(
    '%{seconds}s on air',
    {seconds: String(Math.round(seconds))}
);

const failureLines = (failure: TopOfHourLandingFailure): string[] => {
    const whole = (value: number): string => String(Math.round(Math.abs(value)));
    const lines: string[] = [];

    switch (failure.kind) {
        case 'cut_short':
            lines.push($gettext('Cut short: %{seconds}s trimmed off the ending', {seconds: whole(failure.seconds)}));
            break;
        case 'cut_late_start':
            lines.push($gettext(
                'Cut by the ID: started too close to it, %{seconds}s lost',
                {seconds: whole(failure.seconds)}
            ));
            break;
        case 'cut_too_long':
            lines.push($gettext('Cut by the ID: %{seconds}s lost', {seconds: whole(failure.seconds)}));
            break;
        case 'ended_early':
            lines.push($gettext(
                'Ended %{seconds}s early, leaving a gap before the ID',
                {seconds: whole(failure.seconds)}
            ));
            break;
    }

    if (failure.resumed === 'after_cut') {
        lines.push($gettext('The cut song came back on air after the ID'));
    } else if (failure.resumed === 'replayed') {
        lines.push($gettext('The same song played again after the ID'));
    }

    if (failure.id_offset_seconds < 0) {
        lines.push($gettext('ID started %{seconds}s early', {seconds: whole(failure.id_offset_seconds)}));
    } else if (failure.id_offset_seconds > 0) {
        lines.push($gettext('ID started %{seconds}s late', {seconds: whole(failure.id_offset_seconds)}));
    }

    if (failure.late_start_seconds > 0) {
        lines.push($gettext(
            'Next song started %{seconds}s after the ID ended',
            {seconds: whole(failure.late_start_seconds)}
        ));
    }
    if (failure.under_id) {
        lines.push($gettext('Next item started while the ID was still on'));
    }
    if (failure.promo_stack) {
        lines.push($gettext('Two promos back to back before the ID'));
    }

    return lines;
};

const downloadCsv = () => {
    if (!report.value) return;

    const cell = (value: string | number): string => `"${String(value).replace(/"/g, '""')}"`;
    const rows = [
        ['ID aired', 'Before the ID', 'What happened', 'Seconds cut or gap', 'ID seconds off target', 'Seconds late after ID'],
        ...report.value.failures.map((failure) => [
            failure.id_at,
            failure.title,
            failureLines(failure).join('; '),
            failure.seconds,
            failure.id_offset_seconds,
            failure.late_start_seconds,
        ]),
    ];

    const blob = new Blob(
        [rows.map((row) => row.map(cell).join(',')).join('\r\n')],
        {type: 'text/csv;charset=utf-8'}
    );
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `top-of-hour-failures-${report.value.end.slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
};
</script>

<style scoped>
.landing-failures {
    max-height: 32rem;
    overflow-y: auto;
}

.landing-day {
    width: 6.5rem;
}

.landing-day-bar {
    height: 0.9rem;
}

.landing-day-value {
    width: 7.5rem;
    text-align: right;
}

.landing-day-note {
    width: 7rem;
}
</style>
