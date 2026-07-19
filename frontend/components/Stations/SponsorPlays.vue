<template>
    <card-page header-id="hdr_sponsor_plays">
        <template #header="{id}">
            <h2
                :id="id"
                class="card-title my-0"
            >
                {{ $gettext('Sponsor Play Report') }}
            </h2>
        </template>

        <div class="card-body">
            <sponsor-plays-tab :api-url="apiUrl" />
        </div>
    </card-page>
</template>

<script setup lang="ts">
import CardPage from '~/components/Common/CardPage.vue';
import SponsorPlaysTab from '~/components/Stations/Reports/Overview/SponsorPlaysTab.vue';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useTranslate} from '~/vendor/gettext';

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const apiUrl = getStationApiUrl('/reports/overview/sponsor-plays');
</script>
