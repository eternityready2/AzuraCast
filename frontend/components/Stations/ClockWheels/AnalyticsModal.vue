<template>
    <modal
        ref="$modal"
        :title="modalTitle"
    >
        <loading :loading="loading">
            <div class="mb-3">
                <label class="form-label">{{ $gettext('Lookback (days)') }}</label>
                <select
                    v-model.number="days"
                    class="form-select form-select-sm"
                    style="max-width: 8rem;"
                    @change="loadAnalytics"
                >
                    <option :value="7">
                        7
                    </option>
                    <option :value="14">
                        14
                    </option>
                    <option :value="30">
                        30
                    </option>
                    <option :value="60">
                        60
                    </option>
                    <option :value="90">
                        90
                    </option>
                </select>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6 col-md-4">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ analytics?.tracks_queued ?? 0 }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Tracks queued') }}
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ analytics?.fallbacks ?? 0 }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Fallbacks') }}
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ analytics?.deferred ?? 0 }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Deferred') }}
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ analytics?.avg_drift_seconds ?? '—' }}
                        </div>
                        <div
                            class="small text-muted"
                            :title="$gettext('Gap between target anchor position and actual play time. Expected when using graceful transitions (no hard cuts).')"
                        >
                            {{ $gettext('Avg Transition Offset') }}
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="analytics?.effectiveness_score != null"
                class="row g-3 mb-3"
            >
                <div class="col-6 col-md-4">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ analytics.effectiveness_score }}
                            <span class="fs-6 text-muted">/ 100</span>
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Effectiveness score') }}
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ analytics.effectiveness_grade ?? '—' }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Wheel grade') }}
                        </div>
                    </div>
                </div>
                <div
                    v-if="analytics.avg_listeners != null"
                    class="col-6 col-md-4"
                >
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ analytics.avg_listeners }}
                            <span
                                v-if="analytics.peak_listeners != null"
                                class="fs-6 text-muted"
                            >
                                ({{ $gettext('peak') }} {{ analytics.peak_listeners }})
                            </span>
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Avg listeners (hourly)') }}
                        </div>
                    </div>
                </div>
            </div>

            <template v-if="(analytics?.legal_id_hours_logged ?? 0) > 0">
                <h3 class="h6 mt-2">
                    {{ $gettext('Legal ID compliance') }}
                    <span class="text-muted fw-normal small">
                        ({{ $gettext('tolerance') }}: {{ analytics?.legal_id_tolerance_seconds ?? 10 }}s)
                    </span>
                </h3>
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center">
                            <div class="fs-4 fw-semibold">
                                {{ analytics?.legal_id_compliance_percent ?? '—' }}<span
                                    v-if="analytics?.legal_id_compliance_percent != null"
                                    class="fs-6"
                                >%</span>
                            </div>
                            <div class="small text-muted">
                                {{ $gettext('On time') }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center">
                            <div class="fs-4 fw-semibold">
                                {{ analytics?.legal_id_on_time_count ?? 0 }}
                            </div>
                            <div class="small text-muted">
                                {{ $gettext('Compliant hours') }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center">
                            <div class="fs-4 fw-semibold text-warning">
                                {{ analytics?.legal_id_late_count ?? 0 }}
                            </div>
                            <div class="small text-muted">
                                {{ $gettext('Late (> tolerance)') }}
                            </div>
                        </div>
                    </div>
                </div>
                <ul
                    v-if="(analytics?.legal_id_late_events?.length ?? 0) > 0"
                    class="list-group list-group-flush mb-3"
                >
                    <li
                        v-for="(ev, idx) in analytics?.legal_id_late_events"
                        :key="idx"
                        class="list-group-item px-0 small"
                    >
                        {{ $gettext('Expected') }}: {{ ev.expected_play_at }}
                        · {{ $gettext('Actual') }}: {{ ev.actual_play_at ?? '—' }}
                        · Δ {{ ev.drift_seconds ?? '—' }}s
                    </li>
                </ul>
            </template>

            <template v-if="fallbackEntries.length > 0">
                <h3 class="h6">
                    {{ $gettext('Fallback reasons') }}
                </h3>
                <ul class="list-group list-group-flush mb-0">
                    <li
                        v-for="entry in fallbackEntries"
                        :key="entry.reason"
                        class="list-group-item px-0 d-flex justify-content-between"
                    >
                        <code class="small">{{ entry.reason }}</code>
                        <span>{{ entry.count }}</span>
                    </li>
                </ul>
            </template>
            <p
                v-else-if="!loading"
                class="text-muted small mb-0"
            >
                {{ $gettext('No fallback events in this period.') }}
            </p>
        </loading>
    </modal>
</template>

<script setup lang="ts">
import {computed, ref, useTemplateRef} from 'vue';
import Modal from '~/components/Common/Modal.vue';
import Loading from '~/components/Common/Loading.vue';
import {useTranslate} from '~/vendor/gettext';
import {useAxios} from '~/vendor/axios.ts';

export interface ClockWheelAnalyticsResponse {
    days: number;
    tracks_queued: number;
    deferred: number;
    fallbacks: number;
    avg_drift_seconds: number | null;
    separation_relaxed_count: number;
    burn_rate_warning_count: number;
    fallback_reasons: Record<string, number>;
    legal_id_tolerance_seconds?: number;
    legal_id_hours_logged?: number;
    legal_id_on_time_count?: number;
    legal_id_late_count?: number;
    legal_id_compliance_percent?: number | null;
    legal_id_late_events?: {
        expected_play_at: string;
        actual_play_at: string | null;
        drift_seconds: number | null;
        media_id: number | null;
    }[];
    effectiveness_score?: number | null;
    effectiveness_grade?: string | null;
    avg_listeners?: number | null;
    peak_listeners?: number | null;
}

const {$gettext} = useTranslate();
const analyticsBaseUrl = ref('');
const {axios} = useAxios();

const $modal = useTemplateRef('$modal');
const loading = ref(false);
const wheelName = ref('');
const days = ref(7);
const analytics = ref<ClockWheelAnalyticsResponse | null>(null);

const modalTitle = computed(() =>
    wheelName.value
        ? $gettext('Analytics') + ': ' + wheelName.value
        : $gettext('Clock wheel analytics')
);

const fallbackEntries = computed(() => {
    const reasons = analytics.value?.fallback_reasons ?? {};
    return Object.entries(reasons)
        .map(([reason, count]) => ({reason, count}))
        .sort((a, b) => b.count - a.count);
});

const loadAnalytics = async () => {
    loading.value = true;
    try {
        const {data} = await axios.get<ClockWheelAnalyticsResponse>(analyticsBaseUrl.value, {
            params: {days: days.value},
        });
        analytics.value = data;
    } finally {
        loading.value = false;
    }
};

const open = async (name: string, url: string) => {
    wheelName.value = name;
    analyticsBaseUrl.value = url;
    analytics.value = null;
    days.value = 7;
    $modal.value?.show();
    await loadAnalytics();
};

defineExpose({open});
</script>
