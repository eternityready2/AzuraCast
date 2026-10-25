<template>
    <loading
        :loading="isLoading"
        lazy
    >
        <template v-if="state">
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ formatPercent(state.summary.compliance_percent) }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Log aired as planned') }}
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ formatPercent(state.summary.hours_clean_percent) }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Hours landed cleanly on the ID') }}
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div
                            class="fs-4 fw-semibold"
                            :class="{'text-danger': state.summary.gaps > 0}"
                        >
                            {{ state.summary.gaps }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Overruns / possible dead air') }}
                            ({{ formatLength(state.summary.gap_seconds) }})
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ state.summary.live_picks }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Songs AutoDJ filled in (no log line ready)') }}
                        </div>
                    </div>
                </div>
            </div>

            <fieldset class="mb-4">
                <legend>{{ $gettext('Planned vs Aired') }}</legend>
                <p class="small text-muted">
                    {{ $gettext('Every planned Linear Log line in the date range, by what happened to it at air time.') }}
                </p>
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>{{ $gettext('Day') }}</th>
                                <th class="text-end">{{ $gettext('As planned') }}</th>
                                <th class="text-end">{{ $gettext('Swapped') }}</th>
                                <th class="text-end">{{ $gettext('Replaced') }}</th>
                                <th class="text-end">{{ $gettext('Dropped') }}</th>
                                <th class="text-end">{{ $gettext('AutoDJ fill-ins') }}</th>
                                <th class="text-end">{{ $gettext('Compliance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="day in state.days"
                                :key="day.date"
                            >
                                <td>{{ day.date }}</td>
                                <td class="text-end">{{ day.as_planned }}</td>
                                <td class="text-end">{{ day.swapped }}</td>
                                <td class="text-end">{{ day.replaced }}</td>
                                <td class="text-end">{{ day.dropped }}</td>
                                <td class="text-end">{{ day.live_picks }}</td>
                                <td class="text-end fw-semibold">{{ formatPercent(day.compliance_percent) }}</td>
                            </tr>
                            <tr v-if="state.days.length === 0">
                                <td
                                    colspan="7"
                                    class="text-muted text-center"
                                >
                                    {{ $gettext('No Linear Log lines in this date range.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </fieldset>

            <fieldset
                v-if="state.reasons.length > 0"
                class="mb-4"
            >
                <legend>{{ $gettext('Why lines were dropped or replaced') }}</legend>
                <ul class="list-group list-group-flush">
                    <li
                        v-for="row in state.reasons"
                        :key="row.reason"
                        class="list-group-item px-0 d-flex justify-content-between gap-3"
                    >
                        <span class="small">{{ row.reason }}</span>
                        <span class="fw-semibold">{{ row.count }}</span>
                    </li>
                </ul>
            </fieldset>

            <fieldset class="mb-4">
                <legend>{{ $gettext('Landing on the Top-of-Hour ID') }}</legend>
                <p class="small text-muted">
                    {{ $gettext('How the last item before each ID ended. Clean: within %{s}s of the ID. Cut: the ID cut more than %{c}s off it.', {s: String(state.tolerance_seconds), c: String(state.cut_tolerance_seconds)}) }}
                </p>
                <p class="mb-2">
                    {{ $gettext('Clean') }}: <strong>{{ state.summary.hours_clean }}</strong>
                    · {{ $gettext('Ended early (gap before ID)') }}: <strong>{{ state.summary.hours_early }}</strong>
                    · {{ $gettext('Cut by the ID') }}: <strong :class="{'text-danger': state.summary.hours_cut > 0}">{{ state.summary.hours_cut }}</strong>
                    · {{ $gettext('Hours checked') }}: {{ state.summary.hours_landed }}
                </p>
                <div
                    v-if="state.landing_misses.length > 0"
                    class="table-responsive"
                >
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>{{ $gettext('Hour') }}</th>
                                <th>{{ $gettext('Problem') }}</th>
                                <th>{{ $gettext('Item before the ID') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="miss in state.landing_misses"
                                :key="miss.hour"
                            >
                                <td class="text-nowrap">{{ formatTimestampAsDateTime(miss.hour) }}</td>
                                <td class="text-nowrap">
                                    <span
                                        v-if="miss.kind === 'cut'"
                                        class="text-danger"
                                    >{{ $gettext('Cut by') }} {{ formatLength(-miss.gap_seconds) }}</span>
                                    <span
                                        v-else
                                        class="text-warning"
                                    >{{ formatLength(miss.gap_seconds) }} {{ $gettext('before the ID') }}</span>
                                </td>
                                <td>{{ miss.previous }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </fieldset>

            <fieldset class="mb-4">
                <legend>{{ $gettext('Overruns and possible dead air') }}</legend>
                <p class="small text-muted">
                    {{ $gettext('Items that held the air more than %{s}s longer than their own length: the time after them was silence, a held transport or the wrong audio. Relayed programme streams are not counted.', {s: String(state.gap_threshold_seconds)}) }}
                </p>
                <div
                    v-if="state.gap_list.length > 0"
                    class="table-responsive"
                >
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>{{ $gettext('From') }}</th>
                                <th class="text-end">{{ $gettext('Length') }}</th>
                                <th>{{ $gettext('After') }}</th>
                                <th>{{ $gettext('Before') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="gap in state.gap_list"
                                :key="gap.at"
                            >
                                <td class="text-nowrap">{{ formatTimestampAsDateTime(gap.at) }}</td>
                                <td class="text-end text-nowrap fw-semibold">{{ formatLength(gap.seconds) }}</td>
                                <td>{{ gap.after }}</td>
                                <td>{{ gap.before }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-else
                    class="text-success mb-0"
                >
                    {{ $gettext('None in this date range.') }}
                </p>
            </fieldset>
        </template>
    </loading>
</template>

<script setup lang="ts">
import {toRef} from "vue";
import {useAxios} from "~/vendor/axios";
import Loading from "~/components/Common/Loading.vue";
import {useLuxon} from "~/vendor/luxon";
import {DateRange} from "~/components/Stations/Reports/Overview/CommonMetricsView.vue";
import {useQuery} from "@tanstack/vue-query";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";

const props = defineProps<{
    dateRange: DateRange,
    apiUrl: string,
}>();

type DayRow = {
    date: string,
    as_planned: number,
    swapped: number,
    replaced: number,
    dropped: number,
    live_picks: number,
    compliance_percent: number | null,
};

type LogComplianceData = {
    summary: {
        planned_lines: number,
        compliance_percent: number | null,
        as_planned: number,
        swapped: number,
        replaced: number,
        dropped: number,
        live_picks: number,
        hours_landed: number,
        hours_clean: number,
        hours_clean_percent: number | null,
        hours_early: number,
        hours_cut: number,
        gaps: number,
        gap_seconds: number,
    },
    tolerance_seconds: number,
    cut_tolerance_seconds: number,
    gap_threshold_seconds: number,
    days: DayRow[],
    reasons: Array<{reason: string, count: number}>,
    landing_misses: Array<{hour: number, id_at: number, previous: string, gap_seconds: number, kind: 'gap' | 'cut'}>,
    gap_list: Array<{at: number, seconds: number, after: string, before: string}>,
};

const dateRange = toRef(props, 'dateRange');
const {axios} = useAxios();
const {DateTime} = useLuxon();
const {formatTimestampAsDateTime} = useStationDateTimeFormatter();

const formatPercent = (value: number | null): string => (null === value ? '—' : `${value}%`);
const formatLength = (seconds: number): string => {
    const s = Math.max(0, Math.round(seconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = String(s % 60).padStart(2, '0');
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
};

const {data: state, isLoading} = useQuery<LogComplianceData>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationReports,
        'log_compliance',
        dateRange,
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<LogComplianceData>(props.apiUrl, {
            signal,
            params: {
                start: DateTime.fromJSDate(dateRange.value.startDate).toISO(),
                end: DateTime.fromJSDate(dateRange.value.endDate).toISO(),
            },
        });
        return data;
    },
});
</script>
