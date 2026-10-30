<template>
    <div
        v-if="isVisible"
        :id="id"
        ref="$panel"
        class="card-body inline-form"
        :aria-busy="loading"
    >
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h4 mb-0">
                {{ title }}
            </h2>
            <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                @click="hide"
            >
                {{ $gettext('Back to Calendar') }}
            </button>
        </div>

        <div
            v-if="error"
            class="alert alert-danger"
        >
            {{ error }}
        </div>

        <form
            class="form vue-form"
            @submit.prevent="doSubmit"
        >
            <slot name="default" />
            <invisible-submit-button />
        </form>

        <div class="inline-form-footer d-flex flex-wrap align-items-center justify-content-end gap-2 border-top pt-3 mt-4">
            <slot
                name="modal-footer"
                :hide="hide"
            >
                <button
                    class="btn btn-secondary"
                    type="button"
                    @click="hide"
                >
                    {{ $gettext('Close') }}
                </button>
                <button
                    class="btn btn-primary"
                    :disabled="loading || disableSaveButton"
                    type="button"
                    @click="doSubmit"
                >
                    <slot name="save-button-name">
                        {{ $gettext('Save Changes') }}
                    </slot>
                </button>
            </slot>
        </div>
    </div>
</template>

<script setup lang="ts">
// Same props, slots, events and show()/hide() as ModalForm, but renders in
// place on the page instead of in a popup.
import InvisibleSubmitButton from "~/components/Common/InvisibleSubmitButton.vue";
import {nextTick, ref, useTemplateRef} from "vue";

withDefaults(
    defineProps<{
        title: string,
        id?: string,
        loading?: boolean,
        disableSaveButton?: boolean,
        error?: string | null,
    }>(),
    {
        id: 'edit-inline-form',
        loading: false,
        disableSaveButton: false,
    }
);

const emit = defineEmits<{
    (e: 'submit'): void,
    (e: 'shown'): void,
    (e: 'hidden'): void
}>();

const isVisible = ref(false);
const $panel = useTemplateRef('$panel');

const doSubmit = () => {
    emit('submit');
};

const show = () => {
    isVisible.value = true;
    emit('shown');
    void nextTick(() => {
        $panel.value?.scrollIntoView({behavior: 'smooth', block: 'start'});
    });
};

const hide = () => {
    if (!isVisible.value) {
        return;
    }
    isVisible.value = false;
    emit('hidden');
};

defineExpose({
    show,
    hide
});
</script>
