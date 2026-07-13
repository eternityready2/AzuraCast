<template>
    <loading
        :loading="isLoading"
        lazy
    >
        <fieldset v-if="state">
            <legend>
                {{ $gettext('Song Dropouts (< 30 seconds)') }}
            </legend>

            <p class="text-muted small">
                {{ $gettext('Songs where listeners disconnected within 30 seconds of the track starting.') }}
            </p>

            <table
                v-if="state.songs.length > 0"
                class="table table-striped table-condensed"
            >
                <thead>
                    <tr>
                        <th>{{ $gettext('Song') }}</th>
                        <th class="text-end">{{ $gettext('Plays') }}</th>
                        <th class="text-end">{{ $gettext('Dropouts') }}</th>
                        <th class="text-end">{{ $gettext('Dropout rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in state.songs"
                        :key="row.song.id"
                    >
                        <td>
                            <song-text :song="row.song" />
                        </td>
                        <td class="text-end">{{ row.play_count }}</td>
                        <td class="text-end">{{ row.dropout_count }}</td>
                        <td class="text-end">
                            <span v-if="row.dropout_rate_percent != null">
                                {{ row.dropout_rate_percent }}%
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
                {{ $gettext('No dropouts detected in this date range.') }}
            </p>
        </fieldset>
    </loading>
</template>

<script setup lang="ts">
import {toRef} from "vue";
import {useAxios} from "~/vendor/axios";
import Loading from "~/components/Common/Loading.vue";
import {useLuxon} from "~/vendor/luxon";
import SongText from "~/components/Stations/Reports/Overview/SongText.vue";
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

type DropoutData = {
    songs: Array<{
        song: any,
        play_count: number,
        dropout_count: number,
        dropout_rate_percent: number | null,
    }>,
};

const {data: state, isLoading} = useQuery<DropoutData>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationReports,
        'dropout',
        dateRange,
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<DropoutData>(props.apiUrl, {
            signal,
            params: {
                start: DateTime.fromJSDate(dateRange.value.startDate).toISO(),
                end: DateTime.fromJSDate(dateRange.value.endDate).toISO(),
            },
        });
        return data;
    },
    placeholderData: () => ({songs: []}),
});
</script>
