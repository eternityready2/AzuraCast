<template>
    <loading
        :loading="isLoading"
        lazy
    >
        <div
            v-if="sponsors.length === 0"
            class="text-muted p-3"
        >
            {{ $gettext('No sponsor playlists configured yet. Mark a playlist as a sponsor spot in its Advanced settings to see it here.') }}
        </div>

        <div
            v-else
            class="row g-3 mb-4"
        >
            <div
                v-for="sponsor in sponsors"
                :key="sponsor.sponsor_name"
                class="col-md-4"
            >
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            {{ sponsor.sponsor_name }}
                        </h5>
                        <p class="card-text mb-1">
                            {{ $gettext('Plays in range:') }} <strong>{{ sponsor.total_plays_in_range }}</strong>
                        </p>
                        <p
                            v-if="sponsor.guaranteed_plays_per_day"
                            class="card-text text-muted small mb-0"
                        >
                            {{ $gettext('Guaranteed:') }} {{ sponsor.guaranteed_plays_per_day }}
                            {{ $gettext('plays/day') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <data-table
            id="sponsor_plays_table"
            paginated
            :fields="fields"
            :items="plays"
        >
            <template #cell(played_at)="row">
                {{ formatIsoAsDateTime(row.item.played_at) }}
            </template>
        </data-table>
    </loading>
</template>

<script setup lang="ts">
import {computed} from 'vue';
import {useAxios} from '~/vendor/axios';
import {useAsyncState} from '@vueuse/core';
import Loading from '~/components/Common/Loading.vue';
import DataTable, {DataTableField} from '~/components/Common/DataTable.vue';
import {useTranslate} from '~/vendor/gettext';
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';

const {$gettext} = useTranslate();
const {formatIsoAsDateTime} = useStationDateTimeFormatter();

const props = defineProps<{
    apiUrl: string;
}>();

const {axios} = useAxios();

const {state, isLoading} = useAsyncState(
    () => axios.get(props.apiUrl).then((r) => r.data),
    {sponsors: [], plays: []},
);

const sponsors = computed(() => state.value?.sponsors ?? []);
const plays = computed(() => state.value?.plays ?? []);

const fields: DataTableField<any>[] = [
    {key: 'played_at', label: $gettext('Played At'), sortable: true},
    {key: 'sponsor_name', label: $gettext('Sponsor'), sortable: true},
    {key: 'title', label: $gettext('Title'), sortable: false},
    {key: 'artist', label: $gettext('Artist'), sortable: false},
    {key: 'listeners', label: $gettext('Listeners'), sortable: true},
];
</script>
