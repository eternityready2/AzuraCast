<template>
    <div
        class="am-pm-time-segments"
        :class="[fieldClass, {'is-invalid': showInvalid}]"
        role="group"
        :aria-label="ariaLabel ?? $gettext('Time')"
    >
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="d-flex align-items-center gap-1">
                <label
                    class="visually-hidden"
                    :for="hourSelectId"
                >
                    {{ $gettext('Hour') }}
                </label>
                <select
                    :id="hourSelectId"
                    v-model.number="hour12"
                    class="form-select form-select-sm am-pm-time-segments__select"
                    @change="onSegmentChange"
                >
                    <option
                        v-for="h in hourOptions"
                        :key="h"
                        :value="h"
                    >
                        {{ h }}
                    </option>
                </select>
                <span
                    class="am-pm-time-segments__colon"
                    aria-hidden="true"
                >:</span>
                <template v-if="wholeHourOnly">
                    <span class="am-pm-time-segments__minutes-fixed">00</span>
                </template>
                <template v-else>
                    <label
                        class="visually-hidden"
                        :for="minuteSelectId"
                    >
                        {{ $gettext('Minutes') }}
                    </label>
                    <select
                        :id="minuteSelectId"
                        v-model.number="minutes"
                        class="form-select form-select-sm am-pm-time-segments__select"
                        @change="onSegmentChange"
                    >
                        <option
                            v-for="m in minuteOptions"
                            :key="m"
                            :value="m"
                        >
                            {{ formatMinuteOption(m) }}
                        </option>
                    </select>
                </template>
            </div>

            <div
                class="btn-group btn-group-sm am-pm-time-segments__period"
                role="group"
                :aria-label="$gettext('AM or PM')"
            >
                <input
                    :id="amRadioId"
                    v-model="period"
                    type="radio"
                    class="btn-check"
                    value="AM"
                    @change="onSegmentChange"
                >
                <label
                    class="btn btn-outline-primary"
                    :for="amRadioId"
                >
                    {{ $gettext('AM') }}
                </label>
                <input
                    :id="pmRadioId"
                    v-model="period"
                    type="radio"
                    class="btn-check"
                    value="PM"
                    @change="onSegmentChange"
                >
                <label
                    class="btn btn-outline-primary"
                    :for="pmRadioId"
                >
                    {{ $gettext('PM') }}
                </label>
            </div>

            <span
                class="text-muted small am-pm-time-segments__preview"
                aria-live="polite"
            >
                {{ previewLabel }}
            </span>
        </div>
    </div>
</template>

<script setup lang="ts">
import {computed, ref, useId, watch} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {
    formatPartsToAmPm,
    HOUR12_OPTIONS,
    MINUTE_OPTIONS,
    modelValueToSegments,
    segmentsToHourOfDay,
    segmentsToModelValue,
    type AmPmPeriod,
    type AmPmTimeSegments,
} from '~/functions/amPmTime.ts';

const props = withDefaults(
    defineProps<{
        modelValue?: number | null;
        /** `timeCode` = HHMM (schedule); `hour` = 0–23 whole hours (dayparts). */
        mode?: 'timeCode' | 'hour';
        inputId?: string;
        ariaLabel?: string;
        fieldClass?: string;
    }>(),
    {
        modelValue: null,
        mode: 'timeCode',
        inputId: undefined,
        ariaLabel: undefined,
        fieldClass: '',
    }
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
}>();

const {$gettext} = useTranslate();

const wholeHourOnly = computed(() => props.mode === 'hour');
const hourOptions = HOUR12_OPTIONS;
const minuteOptions = MINUTE_OPTIONS;

const fallbackId = useId();
const baseId = computed(() => props.inputId ?? fallbackId);
const hourSelectId = computed(() => baseId.value);
const minuteSelectId = computed(() => `${baseId.value}_minute`);
const amRadioId = computed(() => `${baseId.value}_am`);
const pmRadioId = computed(() => `${baseId.value}_pm`);

const hour12 = ref(12);
const minutes = ref(0);
const period = ref<AmPmPeriod>('AM');
const showInvalid = ref(false);
const suppressSync = ref(false);

const formatMinuteOption = (m: number): string => String(m).padStart(2, '0');

const currentSegments = computed(
    (): AmPmTimeSegments => ({
        hour12: hour12.value,
        minutes: wholeHourOnly.value ? 0 : minutes.value,
        period: period.value,
    })
);

const previewLabel = computed(() => {
    const segs = currentSegments.value;
    const hour24 = segmentsToHourOfDay(segs);

    return formatPartsToAmPm(hour24, segs.minutes);
});

const applySegments = (segments: AmPmTimeSegments) => {
    hour12.value = segments.hour12;
    minutes.value = wholeHourOnly.value ? 0 : segments.minutes;
    period.value = segments.period;
};

const emitFromSegments = () => {
    if (suppressSync.value) {
        return;
    }

    showInvalid.value = false;
    const value = segmentsToModelValue(currentSegments.value, wholeHourOnly.value);
    emit('update:modelValue', value);
};

const onSegmentChange = () => {
    emitFromSegments();
};

watch(
    () => props.modelValue,
    (value) => {
        suppressSync.value = true;

        if (value === null || value === undefined || Number.isNaN(value)) {
            applySegments({hour12: 12, minutes: 0, period: 'AM'});
        } else {
            applySegments(modelValueToSegments(value, wholeHourOnly.value));
        }

        showInvalid.value = false;
        suppressSync.value = false;
    },
    {immediate: true}
);

watch(wholeHourOnly, () => {
    if (wholeHourOnly.value) {
        minutes.value = 0;
    }
    emitFromSegments();
});
</script>

<style scoped>
.am-pm-time-segments__select {
    width: auto;
    min-width: 4.25rem;
}

.am-pm-time-segments__colon {
    font-weight: 600;
    line-height: 1;
}

.am-pm-time-segments__minutes-fixed {
    display: inline-block;
    min-width: 2.25rem;
    padding: 0.25rem 0.5rem;
    font-variant-numeric: tabular-nums;
    background: var(--bs-secondary-bg);
    border-radius: var(--bs-border-radius-sm);
}

.am-pm-time-segments.is-invalid .form-select,
.am-pm-time-segments.is-invalid .am-pm-time-segments__period .btn {
    border-color: var(--bs-form-invalid-border-color);
}

.am-pm-time-segments__preview {
    flex: 1 1 100%;
    min-width: 6rem;
}

@media (min-width: 576px) {
    .am-pm-time-segments__preview {
        flex: 0 1 auto;
    }
}
</style>
