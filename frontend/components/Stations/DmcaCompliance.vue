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
                    <div v-if="loadError" class="alert alert-danger" role="alert">
                        <strong>{{ $gettext('The saved DMCA settings could not be loaded.') }}</strong>
                        {{ $gettext('What you see below are defaults, not your settings, so saving is disabled until the load succeeds. Your stored settings have not been changed.') }}
                        <div class="small mt-1">{{ loadError }}</div>
                    </div>
                    <dmca-compliance-form v-model="settings" />
                </div>
            </loading>

            <template #footer_actions>
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="isLoading || isSaving || !!loadError"
                >
                    {{ $gettext('Save Changes') }}
                </button>
            </template>
        </card-page>
    </form>

    <card-page
        class="mt-3"
        header-id="hdr_dmca_exempt_playlists"
    >
        <template #header="{id}">
            <h2
                :id="id"
                class="card-title my-0"
            >
                {{ $gettext('Not Covered by These Limits') }}
            </h2>
        </template>

        <div class="card-body">
            <p class="text-body-secondary">
                {{ $gettext('The DMCA performance limits govern sound recordings, so spoken-word programming is exempt. A show split across several episode files would otherwise count its own parts as repeated songs and be cut short. Nothing here is edited on this page: a file\'s type is set in Media, and the playlist flag on the playlist\'s own Basic Info tab.') }}
            </p>

            <loading :loading="isLoadingExempt" lazy>
                <p v-if="!exemptPlaylists.length" class="mb-0 fw-semibold">
                    {{ $gettext('Every playlist is currently subject to the limits. If a show is being cut short, set its files to Talk in Media, or tick Spoken-Word Programming on its playlist.') }}
                </p>

                <p v-else class="small fw-semibold">
                    {{ $gettext('Anything not listed here is still subject to the limits — including a show whose files are typed as music.') }}
                </p>

                <table v-if="exemptPlaylists.length" class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ $gettext('Playlist') }}</th>
                            <th>{{ $gettext('Exempt because') }}</th>
                            <th class="text-end">{{ $gettext('Files') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in exemptPlaylists" :key="row.id">
                            <td>
                                {{ row.name }}
                                <span v-if="!row.is_enabled" class="badge text-bg-secondary ms-1">
                                    {{ $gettext('Disabled') }}
                                </span>
                            </td>
                            <td>
                                <span v-if="row.scope === 'playlist'" class="badge text-bg-success me-1">
                                    {{ $gettext('Playlist flag') }}
                                </span>
                                <span v-else-if="row.scope === 'all_files'" class="badge text-bg-info me-1">
                                    {{ $gettext('File type') }}
                                </span>
                                <span v-else class="badge text-bg-warning me-1">
                                    {{ $gettext('Some files only') }}
                                </span>
                                <span class="small text-body-secondary">{{ exemptReason(row) }}</span>
                            </td>
                            <td class="text-end">
                                {{ row.non_music_files }} / {{ row.total_files }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </loading>
        </div>
    </card-page>

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
import {useTranslate} from '~/vendor/gettext.ts';
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

interface ExemptPlaylist {
    id: number;
    name: string;
    is_enabled: boolean;
    is_programme: boolean;
    total_files: number;
    non_music_files: number;
    scope: 'playlist' | 'all_files' | 'some_files';
}

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();

const reportUrl = getStationApiUrl('/reports/overview/dmca-compliance');
const exemptUrl = getStationApiUrl('/dmca-compliance/exempt-playlists');
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

const loadError = ref('');

const loadSettings = async () => {
    isLoading.value = true;
    loadError.value = '';
    try {
        const {data} = await axios.get<DmcaSettings>(settingsUrl.value);
        settings.value = data;
    } catch (error: unknown) {
        // Never leave the form showing its initialized defaults after a failed
        // load: an operator reads that as "compliance is off and my limits are
        // gone", and saving the form would then write those defaults over the
        // real settings. Say the load failed and keep Save out of reach.
        loadError.value = (error as {message?: string})?.message
            ?? $gettext('The saved DMCA settings could not be loaded.');
        notifyError();
    } finally {
        isLoading.value = false;
    }
};

const exemptPlaylists = ref<ExemptPlaylist[]>([]);
const isLoadingExempt = ref(true);

const loadExempt = async () => {
    isLoadingExempt.value = true;
    try {
        const {data} = await axios.get<{exempt: ExemptPlaylist[]}>(exemptUrl.value);
        exemptPlaylists.value = data.exempt ?? [];
    } catch {
        exemptPlaylists.value = [];
    } finally {
        isLoadingExempt.value = false;
    }
};

const exemptReason = (row: ExemptPlaylist): string => {
    if (row.scope === 'playlist') {
        return $gettext('Marked as spoken-word programming on the playlist.');
    }
    if (row.scope === 'all_files') {
        return $gettext('Every file in it is typed as talk, podcast or another non-music type.');
    }
    return $gettext('Only some files are non-music; the rest are still subject to the limits.');
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

onMounted(() => {
    void loadSettings();
    void loadExempt();
});
</script>
