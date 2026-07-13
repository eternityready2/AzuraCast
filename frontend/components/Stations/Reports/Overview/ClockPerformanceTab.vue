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
                            {{ state.summary.tracks_queued }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Tracks queued') }}
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ state.summary.fallbacks }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Fallbacks') }}
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ state.summary.deferred }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Deferred') }}
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2 text-center">
                        <div class="fs-4 fw-semibold">
                            {{ state.summary.avg_drift ?? '—' }}
                        </div>
                        <div class="small text-muted">
                            {{ $gettext('Avg drift (sec)') }}
                        </div>
                    </div>
                </div>
            </div>

            <fieldset
                v-if="state.wheels.length > 0"
                class="mb-4"
            >
                <legend>
                    {{ $gettext('Performance by Clock Wheel') }}
                </legend>

                <table class="table table-striped table-condensed">
                    <thead>
                        <tr>
                            <th>{{ $gettext('Wheel') }}</th>
                            <th class="text-end">{{ $gettext('Queued') }}</th>
                            <th class="text-end">{{ $gettext('Fallbacks') }}</th>
                            <th class="text-end">{{ $gettext('Deferred') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="wheel in state.wheels"
                            :key="wheel.wheel_id ?? 'station'"
                        >
                            <td>{{ wheel.name }}</td>
                            <td class="text-end">{{ wheel.tracks_queued }}</td>
                            <td class="text-end">{{ wheel.fallbacks }}</td>
                            <td class="text-end">{{ wheel.deferred }}</td>
                        </tr>
                    </tbody>
                </table>
            </fieldset>

            <compliance-section
                v-if="(state.legal_id_compliance.hours_with_legal_id ?? 0) > 0"
                :title="$gettext('Legal ID compliance (all sources)')"
                :compliance="state.legal_id_compliance"
            />

            <compliance-section
                v-if="(state.top_of_hour_compliance.hours_with_legal_id ?? 0) > 0"
                :title="$gettext('Top of Hour compliance (station-wide)')"
                :compliance="state.top_of_hour_compliance"
            />
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
import ComplianceSection from "~/components/Stations/Reports/Overview/ClockComplianceSection.vue";

const props = defineProps<{
    dateRange: DateRange,
    apiUrl: string,
}>();

const dateRange = toRef(props, 'dateRange');
const {axios} = useAxios();
const {DateTime} = useLuxon();

type ComplianceSummary = {
    tolerance_seconds: number,
    hours_with_legal_id: number,
    on_time_count: number,
    late_count: number,
    compliance_percent: number | null,
    fallback_count?: number,
    late_events?: Array<{
        expected_play_at: string,
        actual_play_at: string | null,
        drift_seconds: number | null,
        media_id: number | null,
    }>,
};

type ClockPerformanceData = {
    summary: {
        tracks_queued: number,
        fallbacks: number,
        deferred: number,
        avg_drift: number | null,
    },
    wheels: Array<{
        wheel_id: number | null,
        name: string,
        tracks_queued: number,
        fallbacks: number,
        deferred: number,
    }>,
    legal_id_compliance: ComplianceSummary,
    top_of_hour_compliance: ComplianceSummary,
};

const {data: state, isLoading} = useQuery<ClockPerformanceData>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationReports,
        'clock_performance',
        dateRange,
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<ClockPerformanceData>(props.apiUrl, {
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
