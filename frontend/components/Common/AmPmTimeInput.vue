<template>
    <div class="am-pm-time-input">
        <input
            :id="inputId"
            ref="inputEl"
            v-model="displayText"
            type="text"
            class="form-control"
            :class="[fieldClass, {'is-invalid': showInvalid}]"
            :placeholder="placeholderText"
            :aria-label="ariaLabel"
            :list="datalistId"
            inputmode="numeric"
            autocomplete="off"
            spellcheck="false"
            maxlength="11"
            @input="onUserInput"
            @blur="commitFromDisplay"
            @keydown.enter.prevent="commitFromDisplay"
            @keydown="onKeydown"
        >
        <datalist :id="datalistId">
            <option
                v-for="suggestion in suggestions"
                :key="suggestion"
                :value="suggestion"
            />
        </datalist>
        <div
            v-if="showHint"
            class="form-text"
        >
            {{ hintText }}
        </div>
    </div>
</template>

<script setup lang="ts">
import {computed, ref, watch} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {
    buildAmPmTimeSuggestions,
    formatAmPmTimeAsYouType,
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
const inputEl = ref<HTMLInputElement>();
const displayText = ref('');
const showInvalid = ref(false);
const isEditing = ref(false);
const showHint = ref(false);

const wholeHourOnly = computed(() => props.mode === 'hour');

const datalistId = computed(
    () => (props.inputId ? `${props.inputId}_suggestions` : undefined)
);

const suggestions = computed(() =>
    buildAmPmTimeSuggestions(wholeHourOnly.value)
);

const placeholderText = computed(
    () => props.placeholder ?? $gettext('Type 930a → 9:30 AM')
);

const hintText = computed(() =>
    wholeHourOnly.value
        ? $gettext('Whole hours: type 6a → 6:00 AM, or pick from suggestions.')
        : $gettext('Type digits + a/p: 930a → 9:30 AM, 1200p → 12:00 PM.')
);

const formatModel = (value: number | null | undefined): string => {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '';
    }

    return wholeHourOnly.value
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
        showHint.value = false;
    },
    {immediate: true}
);

const applyFormattedInput = (raw: string, moveCursorToEnd = true) => {
    const state = formatAmPmTimeAsYouType(raw, wholeHourOnly.value);
    displayText.value = state.display;
    showInvalid.value = false;
    showHint.value = state.display !== '' && !state.complete;

    if (state.complete && state.parsed !== null) {
        emit('update:modelValue', state.parsed);
    }

    if (moveCursorToEnd && inputEl.value) {
        requestAnimationFrame(() => {
            const el = inputEl.value;
            if (el) {
                const len = displayText.value.length;
                el.setSelectionRange(len, len);
            }
        });
    }

    return state;
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'a' || event.key === 'A') {
        event.preventDefault();
        applyFormattedInput(displayText.value + 'a');
        return;
    }
    if (event.key === 'p' || event.key === 'P') {
        event.preventDefault();
        applyFormattedInput(displayText.value + 'p');
    }
};

const onUserInput = () => {
    isEditing.value = true;
    applyFormattedInput(displayText.value);
};

const commitFromDisplay = () => {
    isEditing.value = false;
    showHint.value = false;
    const raw = displayText.value.trim();

    if (raw === '') {
        showInvalid.value = false;
        emit('update:modelValue', null);
        return;
    }

    const state = formatAmPmTimeAsYouType(raw, wholeHourOnly.value);
    if (state.complete && state.parsed !== null) {
        showInvalid.value = false;
        emit('update:modelValue', state.parsed);
        displayText.value = formatModel(state.parsed);
        return;
    }

    const parsed = wholeHourOnly.value
        ? parseHourOfDayFromAmPm(raw, true)
        : parseTimeCodeFromAmPm(raw);

    if (parsed !== null) {
        showInvalid.value = false;
        emit('update:modelValue', parsed);
        displayText.value = formatModel(parsed);
        return;
    }

    showInvalid.value = true;
};
</script>
