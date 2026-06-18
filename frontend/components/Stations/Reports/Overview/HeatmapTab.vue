<template>
    <div class="buttons mb-3">
        <input
            id="heatmap-type-average"
            v-model="resultType"
            type="radio"
            class="btn-check"
            autocomplete="off"
            value="average"
        >
        <label
            class="btn btn-sm btn-outline-secondary"
            for="heatmap-type-average"
        >
            {{ $gettext('Average Listeners') }}
        </label>

        <input
            id="heatmap-type-unique"
            v-model="resultType"
            type="radio"
            class="btn-check"
            autocomplete="off"
            value="unique"
        >
        <label
            class="btn btn-sm btn-outline-secondary"
            for="heatmap-type-unique"
        >
            {{ $gettext('Unique Listeners') }}
        </label>
    </div>

    <loading
        :loading="isLoading"
        lazy
    >
        <fieldset v-if="state">
            <legend>
                {{ $gettext('Listener Heatmap (Day × Hour)') }}
            </legend>

            <p class="text-muted small">
                {{ $gettext('Darker cells indicate higher listener counts for that day and hour.') }}
            </p>

            <div class="table-responsive">
                <table class="table table-sm table-bordered text-center mb-0 heatmap-table">
                    <thead>
                        <tr>
                            <th class="text-start">
                                {{ $gettext('Day') }}
                            </th>
                            <th
                                v-for="hour in state.hour_labels"
                                :key="hour"
                                class="small"
                            >
                                {{ hour }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(dayLabel, dayIndex) in state.day_labels"
                            :key="dayLabel"
                        >
                            <th class="text-start text-nowrap">
                                {{ dayLabel }}
                            </th>
                            <td
                                v-for="(hour, hourIndex) in state.hour_labels"
                                :key="hour"
                                :style="cellStyle(state.cells[dayIndex]?.[hourIndex] ?? 0)"
                                :title="cellTitle(dayLabel, hour, state.cells[dayIndex]?.[hourIndex] ?? 0)"
                            >
                                {{ formatCellValue(state.cells[dayIndex]?.[hourIndex] ?? 0) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </fieldset>
    </loading>
</template>

<script setup lang="ts">
import {computed, ref, toRef} from "vue";
import {useAxios} from "~/vendor/axios";
import Loading from "~/components/Common/Loading.vue";
import {useLuxon} from "~/vendor/luxon";
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
const resultType = ref<'average' | 'unique'>('average');
const {axios} = useAxios();
const {DateTime} = useLuxon();

type HeatmapData = {
    metric: string,
    day_labels: string[],
    hour_labels: string[],
    cells: number[][],
    max_value: number,
};

const {data: state, isLoading} = useQuery<HeatmapData>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationReports,
        'heatmap',
        dateRange,
        resultType,
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<HeatmapData>(props.apiUrl, {
            signal,
            params: {
                start: DateTime.fromJSDate(dateRange.value.startDate).toISO(),
                end: DateTime.fromJSDate(dateRange.value.endDate).toISO(),
                type: resultType.value,
            },
        });
        return data;
    },
});

const maxValue = computed(() => state.value?.max_value ?? 0);

function cellIntensity(value: number): number {
    if (maxValue.value <= 0) {
        return 0;
    }

    return Math.min(1, value / maxValue.value);
}

function cellStyle(value: number): Record<string, string> {
    const intensity = cellIntensity(value);
    const alpha = 0.12 + (intensity * 0.75);

    return {
        backgroundColor: `rgba(13, 110, 253, ${alpha})`,
        color: intensity > 0.55 ? '#fff' : 'inherit',
    };
}

function formatCellValue(value: number): string {
    if (value <= 0) {
        return '—';
    }

    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

function cellTitle(day: string, hour: string, value: number): string {
    return `${day} ${hour}: ${formatCellValue(value)}`;
}
</script>

<style scoped>
.heatmap-table td {
    min-width: 2.25rem;
    font-size: 0.75rem;
    vertical-align: middle;
}
</style>
