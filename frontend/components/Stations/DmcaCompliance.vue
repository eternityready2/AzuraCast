<template>
    <card-page header-id="hdr_dmca_compliance">
        <template #header="{id}">
            <h2
                :id="id"
                class="card-title my-0"
            >
                {{ $gettext('DMCA Compliance') }}
            </h2>
        </template>

        <div class="card-body">
            <dmca-compliance-tab :api-url="apiUrl" />
        </div>
    </card-page>
</template>

<script setup lang="ts">
import CardPage from '~/components/Common/CardPage.vue';
import DmcaComplianceTab from '~/components/Stations/Reports/Overview/DmcaComplianceTab.vue';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useTranslate} from '~/vendor/gettext';

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const apiUrl = getStationApiUrl('/reports/overview/dmca-compliance');
</script>
