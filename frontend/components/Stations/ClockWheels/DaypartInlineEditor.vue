<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_daypart_editor"
    >
        <div class="card-header text-bg-primary d-flex align-items-center gap-3 flex-wrap">
            <button
                type="button"
                class="btn btn-sm btn-light"
                @click="emit('cancel')"
            >
                ← {{ $gettext('All Dayparts') }}
            </button>
            <h2
                id="hdr_daypart_editor"
                class="card-title my-0 flex-fill"
            >
                {{ isEditMode ? form.name || $gettext('Edit Daypart') : $gettext('New Daypart') }}
            </h2>
        </div>

        <div
            v-if="error"
            class="alert alert-danger rounded-0 mb-0"
        >
            {{ error }}
        </div>

        <loading
            :loading="busy && !loaded"
            lazy
        >
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="d-flex justify-content-center mb-3">
                            <wheel-dial
                                :slots="templateDialSlots"
                                :size="240"
                                :show-ticks="false"
                                :center-label="selectedTemplate?.name ?? $gettext('No template')"
                                :center-sub="$gettext('every hour')"
                                :aria-label="$gettext('Hour template preview')"
                            />
                        </div>

                        <div class="card bg-body-tertiary">
                            <div class="card-body">
                                <h3 class="h6 mb-2">
                                    {{ $gettext('On Air') }}
                                </h3>
                                <p class="mb-2">
                                    <strong>{{ hoursLabel }}</strong><br>
                                    <span class="text-muted">{{ daysLabel }}</span>
                                </p>
                                <div
                                    class="hour-strip mb-2"
                                    :aria-label="$gettext('Hours covered')"
                                >
                                    <span
                                        v-for="hour in 24"
                                        :key="hour"
                                        class="hour-strip__cell"
                                        :class="{'is-on': coveredHours.includes(hour - 1)}"
                                        :style="coveredHours.includes(hour - 1) ? {background: form.color || 'var(--bs-primary)'} : {}"
                                        :title="formatHourOfDayToAmPm(hour - 1)"
                                    />
                                </div>
                                <div class="d-flex justify-content-between small text-muted mb-3">
                                    <span>12 AM</span>
                                    <span>6 AM</span>
                                    <span>12 PM</span>
                                    <span>6 PM</span>
                                    <span>12 AM</span>
                                </div>
                                <p class="small text-muted mb-0">
                                    {{ $ngettext(
                                        'Creates and schedules %{n} hourly Clock Wheel.',
                                        'Creates and schedules %{n} hourly Clock Wheels.',
                                        coveredHours.length,
                                        {n: String(coveredHours.length)}
                                    ) }}
                                    {{ $gettext('Scheduled shows and programmes keep priority in their own hours.') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <h3 class="h6">
                            {{ $gettext('Daypart Details') }}
                        </h3>
                        <div class="row g-3 mb-4">
                            <div class="col-md-7">
                                <label
                                    class="form-label"
                                    for="dp_name"
                                >{{ $gettext('Name') }}</label>
                                <input
                                    id="dp_name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="{'is-invalid': submitted && !form.name.trim()}"
                                    :placeholder="$gettext('e.g. Morning Drive')"
                                    required
                                >
                            </div>
                            <div class="col-md-2 col-4">
                                <label
                                    class="form-label"
                                    for="dp_color"
                                >{{ $gettext('Color') }}</label>
                                <input
                                    id="dp_color"
                                    v-model="form.color"
                                    type="color"
                                    class="form-control form-control-color w-100"
                                >
                            </div>
                            <div class="col-md-3 col-8">
                                <label
                                    class="form-label"
                                    for="dp_status"
                                >{{ $gettext('Status') }}</label>
                                <select
                                    id="dp_status"
                                    v-model="form.is_active"
                                    class="form-select"
                                >
                                    <option :value="true">
                                        {{ $gettext('Active') }}
                                    </option>
                                    <option :value="false">
                                        {{ $gettext('Inactive') }}
                                    </option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label
                                    class="form-label"
                                    for="dp_template"
                                >{{ $gettext('Hour Template') }}</label>
                                <select
                                    id="dp_template"
                                    v-model="form.template_id"
                                    class="form-select"
                                    :class="{'is-invalid': submitted && !form.template_id}"
                                >
                                    <option
                                        :value="null"
                                        disabled
                                    >
                                        {{ templates.length ? $gettext('Choose a template…') : $gettext('Create an Hour Template first') }}
                                    </option>
                                    <option
                                        v-for="template in templates"
                                        :key="template.id"
                                        :value="template.id"
                                    >
                                        {{ template.name }}
                                    </option>
                                </select>
                                <div class="form-text">
                                    {{ $gettext('Every hour in this daypart plays this template\'s clock.') }}
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="dp_start_hour"
                                >{{ $gettext('From') }}</label>
                                <am-pm-time-input
                                    input-id="dp_start_hour"
                                    v-model="form.start_hour"
                                    mode="hour"
                                />
                            </div>
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="dp_end_hour"
                                >{{ $gettext('Through the hour starting') }}</label>
                                <am-pm-time-input
                                    input-id="dp_end_hour"
                                    v-model="form.end_hour"
                                    mode="hour"
                                />
                            </div>

                            <div class="col-12">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                                    <span class="form-label mb-0">{{ $gettext('Days') }}</span>
                                    <div class="d-flex gap-1">
                                        <button
                                            v-for="preset in dayPresets"
                                            :key="preset.label"
                                            type="button"
                                            class="btn btn-sm btn-link p-0 px-1"
                                            @click="form.days = [...preset.days]"
                                        >
                                            {{ preset.label }}
                                        </button>
                                    </div>
                                </div>
                                <div
                                    class="btn-group w-100 flex-wrap"
                                    role="group"
                                    :aria-label="$gettext('Days')"
                                >
                                    <button
                                        v-for="day in weekDays"
                                        :key="day.value"
                                        type="button"
                                        class="btn"
                                        :class="isDayOn(day.value) ? 'btn-primary' : 'btn-outline-primary'"
                                        :aria-pressed="isDayOn(day.value)"
                                        @click="toggleDay(day.value)"
                                    >
                                        {{ day.short }}
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </loading>

        <div class="card-footer d-flex flex-wrap gap-2">
            <button
                v-if="isEditMode"
                type="button"
                class="btn btn-outline-danger"
                :disabled="busy"
                @click="doDeleteFromEditor"
            >
                {{ $gettext('Delete') }}
            </button>
            <button
                v-if="isEditMode"
                type="button"
                class="btn btn-outline-secondary"
                :disabled="busy || syncing"
                :title="$gettext('Copy the template\'s current slots into this daypart\'s wheels again.')"
                @click="doResync"
            >
                {{ syncing ? $gettext('Syncing…') : $gettext('Re-sync Wheels') }}
            </button>
            <div class="ms-auto d-flex gap-2">
                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    :disabled="busy"
                    @click="emit('cancel')"
                >
                    {{ $gettext('Cancel') }}
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="busy"
                    @click="doSubmit"
                >
                    {{ busy && loaded ? $gettext('Saving…') : $gettext('Save Daypart') }}
                </button>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from 'vue';
import {useAxios} from '~/vendor/axios';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import useConfirmAndDelete from '~/functions/useConfirmAndDelete.ts';
import Loading from '~/components/Common/Loading.vue';
import AmPmTimeInput from '~/components/Common/AmPmTimeInput.vue';
import WheelDial from '~/components/Stations/ClockWheels/WheelDial.vue';
import {formatHourOfDayToAmPm} from '~/functions/amPmTime.ts';
import {mapApiSlotToEditorRow} from '~/functions/clockWheelSlotEditor.ts';

type TemplateRow = {
    id: number;
    name: string;
    color?: string | null;
    slots?: Record<string, unknown>[];
};

const props = withDefaults(defineProps<{
    createUrl: string;
    templatesUrl: string;
    recordUrl?: string | null;
    presetTemplateId?: number | null;
}>(), {
    recordUrl: null,
    presetTemplateId: null,
});

const emit = defineEmits<{
    (e: 'saved'): void;
    (e: 'cancel'): void;
    (e: 'changed'): void;
}>();

const {$gettext, $ngettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();

const ALL_DAYS = [1, 2, 3, 4, 5, 6, 7];

const busy = ref(false);
const loaded = ref(false);
const syncing = ref(false);
const submitted = ref(false);
const error = ref<string | null>(null);
const templates = ref<TemplateRow[]>([]);
const isEditMode = computed(() => Boolean(props.recordUrl));

const form = ref({
    name: '',
    template_id: props.presetTemplateId,
    start_hour: 6,
    end_hour: 9,
    days: [...ALL_DAYS] as number[],
    color: '#e87722',
    is_active: true,
    separation_override_enabled: false,
    separation_enabled: false,
    separation_artist_minutes: 45,
    separation_title_minutes: 90,
    burn_rate_max_plays_24h: null as number | null,
});

const weekDays = computed(() => [
    {value: 1, short: $gettext('Mon')},
    {value: 2, short: $gettext('Tue')},
    {value: 3, short: $gettext('Wed')},
    {value: 4, short: $gettext('Thu')},
    {value: 5, short: $gettext('Fri')},
    {value: 6, short: $gettext('Sat')},
    {value: 7, short: $gettext('Sun')},
]);

const dayPresets = computed(() => [
    {label: $gettext('Every day'), days: ALL_DAYS},
    {label: $gettext('Weekdays'), days: [1, 2, 3, 4, 5]},
    {label: $gettext('Weekends'), days: [6, 7]},
]);

const isDayOn = (day: number) => form.value.days.includes(day);

const toggleDay = (day: number) => {
    const days = form.value.days.filter((d) => d !== day);
    form.value.days = isDayOn(day) ? days : [...days, day].sort((a, b) => a - b);
};

const coveredHours = computed(() => {
    const start = Number(form.value.start_hour);
    const end = Number(form.value.end_hour);
    if (Number.isNaN(start) || Number.isNaN(end)) {
        return [];
    }
    const hours: number[] = [];
    for (let hour = start; hours.length <= 24; hour = (hour + 1) % 24) {
        hours.push(hour);
        if (hour === end) {
            break;
        }
    }
    return hours;
});

const hoursLabel = computed(() => {
    const hours = coveredHours.value;
    if (hours.length === 0) {
        return '';
    }
    return $gettext('%{start} – %{end}', {
        start: formatHourOfDayToAmPm(hours[0]),
        end: formatHourOfDayToAmPm((hours[hours.length - 1] + 1) % 24),
    });
});

const daysLabel = computed(() => {
    const days = form.value.days;
    if (days.length === 0) {
        return $gettext('No days selected');
    }
    const preset = dayPresets.value.find((p) => p.days.length === days.length && p.days.every((d) => days.includes(d)));
    if (preset) {
        return preset.label;
    }
    return weekDays.value.filter((d) => days.includes(d.value)).map((d) => d.short).join(', ');
});

const selectedTemplate = computed(() => templates.value.find((t) => t.id === form.value.template_id) ?? null);

const templateDialSlots = computed(() =>
    (selectedTemplate.value?.slots ?? [])
        .map((s) => mapApiSlotToEditorRow(s))
        .map((r) => ({position_seconds: r.position_seconds, type: r.type}))
);

const requestError = (err: unknown, fallback: string): string =>
    (err as {response?: {data?: {message?: string}}})?.response?.data?.message ?? fallback;

onMounted(async () => {
    busy.value = true;
    try {
        const {data} = await axios.get<TemplateRow[]>(props.templatesUrl);
        templates.value = Array.isArray(data) ? data : [];

        if (props.recordUrl) {
            const {data: record} = await axios.get<Record<string, unknown>>(props.recordUrl);
            const days = Array.isArray(record.days) ? (record.days as unknown[]).map(Number) : [];
            form.value = {
                ...form.value,
                name: typeof record.name === 'string' ? record.name : '',
                template_id: record.template_id != null ? Number(record.template_id) : null,
                start_hour: Number(record.start_hour ?? 6),
                end_hour: Number(record.end_hour ?? 9),
                days: days.length ? days : [...ALL_DAYS],
                color: typeof record.color === 'string' && record.color ? record.color : '#e87722',
                is_active: Boolean(record.is_active),
                separation_override_enabled: Boolean(record.separation_override_enabled),
                separation_enabled: Boolean(record.separation_enabled),
                separation_artist_minutes: Number(record.separation_artist_minutes ?? 45),
                separation_title_minutes: Number(record.separation_title_minutes ?? 90),
                burn_rate_max_plays_24h: record.burn_rate_max_plays_24h != null
                    ? Number(record.burn_rate_max_plays_24h)
                    : null,
            };
        } else if (null === form.value.template_id && templates.value.length === 1) {
            form.value.template_id = templates.value[0].id;
        }
        loaded.value = true;
    } catch (err) {
        error.value = requestError(err, $gettext('Could not load this daypart.'));
    } finally {
        busy.value = false;
    }
});

const buildPayload = (): Record<string, unknown> | null => {
    submitted.value = true;
    if (!form.value.name.trim()) {
        error.value = $gettext('Please enter a name for this daypart.');
        return null;
    }
    if (!form.value.template_id) {
        error.value = $gettext('Please choose an hour template.');
        return null;
    }
    if (form.value.days.length === 0) {
        error.value = $gettext('Please choose at least one day.');
        return null;
    }

    const override = form.value.separation_override_enabled;
    return {
        name: form.value.name.trim(),
        template_id: Number(form.value.template_id),
        start_hour: Number(form.value.start_hour),
        end_hour: Number(form.value.end_hour),
        // Every day is stored as "no restriction".
        days: form.value.days.length === 7 ? [] : form.value.days,
        color: form.value.color || null,
        is_active: form.value.is_active,
        separation_override_enabled: override,
        separation_enabled: override ? form.value.separation_enabled : false,
        separation_artist_minutes: override ? Number(form.value.separation_artist_minutes) || 45 : 45,
        separation_title_minutes: override ? Number(form.value.separation_title_minutes) || 90 : 90,
        burn_rate_max_plays_24h: override && Number(form.value.burn_rate_max_plays_24h) > 0
            ? Number(form.value.burn_rate_max_plays_24h)
            : null,
    };
};

const doSubmit = async () => {
    const payload = buildPayload();
    if (!payload) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        if (props.recordUrl) {
            await axios.put(props.recordUrl, payload);
        } else {
            await axios.post(props.createUrl, payload);
        }
        notifySuccess($gettext('Daypart saved. Its hourly Clock Wheels are scheduled and the 24-hour log is re-planning.'));
        emit('saved');
    } catch (err) {
        error.value = requestError(err, $gettext('Could not save this daypart.'));
    } finally {
        busy.value = false;
    }
};

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete this daypart? Its hourly Clock Wheels and their schedule are removed too.'),
    () => emit('saved')
);

const doDeleteFromEditor = () => {
    if (props.recordUrl) {
        void doDelete(props.recordUrl);
    }
};

const doResync = async () => {
    if (!props.recordUrl) {
        return;
    }

    syncing.value = true;

    try {
        await axios.post(`${props.recordUrl}/sync`);
        notifySuccess($gettext('Daypart wheels re-synced from the hour template.'));
        emit('changed');
    } catch {
        notifyError($gettext('Could not re-sync daypart wheels.'));
    } finally {
        syncing.value = false;
    }
};
</script>

<style scoped>
.hour-strip {
    display: grid;
    grid-template-columns: repeat(24, 1fr);
    gap: 2px;
}

.hour-strip__cell {
    height: 1.1rem;
    border-radius: .2rem;
    background: var(--bs-secondary-bg);
}
</style>
