<template>
    <modal
        ref="$modal"
        :title="modalTitle"
    >
        <analytics-panel
            v-if="analyticsUrl"
            :key="openCount"
            :url="analyticsUrl"
        />
    </modal>
</template>

<script setup lang="ts">
import {computed, ref, useTemplateRef} from 'vue';
import Modal from '~/components/Common/Modal.vue';
import AnalyticsPanel from '~/components/Stations/ClockWheels/AnalyticsPanel.vue';
import {useTranslate} from '~/vendor/gettext';

export type {ClockWheelAnalyticsResponse} from '~/components/Stations/ClockWheels/AnalyticsPanel.vue';

const {$gettext} = useTranslate();

const $modal = useTemplateRef('$modal');
const wheelName = ref('');
const analyticsUrl = ref('');
const openCount = ref(0);

const modalTitle = computed(() =>
    wheelName.value
        ? $gettext('Analytics') + ': ' + wheelName.value
        : $gettext('Clock wheel analytics')
);

const open = (name: string, url: string) => {
    wheelName.value = name;
    analyticsUrl.value = url;
    openCount.value++;
    $modal.value?.show();
};

defineExpose({open});
</script>
