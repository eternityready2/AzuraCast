<template>
    <loading
        :loading="isLoading"
        lazy
    >
        <template v-if="state">
            <p class="text-muted small mb-3">
                {{ $gettext('Unique listeners and sessions grouped by clock wheel daypart (session start hour).') }}
            </p>

            <table
                v-if="state.dayparts.length > 0"
                class="table table-striped table-condensed"
            >
                <thead>
                    <tr>
                        <th>{{ $gettext('Daypart') }}</th>
                        <th>{{ $gettext('Hours') }}</th>
                        <th class="text-end">{{ $gettext('Unique listeners') }}</th>
                        <th class="text-end">{{ $gettext('Sessions') }}</th>
                        <th class="text-end">{{ $gettext('Avg session') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in state.dayparts"
                        :key="row.daypart_id"
                    >
                        <td>{{ row.name }}</td>
                        <td>{{ formatHourRange(row.start_hour, row.end_hour) }}</td>
                        <td class="text-end">{{ row.unique_listeners }}</td>
                        <td class="text-end">{{ row.session_count }}</td>
                        <td class="text-end">{{ formatDuration(row.avg_session_seconds) }}</td>
                    </tr>
                </tbody>
            </table>

            <p
                v-else
                class="text-muted mb-0"
            >
                {{ $gettext('No clock wheel dayparts configured. Add dayparts under Clock Wheels to use this report.') }}
            </p>
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

const props = defineProps<{
    dateRange: DateRange,
    apiUrl: string,
}>();

const dateRange = toRef(props, 'dateRange');
const {axios} = useAxios();
const {DateTime} = useLuxon();

type DaypartAudienceData = {
    dayparts: Array<{
        daypart_id: number,
        name: string,
        start_hour: number,
        end_hour: number,
        unique_listeners: number,
        session_count: number,
        avg_session_seconds: number | null,
    }>,
};

const {data: state, isLoading} = useQuery<DaypartAudienceData>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationReports,
        'daypart_audience',
        dateRange,
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<DaypartAudienceData>(props.apiUrl, {
            signal,
            params: {
                start: DateTime.fromJSDate(dateRange.value.startDate).toISO(),
                end: DateTime.fromJSDate(dateRange.value.endDate).toISO(),
            },
        });
        return data;
    },
    placeholderData: () => ({dayparts: []}),
});

function formatHour(hour: number): string {
    const h = hour % 24;
    const suffix = h >= 12 ? 'PM' : 'AM';
    const display = h % 12 === 0 ? 12 : h % 12;

    return `${display} ${suffix}`;
}

function formatHourRange(start: number, end: number): string {
    return `${formatHour(start)} – ${formatHour(end)}`;
}

function formatDuration(seconds: number | null): string {
    if (seconds == null) {
        return '—';
    }

    const mins = Math.floor(seconds / 60);
    const secs = Math.round(seconds % 60);

    return `${mins}:${String(secs).padStart(2, '0')}`;
}
</script>
