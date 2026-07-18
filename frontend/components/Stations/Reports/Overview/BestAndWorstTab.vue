<template>
    <loading
        :loading="isLoading"
        lazy
    >
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label" for="content_type_filter">
                    {{ $gettext('Content Type') }}
                </label>
                <select
                    id="content_type_filter"
                    v-model="contentType"
                    class="form-select"
                >
                    <option value="">
                        {{ $gettext('All Content') }}
                    </option>
                    <option value="music">
                        {{ $gettext('Music Only') }}
                    </option>
                    <option value="legal_id">
                        {{ $gettext('Legal IDs') }}
                    </option>
                    <option value="id">
                        {{ $gettext('IDs') }}
                    </option>
                    <option value="promo">
                        {{ $gettext('Promos') }}
                    </option>
                    <option value="ad">
                        {{ $gettext('Ads') }}
                    </option>
                    <option value="talk">
                        {{ $gettext('Talk') }}
                    </option>
                </select>
                <small class="text-muted">
                    {{ $gettext('Filter out station IDs and promos so they don\'t mix with music in these rankings.') }}
                </small>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-4">
                <fieldset>
                    <legend>
                        {{ $gettext('Best Performing Songs') }}
                    </legend>

                    <table class="table table-striped table-condensed table-nopadding">
                        <colgroup>
                            <col width="20%">
                            <col width="80%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>
                                    {{ $gettext('Change') }}
                                </th>
                                <th>
                                    {{ $gettext('Song') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody v-if="state">
                            <tr
                                v-for="row in state.bestAndWorst.best"
                                :key="row.song.id"
                            >
                                <td class=" text-center text-success">
                                    <icon-bi-chevron-up/>
                                    {{ row.stat_delta }}
                                    <br>
                                    <small>{{ row.stat_start }} to {{ row.stat_end }}</small>
                                </td>
                                <td>
                                    <song-text :song="row.song" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </fieldset>
            </div>
            <div class="col-md-6 mb-4">
                <fieldset>
                    <legend>
                        {{ $gettext('Worst Performing Songs') }}
                    </legend>

                    <table class="table table-striped table-condensed table-nopadding">
                        <colgroup>
                            <col width="20%">
                            <col width="80%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>
                                    {{ $gettext('Change') }}
                                </th>
                                <th>
                                    {{ $gettext('Song') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody v-if="state">
                            <tr
                                v-for="row in state.bestAndWorst.worst"
                                :key="row.song.id"
                            >
                                <td class="text-center text-danger">
                                    <icon-bi-chevron-down/>

                                    {{ row.stat_delta }}
                                    <br>
                                    <small>{{ row.stat_start }} to {{ row.stat_end }}</small>
                                </td>
                                <td>
                                    <song-text :song="row.song" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </fieldset>
            </div>
            <div class="col-md-12 mb-4">
                <fieldset>
                    <legend>
                        {{ $gettext('Most Played Songs') }}
                    </legend>

                    <table class="table table-striped table-condensed table-nopadding">
                        <colgroup>
                            <col width="10%">
                            <col width="90%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>
                                    {{ $gettext('Plays') }}
                                </th>
                                <th>
                                    {{ $gettext('Song') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody v-if="state">
                            <tr
                                v-for="row in state.mostPlayed"
                                :key="row.song.id"
                            >
                                <td class="text-center">
                                    {{ row.num_plays }}
                                </td>
                                <td>
                                    <song-text :song="row.song" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </fieldset>
            </div>
        </div>
    </loading>
</template>

<script setup lang="ts">
import {ref, toRef} from "vue";
import {useAxios} from "~/vendor/axios";
import SongText from "~/components/Stations/Reports/Overview/SongText.vue";
import Loading from "~/components/Common/Loading.vue";
import {useLuxon} from "~/vendor/luxon";
import {DateRange} from "~/components/Stations/Reports/Overview/CommonMetricsView.vue";
import {useQuery} from "@tanstack/vue-query";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import IconBiChevronDown from "~icons/bi/chevron-down";
import IconBiChevronUp from "~icons/bi/chevron-up";

const props = defineProps<{
    dateRange: DateRange,
    apiUrl: string,
}>();

const dateRange = toRef(props, 'dateRange');
const contentType = ref('');
const {axios} = useAxios();

const {DateTime} = useLuxon();

type StatsData = {
    bestAndWorst: {
        best: any[],
        worst: any[]
    },
    mostPlayed: any[]
}

const {data: state, isLoading} = useQuery<StatsData>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationReports,
        'best_and_worst',
        dateRange,
        contentType
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get(props.apiUrl, {
            signal,
            params: {
                start: DateTime.fromJSDate(dateRange.value.startDate).toISO(),
                end: DateTime.fromJSDate(dateRange.value.endDate).toISO(),
                content_type: contentType.value || undefined
            }
        });
        return data;
    },
    placeholderData: () => ({
        bestAndWorst: {
            best: [],
            worst: []
        },
        mostPlayed: []
    }),
});
</script>
