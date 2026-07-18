<template>
    <modal
        id="generate_clock_wheel_modal"
        ref="$modal"
        size="lg"
        :title="$gettext('Auto Format Clock Generator')"
        @hidden="onHidden"
    >
        <div class="mb-3">
            <small class="text-muted">
                {{
                    $gettext('Set goals for the hour and generate a starting clock wheel layout. The result is a normal, fully editable wheel -- adjust anything afterward.')
                }}
            </small>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="gen_name">{{ $gettext('Wheel Name') }}</label>
                <input
                    id="gen_name"
                    v-model="form.name"
                    type="text"
                    class="form-control"
                    :placeholder="$gettext('Generated Clock Wheel')"
                >
            </div>

            <div class="col-md-6">
                <label class="form-label" for="gen_music_percent">
                    {{ $gettext('Music % of Hour') }}
                </label>
                <input
                    id="gen_music_percent"
                    v-model.number="form.music_percent"
                    type="number"
                    class="form-control"
                    min="0"
                    max="100"
                >
            </div>

            <div class="col-md-6">
                <div class="form-check mt-2">
                    <input
                        id="gen_id_at_top"
                        v-model="form.id_at_top"
                        class="form-check-input"
                        type="checkbox"
                    >
                    <label class="form-check-label" for="gen_id_at_top">
                        {{ $gettext('Station ID at top of hour (0:00)') }}
                    </label>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="gen_category">{{ $gettext('Music Category (optional)') }}</label>
                <select
                    id="gen_category"
                    v-model="form.music_category_id"
                    class="form-select"
                >
                    <option :value="null">
                        {{ $gettext('All Categories') }}
                    </option>
                    <option
                        v-for="opt in categoryOptions"
                        :key="opt.value"
                        :value="opt.value"
                    >
                        {{ opt.text }}
                    </option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="gen_promos">{{ $gettext('Promo positions (mm)') }}</label>
                <input
                    id="gen_promos"
                    v-model="promoMinutes"
                    type="text"
                    class="form-control"
                    placeholder="30"
                >
                <small class="text-muted">{{ $gettext('Comma-separated minutes, e.g. 15, 30, 45') }}</small>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="gen_ads">{{ $gettext('Ad positions (mm)') }}</label>
                <input
                    id="gen_ads"
                    v-model="adMinutes"
                    type="text"
                    class="form-control"
                    placeholder=""
                >
                <small class="text-muted">{{ $gettext('Leave blank for none') }}</small>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="gen_talk">{{ $gettext('Talk positions (mm)') }}</label>
                <input
                    id="gen_talk"
                    v-model="talkMinutes"
                    type="text"
                    class="form-control"
                    placeholder=""
                >
                <small class="text-muted">{{ $gettext('Leave blank for none') }}</small>
            </div>
        </div>

        <div
            v-if="error"
            class="alert alert-danger mt-3 mb-0"
        >
            {{ error }}
        </div>

        <template #modal-footer>
            <button
                type="button"
                class="btn btn-secondary"
                @click="close"
            >
                {{ $gettext('Close') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="loading"
                @click="doGenerate"
            >
                {{ loading ? $gettext('Generating...') : $gettext('Generate Wheel') }}
            </button>
        </template>
    </modal>
</template>

<script setup lang="ts">
import {ref, useTemplateRef} from 'vue';
import Modal from '~/components/Common/Modal.vue';
import {useAxios} from '~/vendor/axios.ts';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useClockWheelSlotOptions} from '~/functions/useClockWheelSlotOptions.ts';

const props = defineProps<{
    generateUrl: string;
}>();

const emit = defineEmits<{
    (e: 'generated'): void;
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess} = useNotify();
const {categoryOptions, load: loadCategories} = useClockWheelSlotOptions();

const $modal = useTemplateRef('$modal');

const loading = ref(false);
const error = ref<string | null>(null);

const form = ref({
    name: '',
    music_percent: 75,
    id_at_top: true,
    music_category_id: null as number | null,
});

const promoMinutes = ref('30');
const adMinutes = ref('');
const talkMinutes = ref('');

const parseMinutesToSeconds = (input: string): number[] =>
    input
        .split(',')
        .map((piece) => piece.trim())
        .filter((piece) => piece !== '')
        .map((piece) => Math.round(parseFloat(piece) * 60))
        .filter((seconds) => Number.isFinite(seconds) && seconds >= 0 && seconds < 3600);

const doGenerate = async () => {
    loading.value = true;
    error.value = null;

    try {
        await axios.post(props.generateUrl, {
            name: form.value.name || undefined,
            music_percent: form.value.music_percent,
            id_at_top: form.value.id_at_top,
            music_category_id: form.value.music_category_id,
            promo_positions: parseMinutesToSeconds(promoMinutes.value),
            ad_positions: parseMinutesToSeconds(adMinutes.value),
            talk_positions: parseMinutesToSeconds(talkMinutes.value),
        });

        notifySuccess($gettext('Clock wheel generated. It is fully editable like any other wheel.'));
        emit('generated');
        close();
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        error.value = err?.response?.data?.message ?? $gettext('An error occurred while generating.');
    } finally {
        loading.value = false;
    }
};

const onHidden = () => {
    error.value = null;
};

const open = () => {
    void loadCategories();
    $modal.value?.show();
};

const close = () => {
    $modal.value?.hide();
};

defineExpose({open, close});
</script>
