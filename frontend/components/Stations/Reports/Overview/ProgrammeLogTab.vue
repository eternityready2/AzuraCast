<template>
    <div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                :disabled="loading"
                @click="load"
            >
                {{ $gettext('Refresh') }}
            </button>
            <button
                type="button"
                class="btn btn-sm btn-outline-primary"
                :disabled="loading"
                @click="exportCsv"
            >
                {{ $gettext('Export CSV') }}
            </button>
        </div>

        <loading :loading="loading">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>{{ $gettext('Played at') }}</th>
                            <th>{{ $gettext('Title') }}</th>
                            <th>{{ $gettext('Artist') }}</th>
                            <th>{{ $gettext('Playlist') }}</th>
                            <th>{{ $gettext('Clock wheel') }}</th>
                            <th>{{ $gettext('Listeners') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="rows.length === 0">
                            <td
                                colspan="6"
                                class="text-center text-muted py-3"
                            >
                                {{ $gettext('No programme log entries.') }}
                            </td>
                        </tr>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                        >
                            <td class="text-nowrap small">{{ row.played_at }}</td>
                            <td>{{ row.title ?? '—' }}</td>
                            <td>{{ row.artist ?? '—' }}</td>
                            <td>{{ row.playlist ?? '—' }}</td>
                            <td>{{ row.clock_wheel ?? '—' }}</td>
                            <td>{{ row.listeners_start ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-if="total > rows.length"
                class="small text-muted mt-2 mb-0"
            >
                {{ $gettext('Showing') }} {{ rows.length }} / {{ total }}
            </p>
        </loading>
    </div>
</template>

<script setup lang="ts">
import {ref, watch} from 'vue';
import Loading from '~/components/Common/Loading.vue';
import {useAxios} from '~/vendor/axios.ts';
import {useTranslate} from '~/vendor/gettext';
import {DateRange} from '~/components/Stations/Reports/Overview/CommonMetricsView.vue';
import {useLuxon} from '~/vendor/luxon';

interface ProgrammeLogRow {
    id: number;
    played_at: string;
    title: string | null;
    artist: string | null;
    playlist: string | null;
    clock_wheel: string | null;
    listeners_start: number | null;
}

const props = defineProps<{
    apiUrl: string;
    dateRange: DateRange;
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {DateTime} = useLuxon();
const loading = ref(false);
const rows = ref<ProgrammeLogRow[]>([]);
const total = ref(0);

const load = async () => {
    loading.value = true;
    try {
        const {data} = await axios.get<{rows: ProgrammeLogRow[]; total: number}>(props.apiUrl, {
            params: {
                start: DateTime.fromJSDate(props.dateRange.startDate).toISO(),
                end: DateTime.fromJSDate(props.dateRange.endDate).toISO(),
                limit: 200,
            },
        });
        rows.value = data.rows ?? [];
        total.value = data.total ?? 0;
    } finally {
        loading.value = false;
    }
};

const exportCsv = () => {
    const start = DateTime.fromJSDate(props.dateRange.startDate).toISO() ?? '';
    const end = DateTime.fromJSDate(props.dateRange.endDate).toISO() ?? '';
    const separator = props.apiUrl.includes('?') ? '&' : '?';
    window.open(
        `${props.apiUrl}${separator}start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}&format=csv&limit=500`,
        '_blank',
    );
};

watch(
    () => [props.apiUrl, props.dateRange.startDate, props.dateRange.endDate],
    () => void load(),
    {immediate: true},
);
</script>
