<template>
    <form @submit.prevent="saveChanges">
        <card-page header-id="hdr_top_of_hour">
            <template #header="{id}">
                <h2
                    :id="id"
                    class="card-title my-0"
                >
                    {{ $gettext('Top of Hour Legal ID') }}
                </h2>
            </template>

            <info-card>
                <p class="mb-0">
                    {{
                        $gettext(
                            'Queues a Legal ID at exactly :00 without interrupting the current song. During the lookahead window, music picks are filtered so long songs do not run past the hour. Tag files as Legal ID on the Music Files page.'
                        )
                    }}
                </p>
            </info-card>

            <loading :loading="isLoading" lazy>
                <div class="card-body">
                    <form-group
                        id="top_of_hour_id_enabled"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Require Legal ID at top of hour') }}
                        </template>

                        <form-checkbox
                            id="top_of_hour_id_enabled"
                            v-model="form.top_of_hour_id_enabled"
                        />
                    </form-group>

                    <form-group
                        id="top_of_hour_lookahead_minutes"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Lookahead (minutes before :00)') }}
                        </template>

                        <input
                            id="top_of_hour_lookahead_minutes"
                            v-model.number="form.top_of_hour_lookahead_minutes"
                            type="number"
                            class="form-control"
                            min="1"
                            max="30"
                        >
                    </form-group>

                    <form-group
                        id="top_of_hour_finish_buffer_seconds"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Finish buffer (seconds before :00)') }}
                        </template>

                        <input
                            id="top_of_hour_finish_buffer_seconds"
                            v-model.number="form.top_of_hour_finish_buffer_seconds"
                            type="number"
                            class="form-control"
                            min="0"
                            max="30"
                        >
                    </form-group>

                    <form-group
                        id="top_of_hour_compliance_tolerance_seconds"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Compliance tolerance (seconds late = miss)') }}
                        </template>

                        <input
                            id="top_of_hour_compliance_tolerance_seconds"
                            v-model.number="form.top_of_hour_compliance_tolerance_seconds"
                            type="number"
                            class="form-control"
                            min="1"
                            max="60"
                        >
                    </form-group>

                    <form-group
                        id="top_of_hour_id_max_seconds"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Max ID length for scheduling (seconds)') }}
                        </template>

                        <input
                            id="top_of_hour_id_max_seconds"
                            v-model.number="form.top_of_hour_id_max_seconds"
                            type="number"
                            class="form-control"
                            min="15"
                            max="120"
                        >
                    </form-group>

                    <p class="text-secondary mb-0">
                        {{
                            $gettext(
                                'Legal ID files in library: %{count}',
                                {count: legalIdMediaCount}
                            )
                        }}
                    </p>
                </div>
            </loading>

            <template #footer_actions>
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="isLoading || isSaving"
                >
                    {{ $gettext('Save Changes') }}
                </button>
            </template>
        </card-page>
    </form>
</template>

<script setup lang="ts">
import CardPage from '~/components/Common/CardPage.vue';
import InfoCard from '~/components/Common/InfoCard.vue';
import Loading from '~/components/Common/Loading.vue';
import FormGroup from '~/components/Form/FormGroup.vue';
import FormCheckbox from '~/components/Form/FormCheckbox.vue';
import {useAxios} from '~/vendor/axios.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {onMounted, ref} from 'vue';

interface TopOfHourSettings {
    top_of_hour_id_enabled: boolean;
    top_of_hour_id_mode: string;
    top_of_hour_lookahead_minutes: number;
    top_of_hour_compliance_tolerance_seconds: number;
    top_of_hour_finish_buffer_seconds: number;
    top_of_hour_id_max_seconds: number;
    legal_id_media_count: number;
}

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();

const apiUrl = getStationApiUrl('/top-of-hour');

const isLoading = ref(true);
const isSaving = ref(false);
const legalIdMediaCount = ref(0);

const form = ref({
    top_of_hour_id_enabled: false,
    top_of_hour_id_mode: 'strict',
    top_of_hour_lookahead_minutes: 10,
    top_of_hour_compliance_tolerance_seconds: 10,
    top_of_hour_finish_buffer_seconds: 15,
    top_of_hour_id_max_seconds: 60,
});

const loadSettings = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<TopOfHourSettings>(apiUrl.value);
        form.value = {
            top_of_hour_id_enabled: data.top_of_hour_id_enabled,
            top_of_hour_id_mode: data.top_of_hour_id_mode,
            top_of_hour_lookahead_minutes: data.top_of_hour_lookahead_minutes,
            top_of_hour_compliance_tolerance_seconds: data.top_of_hour_compliance_tolerance_seconds,
            top_of_hour_finish_buffer_seconds: data.top_of_hour_finish_buffer_seconds,
            top_of_hour_id_max_seconds: data.top_of_hour_id_max_seconds,
        };
        legalIdMediaCount.value = data.legal_id_media_count ?? 0;
    } finally {
        isLoading.value = false;
    }
};

const saveChanges = async () => {
    isSaving.value = true;
    try {
        await axios.put(apiUrl.value, form.value);
        notifySuccess();
        await loadSettings();
    } catch {
        notifyError();
    } finally {
        isSaving.value = false;
    }
};

onMounted(loadSettings);
</script>
