<template>
    <common-metrics-view
        :date-range="dateRange"
        :api-url="apiUrl"
        field-key="country"
        :field-label="$gettext('Country')"
    >
        <template #by_listeners_legend>
            {{ $gettext('Top Countries by Listeners') }}
        </template>
        <template #by_connected_time_legend>
            {{ $gettext('Top Countries by Connected Time') }}
        </template>
        <template #cell_country="row">
            {{ row.item.country }}
            <span
                v-if="row.item.is_new_country"
                class="badge text-bg-success ms-2"
                :title="$gettext('First-ever listener from this country in your station\'s history.')"
            >
                {{ $gettext('NEW') }}
            </span>
        </template>
    </common-metrics-view>
</template>

<script setup lang="ts">
import CommonMetricsView, {DateRange} from "~/components/Stations/Reports/Overview/CommonMetricsView.vue";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();

defineProps<{
    dateRange: DateRange,
    apiUrl: string,
}>();
</script>
