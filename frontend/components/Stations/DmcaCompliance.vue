<template>
    <div>
    <form @submit.prevent="saveSettings">
        <card-page header-id="hdr_dmca_compliance_settings">
            <template #header="{id}">
                <h2
                    :id="id"
                    class="card-title my-0"
                >
                    {{ $gettext('DMCA Compliance') }}
                </h2>
            </template>

            <loading :loading="isLoading" lazy>
                <div class="card-body">
                    <dmca-compliance-form v-model="settings" />
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

    <card-page
        class="mt-3"
        header-id="hdr_dmca_compliance_report"
    >
        <template #header="{id}">
            <h2
                :id="id"
                class="card-title my-0"
            >
                {{ $gettext('Compliance Report') }}
            </h2>
        </template>

        <div class="card-body">
            <dmca-compliance-tab :api-url="reportUrl" />
        </div>
    </card-page>
    </div>
</template>

<script setup lang="ts">
import CardPage from '~/components/Common/CardPage.vue';
import Loading from '~/components/Common/Loading.vue';
import DmcaComplianceForm from '~/components/Admin/Stations/Form/DmcaComplianceForm.vue';
import DmcaComplianceTab from '~/components/Stations/Reports/Overview/DmcaComplianceTab.vue';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios.ts';
import {onMounted, ref} from 'vue';

interface DmcaSettings {
    dmca_compliance_enabled: boolean;
    dmca_window_minutes: number;
    dmca_max_song_plays: number;
    dmca_max_consecutive_song: number;
    dmca_max_album_plays: number;
    dmca_max_artist_plays: number;
    dmca_max_consecutive_artist: number;
}

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();

const reportUrl = getStationApiUrl('/reports/overview/dmca-compliance');
const settingsUrl = getStationApiUrl('/dmca-compliance/settings');

const isLoading = ref(true);
const isSaving = ref(false);
const settings = ref<DmcaSettings>({
    dmca_compliance_enabled: false,
    dmca_window_minutes: 180,
    dmca_max_song_plays: 3,
    dmca_max_consecutive_song: 1,
    dmca_max_album_plays: 3,
    dmca_max_artist_plays: 4,
    dmca_max_consecutive_artist: 3,
});

const loadSettings = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<DmcaSettings>(settingsUrl.value);
        settings.value = data;
    } finally {
        isLoading.value = false;
    }
};

const saveSettings = async () => {
    isSaving.value = true;
    try {
        await axios.put(settingsUrl.value, settings.value);
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
