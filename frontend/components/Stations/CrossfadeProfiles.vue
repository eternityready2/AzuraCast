<template>
    <section
        class="card mb-4"
        role="region"
    >
        <div class="card-header text-bg-primary">
            <h2 class="card-title my-0">
                {{ $gettext('Content-Type Crossfade') }}
            </h2>
        </div>

        <div class="card-body">
            <loading :loading="loading">
                <form @submit.prevent="save">
                    <div class="form-check form-switch mb-3">
                        <form-checkbox
                            id="crossfade_enabled"
                            v-model="form.enabled"
                            class="form-check-input"
                        />
                        <label
                            class="form-check-label"
                            for="crossfade_enabled"
                        >
                            {{ $gettext('Enable content-type crossfade matrix') }}
                        </label>
                        <div class="form-text">
                            {{ $gettext('Apply fade_in/fade_out based on previous and current track media types. Top-of-hour ID quick-cut still applies for ID rows.') }}
                        </div>
                    </div>

                    <fieldset
                        v-if="form.enabled"
                        class="mt-4"
                    >
                        <legend>{{ $gettext('Transition matrix (seconds)') }}</legend>
                        <p class="text-muted small">
                            {{ $gettext('Keys are from_type:to_type. Leave blank to use station default crossfade.') }}
                        </p>

                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr>
                                        <th>{{ $gettext('Transition') }}</th>
                                        <th class="text-end">{{ $gettext('Fade in') }}</th>
                                        <th class="text-end">{{ $gettext('Fade out') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="key in matrixKeys"
                                        :key="key"
                                    >
                                        <td><code>{{ key }}</code></td>
                                        <td class="text-end">
                                            <input
                                                v-model.number="form.matrix[key].fade_in"
                                                type="number"
                                                min="0"
                                                max="30"
                                                step="0.5"
                                                class="form-control form-control-sm text-end"
                                                style="max-width: 5rem; margin-left: auto;"
                                            >
                                        </td>
                                        <td class="text-end">
                                            <input
                                                v-model.number="form.matrix[key].fade_out"
                                                type="number"
                                                min="0"
                                                max="30"
                                                step="0.5"
                                                class="form-control form-control-sm text-end"
                                                style="max-width: 5rem; margin-left: auto;"
                                            >
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </fieldset>

                    <div class="buttons mt-3">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :disabled="saving"
                        >
                            {{ $gettext('Save Changes') }}
                        </button>
                    </div>
                </form>
            </loading>
        </div>
    </section>
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from "vue";
import Loading from "~/components/Common/Loading.vue";
import FormCheckbox from "~/components/Form/FormCheckbox.vue";
import {useAxios} from "~/vendor/axios";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();

const apiUrl = getStationApiUrl('/crossfade-profiles');

type FadeEntry = { fade_in: number, fade_out: number };

type CrossfadeSettings = {
    enabled: boolean,
    matrix: Record<string, FadeEntry | null>,
    profiles: Record<string, Record<string, FadeEntry | null>>,
    content_types: string[],
    defaults: Record<string, FadeEntry>,
};

const loading = ref(true);
const saving = ref(false);

const form = ref({
    enabled: true,
    matrix: {} as Record<string, FadeEntry>,
});

const matrixKeys = computed(() => Object.keys(form.value.matrix).sort());

onMounted(() => loadSettings());

async function loadSettings() {
    loading.value = true;
    try {
        const {data} = await axios.get<CrossfadeSettings>(apiUrl.value);
        form.value.enabled = data.enabled;

        const matrix: Record<string, FadeEntry> = {};
        for (const [key, defaults] of Object.entries(data.defaults)) {
            const override = data.matrix[key];
            matrix[key] = override
                ? {fade_in: override.fade_in, fade_out: override.fade_out}
                : {fade_in: defaults.fade_in, fade_out: defaults.fade_out};
        }
        form.value.matrix = matrix;
    } catch {
        notifyError($gettext('Could not load crossfade settings.'));
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    try {
        await axios.put(apiUrl.value, {
            enabled: form.value.enabled,
            matrix: form.value.matrix,
        });
        notifySuccess($gettext('Crossfade settings saved.'));
    } catch {
        notifyError($gettext('Could not save crossfade settings.'));
    } finally {
        saving.value = false;
    }
}
</script>
