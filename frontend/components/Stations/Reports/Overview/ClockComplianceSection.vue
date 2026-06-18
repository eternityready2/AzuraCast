<template>
    <fieldset>
        <legend>
            {{ title }}
            <span class="text-muted fw-normal small">
                ({{ $gettext('tolerance') }}: {{ compliance.tolerance_seconds }}s)
            </span>
        </legend>

        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold">
                        {{ compliance.compliance_percent ?? '—' }}<span
                            v-if="compliance.compliance_percent != null"
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
                        {{ compliance.on_time_count }}
                    </div>
                    <div class="small text-muted">
                        {{ $gettext('Compliant hours') }}
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold text-warning">
                        {{ compliance.late_count }}
                    </div>
                    <div class="small text-muted">
                        {{ $gettext('Late (> tolerance)') }}
                    </div>
                </div>
            </div>
            <div
                v-if="compliance.fallback_count != null"
                class="col-6 col-md-3"
            >
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold text-danger">
                        {{ compliance.fallback_count }}
                    </div>
                    <div class="small text-muted">
                        {{ $gettext('Fallbacks') }}
                    </div>
                </div>
            </div>
        </div>

        <ul
            v-if="(compliance.late_events?.length ?? 0) > 0"
            class="list-group list-group-flush mb-3"
        >
            <li
                v-for="(ev, idx) in compliance.late_events"
                :key="idx"
                class="list-group-item px-0 small"
            >
                {{ $gettext('Expected') }}: {{ formatDateTime(ev.expected_play_at) }}
                · {{ $gettext('Actual') }}: {{ formatDateTime(ev.actual_play_at) }}
                · {{ $gettext('Drift') }}: {{ ev.drift_seconds ?? '—' }}s
            </li>
        </ul>
    </fieldset>
</template>

<script setup lang="ts">
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();

defineProps<{
    title: string,
    compliance: {
        tolerance_seconds: number,
        on_time_count: number,
        late_count: number,
        compliance_percent: number | null,
        fallback_count?: number,
        late_events?: Array<{
            expected_play_at: string,
            actual_play_at: string | null,
            drift_seconds: number | null,
        }>,
    },
}>();

const {formatIsoAsDateTime} = useStationDateTimeFormatter();

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    return formatIsoAsDateTime(value) || '—';
}
</script>
