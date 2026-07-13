<template>
    <loading
        :loading="isLoading"
        lazy
    >
        <template v-if="state">
            <p class="text-muted small mb-3">
                {{ $gettext('Percentage of sessions still active at each checkpoint (survival curve).') }}
            </p>

            <hour-chart
                style="width: 100%;"
                :data="chartData"
                :labels="chartLabels"
                :alt="chartAlt"
                :aspect-ratio="3"
            />

            <table class="table table-sm table-striped mt-4">
                <thead>
                    <tr>
                        <th>{{ $gettext('Minutes') }}</th>
                        <th class="text-end">{{ $gettext('Sessions') }}</th>
                        <th class="text-end">{{ $gettext('Retention') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in state.checkpoints"
                        :key="row.minute"
                    >
                        <td>{{ row.minute }}</td>
                        <td class="text-end">{{ row.listeners }}</td>
                        <td class="text-end">
                            <span v-if="row.percent != null">{{ row.percent }}%</span>
                            <span v-else>—</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="text-muted small mb-0">
                {{ $gettext('Based on') }} {{ state.total_sessions }} {{ $gettext('completed sessions in range.') }}
            </p>
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

type RetentionData = {
    total_sessions: number,
    checkpoints: Array<{
        minute: number,
        listeners: number,
        percent: number | null,
    }>,
};

const {data: state, isLoading} = useQuery<RetentionData>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationReports,
        'retention_curve',
        dateRange,
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<RetentionData>(props.apiUrl, {
            signal,
            params: {
                start: DateTime.fromJSDate(dateRange.value.startDate).toISO(),
                end: DateTime.fromJSDate(dateRange.value.endDate).toISO(),
            },
        });
        return data;
    },
});

const chartLabels = computed(() =>
    (state.value?.checkpoints ?? []).map((row) => row.minute + 'm'),
);

const chartData = computed(() => [{
    label: $gettext('Retention %'),
    data: (state.value?.checkpoints ?? []).map((row) => row.percent ?? 0),
}]);

const chartAlt = computed(() => ({
    label: $gettext('Retention curve'),
    values: (state.value?.checkpoints ?? []).map((row) => ({
        label: row.minute + 'm',
        type: 'string',
        value: (row.percent ?? 0) + '%',
    })),
}));
</script>
