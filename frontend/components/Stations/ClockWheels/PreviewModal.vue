<template>
    <modal
        ref="$modal"
        size="lg"
        :title="modalTitle"
    >
        <wheel-log-panel
            v-if="previewUrl"
            :key="openCount"
            :url="previewUrl"
            mode="upcoming"
        />
    </modal>
</template>

<script setup lang="ts">
import {computed, ref, useTemplateRef} from 'vue';
import Modal from '~/components/Common/Modal.vue';
import WheelLogPanel from '~/components/Stations/ClockWheels/WheelLogPanel.vue';
import {useTranslate} from '~/vendor/gettext';

const {$gettext} = useTranslate();

const $modal = useTemplateRef('$modal');
const wheelName = ref('');
const previewUrl = ref('');
const openCount = ref(0);

const modalTitle = computed(() =>
    wheelName.value
        ? $gettext('Preview') + ': ' + wheelName.value
        : $gettext('Preview')
);

const open = (name: string, url: string) => {
    wheelName.value = name;
    previewUrl.value = url;
    openCount.value++;
    $modal.value?.show();
};

defineExpose({open});
</script>
