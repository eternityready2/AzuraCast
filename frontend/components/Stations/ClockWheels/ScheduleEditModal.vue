<template>
    <modal
        id="modal_clock_wheel_event"
        ref="$modal"
        :title="isEditMode ? $gettext('Edit Event') : $gettext('Add Event')"
        @hidden="clearForm"
    >
        <template #default>
            <loading :loading="loading">
                <form @submit.prevent="doSubmit">
                    <!-- Clock Wheel selector -->
                    <form-group-select
                        id="clock_wheel_id"
                        v-model="form.clock_wheel_id"
                        :label="$gettext('Clock Wheel')"
                        :options="clockWheelOptions"
                        required
                    />

                    <!-- Days of week -->
                    <form-group-multi-check
                        id="days"
                        v-model="form.days"
                        :label="$gettext('Days of Week')"
                        :options="dayOptions"
                    />

                    <!-- Time range -->
                    <div class="row g-3">
                        <div class="col-6">
                            <form-group-field
                                id="start_time"
                                v-model="form.start_time"
                                type="time"
                                :label="$gettext('Start Time')"
                                required
                            />
                        </div>
                        <div class="col-6">
                            <form-group-field
                                id="end_time"
                                v-model="form.end_time"
                                type="time"
                                :label="$gettext('End Time')"
                                required
                            />
                        </div>
                    </div>
                </form>
            </loading>
        </template>

        <template #modal-footer>
            <button
                v-if="isEditMode"
                type="button"
                class="btn btn-danger me-auto"
                @click="doDelete"
            >
                {{ $gettext('Delete') }}
            </button>
            <button type="button" class="btn btn-secondary" @click="close">
                {{ $gettext('Close') }}
            </button>
            <button type="button" class="btn btn-primary" @click="doSubmit">
                {{ $gettext('Save Changes') }}
            </button>
        </template>
    </modal>
</template>

<script setup lang="ts">
import Modal from '~/components/Common/Modal.vue';
import Loading from '~/components/Common/Loading.vue';
import FormGroupSelect from '~/components/Form/FormGroupSelect.vue';
import FormGroupMultiCheck from '~/components/Form/FormGroupMultiCheck.vue';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import {ref, computed, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';

// ------------------------------------------------------------------
// Props / emits
// ------------------------------------------------------------------

const emit = defineEmits<{
    relist: []
}>();

// ------------------------------------------------------------------
// Utilities
// ------------------------------------------------------------------

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const {axios} = useAxios();
const {notifySuccess} = useNotify();

const eventsBaseUrl = getStationApiUrl('/clock-wheel-events');
const eventUrl = (id: number) => getStationApiUrl(`/clock-wheel-event/${id}`).value;
const clockWheelsUrl = getStationApiUrl('/clock-wheels');

// ------------------------------------------------------------------
// State
// ------------------------------------------------------------------

const $modal = useTemplateRef('$modal');
const loading = ref(false);
const isEditMode = ref(false);
const currentEventId = ref<number | null>(null);

interface EventForm {
    clock_wheel_id: number | null;
    days: number[];
    start_time: string; // HH:MM
    end_time: string;   // HH:MM
}

const defaultForm = (): EventForm => ({
    clock_wheel_id: null,
    days: [1, 2, 3, 4, 5, 6, 7],
    start_time: '09:00',
    end_time: '10:00',
});

const form = ref<EventForm>(defaultForm());

// Clock wheels list for the dropdown
const clockWheels = ref<Array<{id: number; name: string; color: string}>>([]);
const clockWheelOptions = computed(() =>
    clockWheels.value.map((cw) => ({value: cw.id, text: cw.name}))
);

// Day checkboxes
const dayOptions = computed(() => [
    {value: 1, text: $gettext('Monday')},
    {value: 2, text: $gettext('Tuesday')},
    {value: 3, text: $gettext('Wednesday')},
    {value: 4, text: $gettext('Thursday')},
    {value: 5, text: $gettext('Friday')},
    {value: 6, text: $gettext('Saturday')},
    {value: 7, text: $gettext('Sunday')},
]);

// ------------------------------------------------------------------
// Time code <-> HH:MM helpers
// ------------------------------------------------------------------

const timeToCode = (time: string): number => {
    const [h, m] = time.split(':').map(Number);
    return h * 100 + m;
};

const codeToTime = (code: number): string => {
    const h = Math.floor(code / 100).toString().padStart(2, '0');
    const m = (code % 100).toString().padStart(2, '0');
    return `${h}:${m}`;
};

// ------------------------------------------------------------------
// Load clock wheels
// ------------------------------------------------------------------

const loadClockWheels = async () => {
    const {data} = await axios.get<Array<{id: number; name: string; color: string}>>(
        clockWheelsUrl.value
    );
    clockWheels.value = data;
    if (data.length > 0 && form.value.clock_wheel_id === null) {
        form.value.clock_wheel_id = data[0].id;
    }
};

// ------------------------------------------------------------------
// Open modal
// ------------------------------------------------------------------

/** Open the "create" form, optionally pre-filled from a FullCalendar select event. */
const create = (preload?: {start_time?: string; end_time?: string; days?: number[]}) => {
    form.value = defaultForm();
    if (preload) {
        if (preload.start_time) form.value.start_time = preload.start_time;
        if (preload.end_time) form.value.end_time = preload.end_time;
        if (preload.days) form.value.days = preload.days;
    }
    isEditMode.value = false;
    currentEventId.value = null;
    loadClockWheels();
    $modal.value?.show();
};

/** Open the "edit" form with an existing event's data. */
const edit = async (eventId: number) => {
    isEditMode.value = true;
    currentEventId.value = eventId;
    loading.value = true;
    form.value = defaultForm();
    await loadClockWheels();
    $modal.value?.show();

    try {
        const {data} = await axios.get<{
            clock_wheel_id: number;
            start_time: number;
            end_time: number;
            days: string | null;
        }>(eventUrl(eventId));

        form.value.clock_wheel_id = data.clock_wheel_id;
        form.value.start_time = codeToTime(data.start_time);
        form.value.end_time = codeToTime(data.end_time);
        form.value.days = data.days
            ? data.days.split(',').map(Number)
            : [1, 2, 3, 4, 5, 6, 7];
    } finally {
        loading.value = false;
    }
};

// ------------------------------------------------------------------
// Submit & delete
// ------------------------------------------------------------------

const close = () => {
    $modal.value?.hide();
};

const clearForm = () => {
    form.value = defaultForm();
};

const doSubmit = async () => {
    loading.value = true;
    try {
        const payload = {
            clock_wheel_id: form.value.clock_wheel_id,
            start_time: timeToCode(form.value.start_time),
            end_time: timeToCode(form.value.end_time),
            days: form.value.days.length === 7 ? null : form.value.days.join(','),
        };

        if (isEditMode.value && currentEventId.value !== null) {
            await axios.put(eventUrl(currentEventId.value), payload);
        } else {
            await axios.post(eventsBaseUrl.value, payload);
        }

        notifySuccess($gettext('Event saved.'));
        $modal.value?.hide();
        emit('relist');
    } finally {
        loading.value = false;
    }
};

const doDelete = async () => {
    if (currentEventId.value === null) return;
    if (!confirm($gettext('Are you sure you want to delete this event?'))) return;

    loading.value = true;
    try {
        await axios.delete(eventUrl(currentEventId.value));
        notifySuccess($gettext('Event deleted.'));
        $modal.value?.hide();
        emit('relist');
    } finally {
        loading.value = false;
    }
};

defineExpose({create, edit});
</script>
