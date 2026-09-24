<template>
    <form @submit.prevent="saveChanges">
        <card-page header-id="hdr_playout_controls">
            <template #header="{id}">
                <h2
                    :id="id"
                    class="card-title my-0"
                >
                    {{ $gettext('Playout Controls') }}
                </h2>
            </template>

            <info-card>
                <p class="mb-0">
                    {{ $gettext('Advanced stretch/squeeze and audio-ducking controls. Top-of-hour timing is handled on the Top of Hour ID page.') }}
                </p>
            </info-card>

            <loading :loading="isLoading" lazy>
                <div class="card-body">
                    <h3 class="h6">
                        {{ $gettext('Stretch / Squeeze') }}
                    </h3>
                    <p class="text-secondary small">
                        {{ $gettext('Speeds up or slows down a track slightly when AutoDJ backtimes music into a protected boundary. This also raises or lowers pitch (like a turntable), so keep it at 2-3% to stay unnoticeable. This is station-wide and applies to standard rotation playlists, Smart Blocks and Clock Wheels when their selected track has stretch/squeeze timing metadata.') }}
                    </p>

                    <form-group id="stretch_squeeze_enabled" class="mb-3">
                        <template #label>
                            {{ $gettext('Enable stretch / squeeze') }}
                        </template>
                        <form-checkbox id="stretch_squeeze_enabled" v-model="form.stretch_squeeze_enabled" />
                    </form-group>

                    <template v-if="form.stretch_squeeze_enabled">
                        <form-group id="stretch_max_percent" class="mb-3">
                            <template #label>
                                {{ $gettext('Maximum stretch (%)') }}
                            </template>
                            <input
                                id="stretch_max_percent"
                                v-model.number="form.stretch_max_percent"
                                type="number"
                                class="form-control"
                                min="0.5"
                                max="5"
                                step="0.5"
                            >
                            <div class="form-text">
                                {{ $gettext('How much AutoDJ may slow a track down (extend its duration) to reach a timing target. Hard limit is 5%.') }}
                            </div>
                        </form-group>

                        <form-group id="squeeze_max_percent" class="mb-3">
                            <template #label>
                                {{ $gettext('Maximum squeeze (%)') }}
                            </template>
                            <input
                                id="squeeze_max_percent"
                                v-model.number="form.squeeze_max_percent"
                                type="number"
                                class="form-control"
                                min="0.5"
                                max="5"
                                step="0.5"
                            >
                            <div class="form-text">
                                {{ $gettext('How much AutoDJ may speed a track up (shorten its duration) to reach a timing target. Hard limit is 5%. Configured separately from stretch because listeners can perceive the two directions differently.') }}
                            </div>
                        </form-group>
                    </template>

                    <hr class="my-4">

                    <h3 class="h6">
                        {{ $gettext('Smart Ducking') }}
                    </h3>
                    <p class="text-secondary small">
                        {{ $gettext('Lowers the music bed while supported interrupting audio plays, then restores it smoothly.') }}
                    </p>

                    <form-group id="smart_duck_enabled" class="mb-3">
                        <template #label>
                            {{ $gettext('Enable smart ducking') }}
                        </template>
                        <form-checkbox id="smart_duck_enabled" v-model="form.smart_duck_enabled" />
                    </form-group>

                    <template v-if="form.smart_duck_enabled">
                        <form-group id="smart_duck_attenuation" class="mb-3">
                            <template #label>
                                {{ $gettext('Music bed level while ducked (0 = silent, 1 = full volume)') }}
                            </template>
                            <input
                                id="smart_duck_attenuation"
                                v-model.number="form.smart_duck_attenuation"
                                type="number"
                                class="form-control"
                                min="0"
                                max="1"
                                step="0.05"
                            >
                        </form-group>

                        <form-group id="smart_duck_delay" class="mb-3">
                            <template #label>
                                {{ $gettext('Ducking fade time (seconds)') }}
                            </template>
                            <input
                                id="smart_duck_delay"
                                v-model.number="form.smart_duck_delay"
                                type="number"
                                class="form-control"
                                min="0.5"
                                max="15"
                                step="0.5"
                            >
                        </form-group>
                    </template>

                    <div class="alert alert-info mb-0">
                        {{ $gettext('Ducking configuration changes take effect after broadcasting is restarted. Stretch / Squeeze does not require a broadcasting restart; new settings apply to newly planned AutoDJ items while items already in the queue keep their existing timing plan.') }}
                    </div>
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
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import type {PlayoutControlsSettings} from '~/entities/PlayoutControls.ts';
import {useAxios} from '~/vendor/axios.ts';
import {onMounted, ref} from 'vue';

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();

const apiUrl = getStationApiUrl('/playout-controls');
const isLoading = ref(true);
const isSaving = ref(false);

const form = ref<PlayoutControlsSettings>({
    stretch_squeeze_enabled: true,
    stretch_squeeze_max_percent: 5,
    stretch_max_percent: 5,
    squeeze_max_percent: 5,
    smart_duck_enabled: false,
    smart_duck_attenuation: 0.2,
    smart_duck_delay: 3,
});

const loadSettings = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<PlayoutControlsSettings>(apiUrl.value);
        form.value = {
            stretch_squeeze_enabled: data.stretch_squeeze_enabled ?? true,
            stretch_squeeze_max_percent: data.stretch_squeeze_max_percent ?? 5,
            stretch_max_percent: data.stretch_max_percent ?? 5,
            squeeze_max_percent: data.squeeze_max_percent ?? 5,
            smart_duck_enabled: data.smart_duck_enabled ?? false,
            smart_duck_attenuation: data.smart_duck_attenuation ?? 0.2,
            smart_duck_delay: data.smart_duck_delay ?? 3,
        };
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
