<template>
    <input
        :id="inputId"
        v-model="displayText"
        type="text"
        class="form-control"
        :class="[fieldClass, {'is-invalid': showInvalid}]"
        :placeholder="placeholderText"
        :aria-label="ariaLabel"
        autocomplete="off"
        spellcheck="false"
        @input="onUserInput"
        @blur="commitFromDisplay"
        @keydown.enter.prevent="commitFromDisplay"
    >
</template>

<script setup lang="ts">
import {computed, ref, watch} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {
    formatHourOfDayToAmPm,
    formatTimeCodeToAmPm,
    parseHourOfDayFromAmPm,
    parseTimeCodeFromAmPm,
} from '~/functions/amPmTime.ts';

const props = withDefaults(
    defineProps<{
        modelValue?: number | null;
        /** `timeCode` = HHMM (schedule); `hour` = 0–23 whole hours (dayparts). */
        mode?: 'timeCode' | 'hour';
        inputId?: string;
        ariaLabel?: string;
        fieldClass?: string;
        placeholder?: string;
    }>(),
    {
        modelValue: null,
        mode: 'timeCode',
        inputId: undefined,
        ariaLabel: undefined,
        fieldClass: '',
        placeholder: undefined,
    }
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
}>();

const {$gettext} = useTranslate();
const displayText = ref('');
const showInvalid = ref(false);
const isEditing = ref(false);

const placeholderText = computed(
    () => props.placeholder ?? $gettext('e.g. 6:00 AM')
);

const formatModel = (value: number | null | undefined): string => {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '';
    }

    return props.mode === 'hour'
        ? formatHourOfDayToAmPm(value)
        : formatTimeCodeToAmPm(value);
};

watch(
    () => props.modelValue,
    (value) => {
        if (isEditing.value) {
            return;
        }

        displayText.value = formatModel(value);
        showInvalid.value = false;
    },
    {immediate: true}
);

const parseDisplay = (raw: string, markInvalid: boolean): number | null => {
    if (raw === '') {
        showInvalid.value = false;
        return null;
    }

    const parsed = props.mode === 'hour'
        ? parseHourOfDayFromAmPm(raw, true)
        : parseTimeCodeFromAmPm(raw);

    if (parsed === null) {
        if (markInvalid) {
            showInvalid.value = true;
        }
        return null;
    }

    showInvalid.value = false;
    return parsed;
};

const onUserInput = () => {
    isEditing.value = true;
    const parsed = parseDisplay(displayText.value.trim(), false);
    if (parsed !== null) {
        emit('update:modelValue', parsed);
    }
};

const commitFromDisplay = () => {
    isEditing.value = false;
    const raw = displayText.value.trim();
    const parsed = parseDisplay(raw, true);

    if (raw === '') {
        emit('update:modelValue', null);
        return;
    }

    if (parsed === null) {
        return;
    }

    emit('update:modelValue', parsed);
    displayText.value = formatModel(parsed);
};

</script>
