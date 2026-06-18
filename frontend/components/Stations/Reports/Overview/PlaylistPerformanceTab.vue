<template>
    <loading
        :loading="isLoading"
        lazy
    >
        <fieldset v-if="state">
            <legend>
                {{ $gettext('Playlist Performance') }}
            </legend>

            <table
                v-if="state.playlists.length > 0"
                class="table table-striped table-condensed"
            >
                <thead>
                    <tr>
                        <th>{{ $gettext('Playlist') }}</th>
                        <th class="text-end">{{ $gettext('Plays') }}</th>
                        <th class="text-end">{{ $gettext('Avg Δ listeners') }}</th>
                        <th class="text-end">{{ $gettext('Avg unique') }}</th>
                        <th class="text-end">{{ $gettext('Tune-outs') }}</th>
                        <th class="text-end">{{ $gettext('Rotation equity') }}</th>
                        <th class="text-end">{{ $gettext('Min/Max plays') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in state.playlists"
                        :key="row.id"
                    >
                        <td>{{ row.name }}</td>
                        <td class="text-end">{{ row.play_count }}</td>
                        <td class="text-end">{{ formatNullable(row.avg_delta) }}</td>
                        <td class="text-end">{{ formatNullable(row.avg_unique_listeners) }}</td>
                        <td class="text-end">{{ row.tune_outs }}</td>
                        <td class="text-end">
                            <span v-if="row.rotation_equity_percent != null">
                                {{ row.rotation_equity_percent }}%
                            </span>
                            <span v-else>—</span>
                        </td>
                        <td class="text-end">
                            <span v-if="row.min_track_plays != null && row.max_track_plays != null">
                                {{ row.min_track_plays }} / {{ row.max_track_plays }}
                            </span>
                            <span v-else>—</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p
                v-else
                class="text-muted mb-0"
            >
                {{ $gettext('No playlist plays in this date range.') }}
            </p>
        </fieldset>
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

type PlaylistPerformanceData = {
    playlists: Array<{
        id: number,
        name: string,
        play_count: number,
        avg_delta: number | null,
        avg_unique_listeners: number | null,
        tune_outs: number,
        rotation_equity_percent: number | null,
        min_track_plays: number | null,
        max_track_plays: number | null,
    }>,
};

const {data: state, isLoading} = useQuery<PlaylistPerformanceData>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationReports,
        'playlist_performance',
        dateRange,
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<PlaylistPerformanceData>(props.apiUrl, {
            signal,
            params: {
                start: DateTime.fromJSDate(dateRange.value.startDate).toISO(),
                end: DateTime.fromJSDate(dateRange.value.endDate).toISO(),
            },
        });
        return data;
    },
    placeholderData: () => ({playlists: []}),
});

function formatNullable(value: number | null): string {
    return value == null ? '—' : String(value);
}
</script>
