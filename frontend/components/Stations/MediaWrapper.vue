<template>
    <loading :loading="propsLoading" lazy>
        <media v-if="props" v-bind="props"/>
    </loading>
</template>

<script setup lang="ts">
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {useAxios} from "~/vendor/axios.ts";
import {useQuery} from "@tanstack/vue-query";
import Loading from "~/components/Common/Loading.vue";
import Media from "~/components/Stations/Media.vue";
import {StationsVueFilesPropsRequired} from "~/entities/StationMedia.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";

const {getStationApiUrl} = useApiRouter();
const propsUrl = getStationApiUrl('/vue/files');

const {axios, axiosSilent} = useAxios();

type FilesProps = StationsVueFilesPropsRequired

let hasLoaded = false;

const {data: props, isLoading: propsLoading} = useQuery<FilesProps>({
    queryKey: queryKeyWithStation(
        [
            QueryKeys.StationMedia,
            'props'
        ]
    ),
    queryFn: async ({signal}) => {
        // Background refreshes stay quiet; only the first load reports errors.
        const client = hasLoaded ? axiosSilent : axios;
        const {data} = await client.get<FilesProps>(propsUrl.value, {signal});
        hasLoaded = true;
        return data;
    },
    // Playlists are often created in another tab; pick them up on return.
    refetchOnWindowFocus: true,
});
</script>
