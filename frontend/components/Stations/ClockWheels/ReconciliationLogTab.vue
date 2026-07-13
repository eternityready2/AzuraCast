<template>
    <div class="clock-wheel-reconciliation">
        <div class="row g-2 mb-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">{{ $gettext('Event kind') }}</label>
                <select
                    v-model="eventKind"
                    class="form-select form-select-sm"
                    @change="reload"
                >
                    <option value="">
                        {{ $gettext('All') }}
                    </option>
                    <option value="track_queued">
                        {{ $gettext('Track queued') }}
                    </option>
                    <option value="deferred">
                        {{ $gettext('Deferred') }}
                    </option>
                    <option value="fallback">
                        {{ $gettext('Fallback') }}
                    </option>
                </select>
            </div>
            <div class="col-md-2">
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary w-100"
                    :disabled="loading"
                    @click="reload"
                >
                    {{ $gettext('Refresh') }}
                </button>
            </div>
        </div>

        <loading :loading="loading">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>{{ $gettext('Time') }}</th>
                            <th>{{ $gettext('Wheel') }}</th>
                            <th>{{ $gettext('Kind') }}</th>
                            <th>{{ $gettext('Type') }}</th>
                            <th>{{ $gettext('Code') }}</th>
                            <th>{{ $gettext('Drift') }}</th>
                            <th>{{ $gettext('Reason') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="rows.length === 0">
                            <td
                                colspan="7"
                                class="text-center text-muted py-3"
                            >
                                {{ $gettext('No reconciliation events.') }}
                            </td>
                        </tr>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                        >
                            <td class="text-nowrap small">
                                {{ formatTime(row.event_timestamp) }}
                            </td>
                            <td class="small">
                                {{ row.clock_wheel_name ?? '—' }}
                            </td>
                            <td>
                                <code class="small">{{ row.event_kind }}</code>
                            </td>
                            <td class="small">
                                {{ row.anchor_type ?? '—' }}
                            </td>
                            <td class="small">
                                {{ row.sound_code ?? '—' }}
                            </td>
                            <td class="small">
                                <span
                                    v-if="row.drift_seconds != null"
                                    :class="{'text-warning': Math.abs(row.drift_seconds) >= 5}"
                                >
                                    {{ row.drift_seconds }}s
                                </span>
                                <span v-else>—</span>
                            </td>
                            <td class="small">
                                <code v-if="row.fallback_reason">{{ row.fallback_reason }}</code>
                                <span v-else>—</span>
                            </td>
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
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';

interface ReconciliationRow {
    id: number;
    event_timestamp: string;
    event_kind: string;
    fallback_reason: string | null;
    clock_wheel_name: string | null;
    anchor_type: string | null;
    sound_code: string | null;
    drift_seconds: number | null;
}

const props = defineProps<{
    logUrl: string;
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {formatIsoAsDateTime} = useStationDateTimeFormatter();

const loading = ref(false);
const rows = ref<ReconciliationRow[]>([]);
const total = ref(0);
const eventKind = ref('');

const formatTime = (iso: string) => formatIsoAsDateTime(iso);

const reload = async () => {
    loading.value = true;
    try {
        const {data} = await axios.get<{rows: ReconciliationRow[]; total: number}>(props.logUrl, {
            params: {
                limit: 100,
                event_kind: eventKind.value || undefined,
            },
        });
        rows.value = data.rows ?? [];
        total.value = data.total ?? 0;
    } finally {
        loading.value = false;
    }
};

watch(
    () => props.logUrl,
    () => {
        void reload();
    },
    {immediate: true},
);
</script>
