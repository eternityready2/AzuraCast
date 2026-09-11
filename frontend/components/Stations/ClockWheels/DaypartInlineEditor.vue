<template>
    <div class="clock-workspace-editor">
        <header class="clock-workspace-editor__header">
            <button
                type="button"
                class="btn btn-link p-0 text-decoration-none clock-workspace-editor__back"
                @click="emit('cancel')"
            >
                <span aria-hidden="true">←</span>
                {{ $gettext('Back to all Dayparts') }}
            </button>

            <h2 class="h5 mb-1">
                {{ isEditMode ? $gettext('Edit Daypart') : $gettext('Add Daypart') }}
            </h2>
            <p class="mb-0 text-muted small">
                {{ $gettext('Choose a reusable Template and an hour range. AzuraCast will create or update one Clock Wheel for each hour.') }}
            </p>
        </header>

        <div
            v-if="error"
            class="alert alert-danger m-3 mb-0"
        >
            {{ error }}
        </div>

        <div class="clock-workspace-editor__body">
            <div class="clock-workspace-section">
                <div class="clock-workspace-section__heading">
                    <h3 class="h6 mb-1">{{ $gettext('Daypart basics') }}</h3>
                    <p class="small text-muted mb-0">
                        {{ $gettext('Name the block, choose its Template, and set the hours it should cover.') }}
                    </p>
                </div>

                <form-group-field
                    id="daypart_name"
                    class="mb-3"
                    :field="r$.name"
                    :label="$gettext('Name')"
                />

                <form-group-select
                    id="daypart_template"
                    class="mb-3"
                    :field="r$.template_id"
                    :label="$gettext('Clock Template')"
                    :options="templateOptions"
                    :description="$gettext('The template supplies the reusable slot layout for every generated hourly wheel.')"
                />

                <div class="row mb-3">
                    <form-group-field
                        id="daypart_start_hour"
                        class="col-md-6"
                        :field="r$.start_hour"
                        :label="$gettext('Start hour')"
                        :description="$gettext('Station local hour (:00 only).')"
                    >
                        <template #default="{id, model, fieldClass}">
                            <am-pm-time-input
                                :input-id="id"
                                v-model="model.$model"
                                mode="hour"
                                :field-class="fieldClass"
                            />
                        </template>
                    </form-group-field>
                    <form-group-field
                        id="daypart_end_hour"
                        class="col-md-6"
                        :field="r$.end_hour"
                        :label="$gettext('End hour')"
                        :description="hourRangeHint"
                    >
                        <template #default="{id, model, fieldClass}">
                            <am-pm-time-input
                                :input-id="id"
                                v-model="model.$model"
                                mode="hour"
                                :field-class="fieldClass"
                            />
                        </template>
                    </form-group-field>
                </div>

                <div class="row align-items-end">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">{{ $gettext('Color (optional)') }}</label>
                        <input
                            v-model="form.color"
                            type="color"
                            class="form-control form-control-color"
                        >
                    </div>
                    <form-group-checkbox
                        id="daypart_is_active"
                        class="col-md-6 mb-3"
                        :field="r$.is_active"
                        :label="$gettext('Active')"
                        :description="$gettext('Inactive dayparts keep their wheels but mark them inactive on sync.')"
                    />
                </div>
            </div>

            <div class="clock-workspace-section mt-3">
                <div class="clock-workspace-section__heading">
                    <h3 class="h6 mb-1">{{ $gettext('Optional separation override') }}</h3>
                    <p class="small text-muted mb-0">
                        {{ $gettext('Leave this off to use each generated wheel\'s own separation settings.') }}
                    </p>
                </div>

                <form-group-checkbox
                    id="daypart_separation_override_enabled"
                    class="mb-3"
                    :field="r$.separation_override_enabled"
                    :label="$gettext('Override separation rules')"
                    :description="$gettext('Apply one shared separation policy to every hourly wheel generated by this daypart.')"
                />

                <template v-if="form.separation_override_enabled">
                    <form-group-checkbox
                        id="daypart_separation_enabled"
                        class="mb-3"
                        :field="r$.separation_enabled"
                        :label="$gettext('Enable separation rules')"
                    />

                    <div
                        v-if="form.separation_enabled"
                        class="row mb-3"
                    >
                        <form-group-field
                            id="daypart_separation_artist_minutes"
                            class="col-md-4"
                            :field="r$.separation_artist_minutes"
                            :label="$gettext('Artist separation (min)')"
                            type="number"
                        />
                        <form-group-field
                            id="daypart_separation_title_minutes"
                            class="col-md-4"
                            :field="r$.separation_title_minutes"
                            :label="$gettext('Title separation (min)')"
                            type="number"
                        />
                        <form-group-field
                            id="daypart_burn_rate_max_plays_24h"
                            class="col-md-4"
                            :field="r$.burn_rate_max_plays_24h"
                            :label="$gettext('Max plays / 24h')"
                            type="number"
                            :description="$gettext('Leave empty to disable burn-rate deprioritization.')"
                        />
                    </div>
                </template>
            </div>

            <div class="alert alert-info py-2 mt-3 mb-0">
                <strong>{{ $gettext('What happens when you save:') }}</strong>
                {{ $gettext('one Clock Wheel is created or updated for each hour in this range and linked to the selected Template. Schedule those Wheels on the station Schedule page as needed.') }}
            </div>
        </div>

        <footer class="clock-workspace-editor__footer">
            <button
                v-if="isEditMode"
                type="button"
                class="btn btn-outline-danger me-auto"
                :disabled="loading"
                @click="doDeleteFromEditor"
            >
                {{ $gettext('Delete Daypart') }}
            </button>
            <button
                v-if="isEditMode"
                type="button"
                class="btn btn-outline-secondary"
                :disabled="loading || syncing"
                @click="doResync"
            >
                {{ syncing ? $gettext('Syncing…') : $gettext('Re-sync Wheels') }}
            </button>
            <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="loading"
                @click="emit('cancel')"
            >
                {{ $gettext('Cancel') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="loading || r$.$invalid"
                @click="doSubmit"
            >
                <span
                    v-if="loading"
                    class="spinner-border spinner-border-sm me-2"
                    role="status"
                    aria-hidden="true"
                />
                {{ loading ? $gettext('Saving…') : $gettext('Save Daypart') }}
            </button>
        </footer>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from 'vue';
import {useAxios} from '~/vendor/axios';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAppRegle} from '~/vendor/regle.ts';
import {required} from '@regle/rules';
import mergeExisting from '~/functions/mergeExisting.ts';
import useConfirmAndDelete from '~/functions/useConfirmAndDelete.ts';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import FormGroupSelect from '~/components/Form/FormGroupSelect.vue';
import FormGroupCheckbox from '~/components/Form/FormGroupCheckbox.vue';
import AmPmTimeInput from '~/components/Common/AmPmTimeInput.vue';
import {formatHourOfDayToAmPm} from '~/functions/amPmTime.ts';

const props = defineProps<{
    createUrl: string;
    templatesUrl: string;
    recordUrl?: string | null;
}>();

const emit = defineEmits<{
    (e: 'saved'): void;
    (e: 'cancel'): void;
    (e: 'changed'): void;
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();

const loading = ref(false);
const syncing = ref(false);
const error = ref<string | null>(null);
const templateOptions = ref<{value: number; text: string}[]>([]);
const isEditMode = computed(() => Boolean(props.recordUrl));

const blankForm = {
    name: '',
    template_id: null as number | null,
    start_hour: 6,
    end_hour: 10,
    color: '#e87722',
    is_active: true,
    separation_override_enabled: false,
    separation_enabled: false,
    separation_artist_minutes: 45,
    separation_title_minutes: 90,
    burn_rate_max_plays_24h: null as number | null,
};

const form = ref({...blankForm});

const {r$} = useAppRegle(form, {
    name: {required},
    template_id: {required},
    start_hour: {required},
    end_hour: {required},
    is_active: {},
    separation_override_enabled: {},
    separation_enabled: {},
    separation_artist_minutes: {},
    separation_title_minutes: {},
    burn_rate_max_plays_24h: {},
});

const hourRangeHint = computed(() => {
    const start = Number(form.value.start_hour);
    const end = Number(form.value.end_hour);
    if (Number.isNaN(start) || Number.isNaN(end)) {
        return $gettext('Inclusive end hour. If end is before start, the range spans overnight.');
    }

    const count = countHoursInDaypartRange(start, end);
    const overnight = end < start ? ' ' + $gettext('(overnight span)') : '';

    return $gettext(
        'Generates %{count} hourly Clock Wheels from %{start} through %{end}%{overnight}.',
        {
            count: String(count),
            start: formatHourOfDayToAmPm(start),
            end: formatHourOfDayToAmPm(end),
            overnight,
        }
    );
});

function countHoursInDaypartRange(startHour: number, endHour: number): number {
    let count = 0;
    let hour = startHour;

    while (true) {
        count++;
        if (hour === endHour) {
            break;
        }
        hour = (hour + 1) % 24;
        if (count > 24) {
            break;
        }
    }

    return count;
}

const resetForm = () => {
    form.value = {...blankForm};
};

const populateForm = (data: Record<string, unknown>) => {
    form.value = mergeExisting(form.value, {
        ...data,
        template_id: data.template_id != null ? Number(data.template_id) : null,
        start_hour: Number(data.start_hour ?? 6),
        end_hour: Number(data.end_hour ?? 10),
        separation_override_enabled: Boolean(data.separation_override_enabled),
        separation_enabled: Boolean(data.separation_enabled),
        separation_artist_minutes: Number(data.separation_artist_minutes ?? 45),
        separation_title_minutes: Number(data.separation_title_minutes ?? 90),
        burn_rate_max_plays_24h: data.burn_rate_max_plays_24h != null
            ? Number(data.burn_rate_max_plays_24h)
            : null,
    });
};

const loadEditor = async () => {
    loading.value = true;
    error.value = null;
    resetForm();

    try {
        const {data: templates} = await axios.get(props.templatesUrl);
        templateOptions.value = (templates as Array<{id: number; name: string}>).map((template) => ({
            value: template.id,
            text: template.name,
        }));

        if (props.recordUrl) {
            const {data} = await axios.get(props.recordUrl);
            populateForm(data as Record<string, unknown>);
        }
    } catch (err: any) {
        error.value = err?.response?.data?.message ?? $gettext('Could not load this daypart.');
    } finally {
        loading.value = false;
    }
};

onMounted(loadEditor);

const buildPayload = async () => {
    const {valid} = await r$.$validate();
    if (!valid) {
        return null;
    }

    return {
        ...form.value,
        template_id: form.value.template_id != null ? Number(form.value.template_id) : null,
        start_hour: Number(form.value.start_hour),
        end_hour: Number(form.value.end_hour),
        color: form.value.color || null,
        separation_override_enabled: form.value.separation_override_enabled,
        separation_enabled: form.value.separation_override_enabled
            ? form.value.separation_enabled
            : false,
        separation_artist_minutes: form.value.separation_override_enabled
            ? Number(form.value.separation_artist_minutes) || 45
            : 45,
        separation_title_minutes: form.value.separation_override_enabled
            ? Number(form.value.separation_title_minutes) || 90
            : 90,
        burn_rate_max_plays_24h: form.value.separation_override_enabled
            && form.value.burn_rate_max_plays_24h != null
            && form.value.burn_rate_max_plays_24h > 0
            ? Number(form.value.burn_rate_max_plays_24h)
            : null,
    };
};

const doSubmit = async () => {
    const payload = await buildPayload();
    if (!payload) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        if (props.recordUrl) {
            await axios.put(props.recordUrl, payload);
        } else {
            await axios.post(props.createUrl, payload);
        }
        notifySuccess($gettext('Daypart saved and hourly Wheels synced.'));
        emit('saved');
    } catch (err: any) {
        error.value = err?.response?.data?.message ?? $gettext('Could not save this daypart.');
    } finally {
        loading.value = false;
    }
};

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete this daypart and its generated hourly Wheels?'),
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
        notifySuccess($gettext('Daypart hourly Wheels re-synced from Template.'));
        emit('changed');
    } catch {
        notifyError($gettext('Could not re-sync daypart Wheels.'));
    } finally {
        syncing.value = false;
    }
};
</script>

