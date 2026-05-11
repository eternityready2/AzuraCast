<template>
    <form
        class="form vue-form"
        @submit.prevent="saveChanges"
    >
        <section
            class="card"
            role="region"
            aria-labelledby="hdr_ai_news"
        >
            <div class="card-header text-bg-primary">
                <h2
                    id="hdr_ai_news"
                    class="card-title"
                >
                    {{ $gettext('AI News Bulletin') }}
                </h2>
            </div>

            <info-card>
                <p class="card-text">
                    {{ $gettext('Configure hourly AI-generated news bulletins for this station.') }}
                </p>
                <p class="card-text mb-0">
                    {{ $gettext('Active hours format: HH:MM-HH:MM. Leave blank to run all day. Source URLs should be one per line.') }}
                </p>
            </info-card>

            <loading :loading="isLoading" lazy>
                <div class="card-body">
                    <button
                        type="submit"
                        class="btn btn-primary mb-3"
                    >
                        {{ $gettext('Save Changes') }}
                    </button>

                    <button
                        type="button"
                        class="btn btn-secondary mb-3 ms-2"
                        :disabled="isTesting"
                        @click="runTest"
                    >
                        <span v-if="isTesting">{{ $gettext('Testing...') }}</span>
                        <span v-else>{{ $gettext('Generate Test Bulletin') }}</span>
                    </button>

                    <form-fieldset>
                        <form-group-checkbox
                            id="edit_ai_news_enabled"
                            :field="r$.ai_news_enabled"
                        >
                            <template #label>
                                {{ $gettext('Enable AI News Bulletin') }}
                            </template>
                        </form-group-checkbox>

                        <form-group-field
                            id="edit_ai_news_intro"
                            :field="r$.ai_news_intro"
                        >
                            <template #label>
                                {{ $gettext('Intro Script') }}
                            </template>
                            <template #default="{id, model}"
                            >
                                <textarea
                                    :id="id"
                                    v-model="model.$model"
                                    class="form-control"
                                    rows="4"
                                />
                            </template>
                        </form-group-field>

                        <form-group-field
                            id="edit_ai_news_source_urls"
                            :field="r$.ai_news_source_urls"
                        >
                            <template #label>
                                {{ $gettext('Source URLs (One Per Line)') }}
                            </template>
                            <template #default="{id, model}"
                            >
                                <textarea
                                    :id="id"
                                    v-model="model.$model"
                                    class="form-control"
                                    rows="5"
                                />
                            </template>
                        </form-group-field>

                        <form-group-field
                            id="edit_ai_news_active_hours"
                            :field="r$.ai_news_active_hours"
                        >
                            <template #label>
                                {{ $gettext('Active Hours') }}
                            </template>
                            <template #default="{id, model}"
                            >
                                <input
                                    :id="id"
                                    v-model="model.$model"
                                    class="form-control"
                                    type="text"
                                >
                            </template>
                        </form-group-field>

                        <form-group-field
                            id="edit_ai_news_voice_model_path"
                            :field="r$.ai_news_voice_model_path"
                        >
                            <template #label>
                                {{ $gettext('Voice Model Path') }}
                            </template>
                            <template #default="{id, model}"
                            >
                                <input
                                    :id="id"
                                    v-model="model.$model"
                                    class="form-control"
                                    type="text"
                                >
                            </template>
                        </form-group-field>
                    </form-fieldset>

                    <form-markup id="ai_news_last_status">
                        <template #label>
                            {{ $gettext('Last Generation Status') }}
                        </template>
                        <div>{{ statusText }}</div>
                    </form-markup>

                    <form-markup id="ai_news_last_time">
                        <template #label>
                            {{ $gettext('Last Generation Time') }}
                        </template>
                        <div>{{ timeText }}</div>
                    </form-markup>

                    <form-markup id="ai_news_last_error">
                        <template #label>
                            {{ $gettext('Last Error') }}
                        </template>
                        <div>{{ errorText }}</div>
                    </form-markup>
                </div>
            </loading>
        </section>
    </form>
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from "vue";
import FormFieldset from "~/components/Form/FormFieldset.vue";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormMarkup from "~/components/Form/FormMarkup.vue";
import InfoCard from "~/components/Common/InfoCard.vue";
import Loading from "~/components/Common/Loading.vue";
import mergeExisting from "~/functions/mergeExisting";
import {useResettableRef} from "~/functions/useResettableRef.ts";
import {useAxios} from "~/vendor/axios";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useMayNeedRestart} from "~/functions/useMayNeedRestart";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useAppRegle} from "~/vendor/regle.ts";
import {ApiStatus} from "~/entities/ApiInterfaces.ts";

interface AiNewsForm {
    ai_news_enabled: boolean;
    ai_news_intro: string | null;
    ai_news_source_urls: string | null;
    ai_news_active_hours: string | null;
    ai_news_voice_model_path: string | null;
}

interface AiNewsStatusPayload {
    ai_news_last_generation_status?: string | null;
    ai_news_last_generation_time?: string | null;
    ai_news_last_error?: string | null;
}

interface AiNewsResponse extends AiNewsForm, AiNewsStatusPayload {}

const {getStationApiUrl} = useApiRouter();
const apiUrl = getStationApiUrl('/ai-news');
const testUrl = getStationApiUrl('/ai-news/test');

const isLoading = ref(true);
const isTesting = ref(false);

const lastStatus = ref<string | null>(null);
const lastTime = ref<string | null>(null);
const lastError = ref<string | null>(null);

const {record: form, reset: resetForm} = useResettableRef<AiNewsForm>(() => ({
    ai_news_enabled: false,
    ai_news_intro: null,
    ai_news_source_urls: null,
    ai_news_active_hours: null,
    ai_news_voice_model_path: null
}));

const {r$} = useAppRegle(form, {}, {});

const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {mayNeedRestart} = useMayNeedRestart();

const statusText = computed(() => lastStatus.value ?? '—');
const timeText = computed(() => lastTime.value ?? '—');
const errorText = computed(() => lastError.value ?? '—');

const hydrateFromResponse = (data: AiNewsResponse) => {
    resetForm();
    r$.$reset();
    form.value = mergeExisting(form.value, data);

    lastStatus.value = data.ai_news_last_generation_status ?? null;
    lastTime.value = data.ai_news_last_generation_time ?? null;
    lastError.value = data.ai_news_last_error ?? null;
};

const relist = async () => {
    isLoading.value = true;

    try {
        const {data} = await axios.get<AiNewsResponse>(apiUrl.value);
        hydrateFromResponse(data);
    } finally {
        isLoading.value = false;
    }
};

onMounted(relist);

const saveChanges = async () => {
    const {valid} = await r$.$validate();
    if (!valid) {
        return;
    }

    const {data} = await axios.put<ApiStatus>(apiUrl.value, form.value);

    notifySuccess(data.message);
    mayNeedRestart();
    await relist();
};

const runTest = async () => {
    isTesting.value = true;

    try {
        const {data} = await axios.post<ApiStatus>(testUrl.value);
        notifySuccess(data.message);
        await relist();
    } catch {
        notifyError();
    } finally {
        isTesting.value = false;
    }
};
</script>
