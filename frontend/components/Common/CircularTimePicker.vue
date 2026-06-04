<template>
    <div ref="containerRef">
        <input
            ref="inputRef"
            :id="inputId"
            type="text"
            class="form-control"
            :value="displayValue"
            readonly
            :placeholder="$gettext('HH:MM')"
            :aria-label="ariaLabel"
        />
    </div>
</template>

<script setup lang="ts">
import {onMounted, onUnmounted, ref, computed} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {TimepickerUI} from 'timepicker-ui';
import 'timepicker-ui/main.css';

const props = withDefaults(
    defineProps<{
        modelValue?: number | null;
        inputId?: string;
        ariaLabel?: string;
    }>(),
    {
        modelValue: null,
        inputId: undefined,
        ariaLabel: undefined,
    }
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
}>();

const {$gettext} = useTranslate();

const containerRef = ref<HTMLDivElement>();
const inputRef = ref<HTMLInputElement>();

let picker: TimepickerUI | null = null;

/** AzuraCast schedule times are stored as HHMM integers (e.g. 1600 = 4:00 PM). */
const formatTimeCodeForDisplay = (timeCode: number): string => {
    let hour24 = Math.floor(timeCode / 100);
    const minutes = timeCode % 100;
    const period = hour24 >= 12 ? 'PM' : 'AM';

    if (hour24 === 0) {
        hour24 = 12;
    } else if (hour24 > 12) {
        hour24 -= 12;
    }

    return `${String(hour24).padStart(2, '0')}:${String(minutes).padStart(2, '0')} ${period}`;
};

const timeCodeFromPickerConfirm = (hourRaw: string, minutesRaw: string, periodRaw?: string | null): number | null => {
    let hour24 = parseInt(hourRaw, 10);
    const minutes = parseInt(minutesRaw, 10);

    if (Number.isNaN(hour24) || Number.isNaN(minutes)) {
        return null;
    }

    const period = String(periodRaw ?? '').trim().toUpperCase();

    if (period === 'PM' || period === 'AM') {
        if (period === 'PM' && hour24 !== 12) {
            hour24 += 12;
        }
        if (period === 'AM' && hour24 === 12) {
            hour24 = 0;
        }
    } else if (hour24 > 23 || minutes > 59) {
        return null;
    }

    return hour24 * 100 + minutes;
};

const displayValue = computed(() => {
    if (props.modelValue === null || props.modelValue === undefined) {
        return '';
    }

    return formatTimeCodeForDisplay(props.modelValue);
});

onMounted(() => {
    if (!inputRef.value) return;

    picker = new TimepickerUI(inputRef.value, {
        clock: {
            type: '12h',
            currentTime: false,
        },
        ui: {
            theme: 'dark',
            mode: 'clock',
            animation: true,
            backdrop: true,
        },
        labels: {
            ok: $gettext('OK'),
            cancel: $gettext('Cancel'),
        },
        callbacks: {
            onConfirm: (data) => {
                if (data.hour !== undefined && data.minutes !== undefined) {
                    const timeCode = timeCodeFromPickerConfirm(
                        String(data.hour),
                        String(data.minutes),
                        data.type
                    );

                    if (timeCode !== null) {
                        emit('update:modelValue', timeCode);
                    }
                }
            },
            onCancel: () => {
                // No change
            },
            onClear: () => {
                emit('update:modelValue', null);
            },
        },
    });

    picker.create();
});

onUnmounted(() => {
    picker?.destroy();
    picker = null;
});
</script>
