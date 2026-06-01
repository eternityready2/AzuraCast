<template>
    <modal
        ref="$modal"
        size="lg"
        :title="modalTitle"
    >
        <loading :loading="loading">
            <p
                v-if="preview?.hour_start"
                class="text-muted small"
            >
                {{ $gettext('Projected hour') }}: {{ formatIsoAsDateTime(preview.hour_start) }}
            </p>
            <div
                v-if="preview?.warnings?.length"
                class="alert alert-warning py-2 small"
            >
                <ul class="mb-0 ps-3">
                    <li
                        v-for="(warn, i) in preview.warnings"
                        :key="i"
                    >
                        {{ warn }}
                    </li>
                </ul>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>{{ $gettext('Position') }}</th>
                            <th>{{ $gettext('Type') }}</th>
                            <th>{{ $gettext('Projected track') }}</th>
                            <th>{{ $gettext('Drift') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, idx) in preview?.items ?? []"
                            :key="idx"
                        >
                            <td>{{ row.position_label }}</td>
                            <td>{{ row.slot_type }}</td>
                            <td>
                                <template v-if="row.title">
                                    <strong>{{ row.title }}</strong>
                                    <span
                                        v-if="row.artist"
                                        class="text-muted"
                                    > — {{ row.artist }}</span>
                                </template>
                                <span
                                    v-else
                                    class="text-muted fst-italic"
                                >—</span>
                                <ul
                                    v-if="row.warnings?.length"
                                    class="small text-warning mb-0 ps-3 mt-1"
                                >
                                    <li
                                        v-for="(w, wi) in row.warnings"
                                        :key="wi"
                                    >
                                        {{ w }}
                                    </li>
                                </ul>
                            </td>
                            <td>{{ row.drift_seconds }}s</td>
                        </tr>
                        <tr v-if="!preview?.items?.length && !loading">
                            <td
                                colspan="4"
                                class="text-muted text-center"
                            >
                                {{ $gettext('No projected slots for this hour.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-2 mb-0">
                {{
                    $gettext(
                        'Simulation only — does not queue tracks or write audit events. Shuffle order may differ on-air.'
                    )
                }}
            </p>
        </loading>
    </modal>
</template>

<script setup lang="ts">
import {computed, ref, useTemplateRef} from 'vue';
import Modal from '~/components/Common/Modal.vue';
import Loading from '~/components/Common/Loading.vue';
import {useTranslate} from '~/vendor/gettext';
import {useAxios} from '~/vendor/axios.ts';
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';

export interface ClockWheelPreviewItem {
    position_seconds: number;
    position_label: string;
    slot_type: string;
    title: string | null;
    artist: string | null;
    duration_seconds: number | null;
    drift_seconds: number;
    warnings: string[];
}

export interface ClockWheelPreviewResponse {
    hour_start: string;
    hour_start_timestamp: number;
    items: ClockWheelPreviewItem[];
    warnings: string[];
}

const {$gettext} = useTranslate();
const previewUrl = ref('');
const {axios} = useAxios();
const {formatIsoAsDateTime} = useStationDateTimeFormatter();

const $modal = useTemplateRef('$modal');
const loading = ref(false);
const wheelName = ref('');
const preview = ref<ClockWheelPreviewResponse | null>(null);

const modalTitle = computed(() =>
    wheelName.value
        ? $gettext('Next hour preview') + ': ' + wheelName.value
        : $gettext('Next hour preview')
);

const loadPreview = async () => {
    loading.value = true;
    try {
        const {data} = await axios.get<ClockWheelPreviewResponse>(previewUrl.value);
        preview.value = data;
    } finally {
        loading.value = false;
    }
};

const open = async (name: string, url: string) => {
    wheelName.value = name;
    previewUrl.value = url;
    preview.value = null;
    $modal.value?.show();
    await loadPreview();
};

defineExpose({open});
</script>
