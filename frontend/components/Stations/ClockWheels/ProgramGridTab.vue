<template>
    <div class="clock-wheel-program-grid">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div class="small text-muted">
                {{ weekLabel }}
            </div>
            <div class="btn-group btn-group-sm">
                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    @click="shiftWeek(-1)"
                >
                    {{ $gettext('Previous') }}
                </button>
                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    @click="shiftWeek(0)"
                >
                    {{ $gettext('This week') }}
                </button>
                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    @click="shiftWeek(1)"
                >
                    {{ $gettext('Next') }}
                </button>
            </div>
        </div>

        <loading :loading="loading">
            <div class="table-responsive">
                <table class="table table-sm table-bordered clock-wheel-program-grid__table mb-0">
                    <thead>
                        <tr>
                            <th class="text-uppercase small">
                                {{ $gettext('Hour') }}
                            </th>
                            <th
                                v-for="day in dayLabels"
                                :key="day.iso"
                                class="text-uppercase small text-center"
                            >
                                {{ day.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="hour in hours"
                            :key="hour"
                        >
                            <td class="text-nowrap small text-muted">
                                {{ formatHour(hour) }}
                            </td>
                            <td
                                v-for="day in dayLabels"
                                :key="day.iso + '-' + hour"
                                class="p-1"
                            >
                                <div
                                    v-if="cellFor(day.iso, hour)"
                                    class="clock-wheel-program-grid__cell rounded px-1 py-1 small text-truncate"
                                    :style="cellStyle(cellFor(day.iso, hour)!)"
                                    :title="cellTitle(cellFor(day.iso, hour)!)"
                                >
                                    {{ cellFor(day.iso, hour)!.wheel_name }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-2 mb-0">
                {{ $gettext('Calendar schedules override daypart wheels. Empty cells have no programmed wheel.') }}
            </p>
        </loading>
    </div>
</template>

<script setup lang="ts">
import {computed, ref, watch} from 'vue';
import Loading from '~/components/Common/Loading.vue';
import {useAxios} from '~/vendor/axios.ts';
import {useTranslate} from '~/vendor/gettext';
import {formatHourOfDayToAmPm} from '~/functions/amPmTime.ts';
import {DateTime} from 'luxon';
import {useStationData} from '~/functions/useStationQuery.ts';

interface GridCell {
    day_of_week: number;
    hour: number;
    wheel_id: number | null;
    wheel_name: string | null;
    wheel_color: string | null;
    source: string | null;
    daypart_name: string | null;
}

const props = defineProps<{
    gridUrl: string;
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const stationData = useStationData();

const loading = ref(false);
const cells = ref<GridCell[]>([]);
const weekStart = ref<DateTime>(DateTime.now().setZone(stationData.value.timezone).startOf('week'));

const hours = Array.from({length: 24}, (_, i) => i);

const dayLabels = computed(() => {
    const labels = [];
    for (let i = 0; i < 7; i++) {
        const dt = weekStart.value.plus({days: i});
        labels.push({
            iso: dt.weekday,
            label: dt.toFormat('ccc'),
        });
    }
    return labels;
});

const weekLabel = computed(() => {
    const end = weekStart.value.plus({days: 6});
    return `${weekStart.value.toFormat('MMM d')} – ${end.toFormat('MMM d, yyyy')}`;
});

const cellMap = computed(() => {
    const map = new Map<string, GridCell>();
    for (const cell of cells.value) {
        map.set(`${cell.day_of_week}_${cell.hour}`, cell);
    }
    return map;
});

const formatHour = formatHourOfDayToAmPm;

const cellFor = (dayOfWeek: number, hour: number): GridCell | null => {
    const cell = cellMap.value.get(`${dayOfWeek}_${hour}`);
    return cell?.wheel_name ? cell : null;
};

const cellStyle = (cell: GridCell) => ({
    backgroundColor: cell.wheel_color ?? '#cccccc',
    color: '#fff',
});

const cellTitle = (cell: GridCell) => {
    const parts = [cell.wheel_name ?? ''];
    if (cell.source === 'daypart' && cell.daypart_name) {
        parts.push(`(${cell.daypart_name})`);
    } else if (cell.source === 'schedule') {
        parts.push(`(${$gettext('Schedule')})`);
    }
    return parts.join(' ');
};

const loadGrid = async () => {
    loading.value = true;
    try {
        const {data} = await axios.get<{cells: GridCell[]}>(props.gridUrl, {
            params: {week: weekStart.value.toISODate()},
        });
        cells.value = data.cells ?? [];
    } finally {
        loading.value = false;
    }
};

const shiftWeek = (delta: number) => {
    if (delta === 0) {
        weekStart.value = DateTime.now()
            .setZone(stationData.value.timezone)
            .startOf('week');
    } else {
        weekStart.value = weekStart.value.plus({weeks: delta});
    }
};

watch(
    () => [props.gridUrl, weekStart.value.toISODate()],
    () => {
        void loadGrid();
    },
    {immediate: true},
);
</script>

<style lang="scss" scoped>
.clock-wheel-program-grid__table {
    th,
    td {
        vertical-align: middle;
    }
}

.clock-wheel-program-grid__cell {
    min-height: 1.5rem;
    line-height: 1.2;
}
</style>
