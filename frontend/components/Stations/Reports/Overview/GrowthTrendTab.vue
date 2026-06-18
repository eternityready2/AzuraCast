<template>
    <loading
        :loading="isLoading"
        lazy
    >
        <template v-if="state">
            <p class="text-muted small mb-3">
                {{ $gettext('Compares unique listeners per hour in the first half vs second half of the selected date range.') }}
            </p>

            <hour-chart
                style="width: 100%;"
                :data="chartMetrics"
                :labels="hourLabels"
                :alt="chartAlt"
                :aspect-ratio="3"
            />

            <table class="table table-sm table-striped mt-4">
                <thead>
                    <tr>
                        <th>{{ $gettext('Hour') }}</th>
                        <th class="text-end">{{ $gettext('First period') }}</th>
                        <th class="text-end">{{ $gettext('Second period') }}</th>
                        <th class="text-end">{{ $gettext('Growth') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in state.hourly"
                        :key="row.hour"
                    >
                        <td>{{ row.hour }}:00</td>
                        <td class="text-end">{{ row.first_period }}</td>
                        <td class="text-end">{{ row.second_period }}</td>
                        <td
                            class="text-end"
                            :class="growthClass(row.growth_percent)"
                        >
                            {{ formatGrowth(row.growth_percent) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </template>
    </loading>
</template>

<script setup lang="ts">
import {computed, toRef} from "vue";
import {useAxios} from "~/vendor/axios";
import Loading from "~/components/Common/Loading.vue";
import {useLuxon} from "~/vendor/luxon";
import HourChart from "~/components/Common/Charts/HourChart.vue";
import {DateRange} from "~/components/Stations/Reports/Overview/CommonMetricsView.vue";
import {useQuery} from "@tanstack/vue-query";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {useTranslate} from "~/vendor/gettext";

const props = defineProps<{
    dateRange: DateRange,
    apiUrl: string,
}>();

const {$gettext} = useTranslate();
const dateRange = toRef(props, 'dateRange');
const {axios} = useAxios();
const {DateTime} = useLuxon();

type GrowthTrendData = {
    hourly: Array<{
        hour: number,
        first_period: number,
        second_period: number,
        growth_percent: number | null,
    }>,
};

const {data: state, isLoading} = useQuery<GrowthTrendData>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationReports,
        'growth_trend',
        dateRange,
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<GrowthTrendData>(props.apiUrl, {
            signal,
            params: {
                start: DateTime.fromJSDate(dateRange.value.startDate).toISO(),
                end: DateTime.fromJSDate(dateRange.value.endDate).toISO(),
            },
        });
        return data;
    },
});

const hourLabels = computed(() =>
    (state.value?.hourly ?? []).map((row) => row.hour + ':00'),
);

const chartMetrics = computed(() => {
    if (!state.value) {
        return [];
    }

    return [
        {
            label: $gettext('First period'),
            data: state.value.hourly.map((row) => row.first_period),
        },
        {
            label: $gettext('Second period'),
            data: state.value.hourly.map((row) => row.second_period),
        },
    ];
});

const chartAlt = computed(() => ({
    label: $gettext('Hourly growth trend'),
    values: (state.value?.hourly ?? []).map((row) => ({
        label: row.hour + ':00',
        type: 'string',
        value: `${row.first_period} → ${row.second_period}`,
    })),
}));

function formatGrowth(value: number | null): string {
    if (value == null) {
        return '—';
    }

    return (value > 0 ? '+' : '') + value + '%';
}

function growthClass(value: number | null): string {
    if (value == null || value === 0) {
        return '';
    }

    return value > 0 ? 'text-success' : 'text-danger';
}
</script>
