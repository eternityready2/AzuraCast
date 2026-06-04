<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="langTitle"
        :error="error"
        :disable-save-button="r$.$invalid"
        @submit="doSubmit"
        @hidden="clearContents"
    >
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
            :label="$gettext('Clock template')"
            :options="templateOptions"
            :description="$gettext('Hourly clock wheels are generated from this template layout.')"
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

        <div class="mb-3">
            <label class="form-label fw-semibold">{{ $gettext('Color (optional)') }}</label>
            <input
                v-model="form.color"
                type="color"
                class="form-control form-control-color"
            >
        </div>

        <form-group-checkbox
            id="daypart_is_active"
            class="mb-3"
            :field="r$.is_active"
            :label="$gettext('Active')"
            :description="$gettext('Inactive dayparts keep wheels but mark them inactive on sync.')"
        />

        <hr class="my-3">

        <form-group-checkbox
            id="daypart_separation_override_enabled"
            class="mb-3"
            :field="r$.separation_override_enabled"
            :label="$gettext('Override separation rules')"
            :description="$gettext('When enabled, these settings apply to all hourly wheels in this daypart instead of each wheel\'s own separation settings.')"
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

        <div class="alert alert-info py-2">
            {{ $gettext('Saving creates or updates one clock wheel per hour in the range, linked to the template. Schedule those wheels on the calendar as needed.') }}
        </div>

        <template
            v-if="isEditMode"
            #modal-footer
        >
            <button
                type="button"
                class="btn btn-danger me-auto"
                @click="doDeleteFromModal"
            >
                {{ $gettext('Delete') }}
            </button>
            <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="syncing"
                @click="doResync"
            >
                {{ syncing ? $gettext('Syncing…') : $gettext('Re-sync wheels') }}
            </button>
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
                :disabled="r$.$invalid"
                @click="doSubmit"
            >
                {{ $gettext('Save Changes') }}
            </button>
        </template>
    </modal-form>
</template>

<script setup lang="ts">
import ModalForm from '~/components/Common/ModalForm.vue';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import FormGroupSelect from '~/components/Form/FormGroupSelect.vue';
import FormGroupCheckbox from '~/components/Form/FormGroupCheckbox.vue';
import AmPmTimeInput from '~/components/Common/AmPmTimeInput.vue';
import {BaseEditModalEmits, BaseEditModalProps, useBaseEditModal} from '~/functions/useBaseEditModal';
import {computed, onMounted, ref, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAppRegle} from '~/vendor/regle.ts';
import {required} from '@regle/rules';
import mergeExisting from '~/functions/mergeExisting.ts';
import useConfirmAndDelete from '~/functions/useConfirmAndDelete.ts';
import {useAxios} from '~/vendor/axios';
import {formatHourOfDayToAmPm} from '~/functions/amPmTime.ts';

const props = defineProps<BaseEditModalProps & {
    templatesUrl: string;
}>();
const emit = defineEmits<BaseEditModalEmits>();

const $modal = useTemplateRef('$modal');
const {notifySuccess, notifyError} = useNotify();
const {$gettext} = useTranslate();
const {axios} = useAxios();

const templateOptions = ref<{value: number; text: string}[]>([]);

const hourRangeHint = computed(() => {
    const start = Number(form.value.start_hour);
    const end = Number(form.value.end_hour);
    if (Number.isNaN(start) || Number.isNaN(end)) {
        return $gettext('Inclusive end hour. If end is before start, the range spans overnight.');
    }

    const count = countHoursInDaypartRange(start, end);
    const overnight =
        end < start
            ? ' ' + $gettext('(overnight span)')
            : '';

    return $gettext(
        'Generates %{count} hourly clock wheels from %{start} through %{end}%{overnight}.',
        {
            count: String(count),
            start: formatHourOfDayToAmPm(start),
            end: formatHourOfDayToAmPm(end),
            overnight,
        }
    );
});

onMounted(async () => {
    const {data} = await axios.get(props.templatesUrl);
    templateOptions.value = (data as Array<{id: number; name: string}>).map((t) => ({
        value: t.id,
        text: t.name,
    }));
});

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

/** Inclusive hour count; end before start = overnight span. */
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

const validateForm = async () => {
    const {valid} = await r$.$validate();
    const payload = {
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
    return {valid, data: payload};
};

const langTitle = computed(() =>
    isEditMode.value ? $gettext('Edit Daypart') : $gettext('Add Daypart')
);

const {
    loading,
    error,
    isEditMode,
    editUrl,
    clearContents,
    create,
    edit,
    close,
    doSubmit,
} = useBaseEditModal(
    computed(() => props.createUrl),
    emit,
    $modal,
    resetForm,
    populateForm,
    validateForm,
    {
        onSubmitSuccess: () => {
            notifySuccess($gettext('Daypart saved and hourly wheels synced.'));
            emit('relist');
            close();
        },
    }
);

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete this daypart and its generated hourly wheels?'),
    () => {
        emit('relist');
    }
);

const doDeleteFromModal = () => {
    if (editUrl.value) {
        $modal.value?.hide();
        void doDelete(editUrl.value);
    }
};

const doResync = async () => {
    if (!editUrl.value) {
        return;
    }

    syncing.value = true;

    try {
        await axios.post(`${editUrl.value}/sync`);
        notifySuccess($gettext('Daypart hourly wheels re-synced from template.'));
        emit('relist');
    } catch {
        notifyError($gettext('Could not re-sync daypart wheels.'));
    } finally {
        syncing.value = false;
    }
};

defineExpose({create, edit});
</script>
