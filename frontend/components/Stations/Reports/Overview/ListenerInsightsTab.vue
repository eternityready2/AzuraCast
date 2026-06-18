<template>
    <loading
        :loading="isLoading"
        lazy
    >
        <template v-if="state">
            <p
                v-if="state.analytics_exclude_bots"
                class="text-muted small"
            >
                {{ $gettext('Unique listener counts exclude detected bots/crawlers.') }}
            </p>

            <fieldset class="mb-4">
                <legend>
                    {{ $gettext('Session Breakdown (B5 — Bot Filtering)') }}
                </legend>

                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center">
                            <div class="fs-4 fw-semibold">
                                {{ state.session_breakdown.total_sessions }}
                            </div>
                            <div class="small text-muted">
                                {{ $gettext('Total sessions') }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center">
                            <div class="fs-4 fw-semibold">
                                {{ state.session_breakdown.human_sessions }}
                            </div>
                            <div class="small text-muted">
                                {{ $gettext('Human sessions') }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center">
                            <div class="fs-4 fw-semibold text-warning">
                                {{ state.session_breakdown.bot_sessions }}
                            </div>
                            <div class="small text-muted">
                                {{ $gettext('Bot sessions') }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center">
                            <div class="fs-4 fw-semibold">
                                {{ state.session_breakdown.bot_percent ?? '—' }}<span
                                    v-if="state.session_breakdown.bot_percent != null"
                                    class="fs-6"
                                >%</span>
                            </div>
                            <div class="small text-muted">
                                {{ $gettext('Bot share') }}
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>
                    {{ $gettext('Repeat Listeners (B7 — Loyalty)') }}
                </legend>

                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center">
                            <div class="fs-4 fw-semibold">
                                {{ state.loyalty.unique_listeners }}
                            </div>
                            <div class="small text-muted">
                                {{ $gettext('Unique listeners') }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center">
                            <div class="fs-4 fw-semibold">
                                {{ state.loyalty.repeat_listeners }}
                            </div>
                            <div class="small text-muted">
                                {{ $gettext('Repeat listeners') }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center">
                            <div class="fs-4 fw-semibold">
                                {{ state.loyalty.loyalty_rate_percent ?? '—' }}<span
                                    v-if="state.loyalty.loyalty_rate_percent != null"
                                    class="fs-6"
                                >%</span>
                            </div>
                            <div class="small text-muted">
                                {{ $gettext('Return rate') }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-2 text-center">
                            <div class="fs-4 fw-semibold">
                                {{ state.loyalty.avg_sessions_per_listener ?? '—' }}
                            </div>
                            <div class="small text-muted">
                                {{ $gettext('Avg sessions / listener') }}
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>
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

type ListenerInsightsData = {
    analytics_exclude_bots: boolean,
    session_breakdown: {
        total_sessions: number,
        human_sessions: number,
        bot_sessions: number,
        bot_percent: number | null,
    },
    loyalty: {
        unique_listeners: number,
        repeat_listeners: number,
        loyalty_rate_percent: number | null,
        avg_sessions_per_listener: number | null,
    },
};

const {data: state, isLoading} = useQuery<ListenerInsightsData>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationReports,
        'listener_insights',
        dateRange,
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<ListenerInsightsData>(props.apiUrl, {
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