<style scoped>
.clock-workspace-editor {
    overflow: hidden;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: var(--bs-body-bg);
    box-shadow: 0 .25rem .85rem rgba(0, 0, 0, .08);
}

.clock-workspace-editor__header,
.clock-workspace-editor__body,
.clock-workspace-editor__footer {
    padding: 1rem 1.1rem;
}

.clock-workspace-editor__header {
    border-bottom: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
}

.clock-workspace-editor__back {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    margin-bottom: .6rem;
    font-weight: 700;
}

.clock-workspace-section {
    padding: 1rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .6rem;
    background: color-mix(in srgb, var(--bs-tertiary-bg) 45%, transparent);
}

.clock-workspace-section__heading {
    margin-bottom: 1rem;
    padding-bottom: .7rem;
    border-bottom: 1px solid var(--bs-border-color);
}

.clock-workspace-editor__footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: .65rem;
    border-top: 1px solid var(--bs-border-color);
}

@media (max-width: 575.98px) {
    .clock-workspace-editor__header,
    .clock-workspace-editor__body,
    .clock-workspace-editor__footer {
        padding: .85rem;
    }

    .clock-workspace-section {
        padding: .85rem;
    }

    .clock-workspace-editor__footer {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .clock-workspace-editor__footer .me-auto {
        grid-column: 1 / -1;
        margin-right: 0 !important;
    }

    .clock-workspace-editor__footer .btn {
        width: 100%;
    }
}
</style>
