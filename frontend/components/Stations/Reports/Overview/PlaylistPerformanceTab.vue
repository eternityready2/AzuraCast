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
                        <th class="text-end">{{ $gettext('Rotation goal') }}</th>
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
                            <span v-if="row.rotation_goal_days != null">
                                {{ row.rotation_goal_days }}d
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

        <template v-if="state">
            <fieldset class="mt-4">
                <legend>{{ $gettext('Repeats') }}</legend>
                <p class="small text-muted">
                    {{ $gettext('Songs that aired again within the station\'s %{m}-minute repeat window.', {m: String(state.repeat_window_minutes)}) }}
                </p>
                <div
                    v-if="state.repeats.length > 0"
                    class="table-responsive"
                >
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>{{ $gettext('Song') }}</th>
                                <th>{{ $gettext('First') }}</th>
                                <th>{{ $gettext('Again') }}</th>
                                <th class="text-end">{{ $gettext('Minutes apart') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, i) in state.repeats"
                                :key="i"
                            >
                                <td>{{ row.text }}</td>
                                <td class="text-nowrap">{{ formatTimestampAsDateTime(Math.round(Number(row.first_at))) }}</td>
                                <td class="text-nowrap">{{ formatTimestampAsDateTime(Math.round(Number(row.again_at))) }}</td>
                                <td class="text-end fw-semibold">{{ row.minutes_apart }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-else
                    class="text-success mb-0"
                >
                    {{ $gettext('No repeats in this date range.') }}
                </p>
            </fieldset>

            <fieldset class="mt-4">
                <legend>{{ $gettext('Most-played songs') }}</legend>
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>{{ $gettext('Song') }}</th>
                                <th>{{ $gettext('Playlist') }}</th>
                                <th class="text-end">{{ $gettext('Plays') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in state.over_played"
                                :key="row.media_id"
                            >
                                <td>{{ row.text }}</td>
                                <td>{{ row.playlist ?? '—' }}</td>
                                <td class="text-end fw-semibold">{{ row.plays }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </fieldset>

            <fieldset class="mt-4">
                <legend>{{ $gettext('Never played') }}</legend>
                <p class="small text-muted">
                    {{ $gettext('%{n} songs in enabled music playlists did not air at all in this date range.', {n: String(state.never_played_count)}) }}
                    <template v-if="state.never_played_count > state.never_played.length">
                        {{ $gettext('First %{n} shown.', {n: String(state.never_played.length)}) }}
                    </template>
                </p>
                <div
                    v-if="state.never_played.length > 0"
                    class="table-responsive"
                >
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>{{ $gettext('Song') }}</th>
                                <th>{{ $gettext('Playlist') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in state.never_played"
                                :key="row.media_id"
                            >
                                <td>{{ row.artist }} – {{ row.title }}</td>
                                <td>{{ row.playlist }}</td>
                            </tr>
                        </tbody>
                    </table>
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
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";

const props = defineProps<{
    dateRange: DateRange,
    apiUrl: string,
}>();

const dateRange = toRef(props, 'dateRange');
const {axios} = useAxios();
const {DateTime} = useLuxon();
const {formatTimestampAsDateTime} = useStationDateTimeFormatter();

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
        rotation_goal_days: number | null,
    }>,
    over_played: Array<{media_id: number, text: string, plays: number, playlist: string | null}>,
    never_played_count: number,
    never_played: Array<{media_id: number, artist: string | null, title: string | null, playlist: string}>,
    repeats: Array<{text: string, first_at: string, again_at: string, minutes_apart: string}>,
    repeat_window_minutes: number,
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
    placeholderData: () => ({
        playlists: [],
        over_played: [],
        never_played_count: 0,
        never_played: [],
        repeats: [],
        repeat_window_minutes: 120,
    }),
});

function formatNullable(value: number | null): string {
    return value == null ? '—' : String(value);
}
</script>
