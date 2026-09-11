<template>
    <section class="schedule-card card mb-4">
        <div class="card-header schedule-card-header d-flex align-items-center">
            <div class="flex-fill">
                <h2 class="card-title h5 mb-0">
                    {{ $gettext('Scheduled Time #%{num}', {num: index + 1}) }}
                </h2>
            </div>
            <div class="flex-shrink-0">
                <button
                    type="button"
                    class="btn btn-sm btn-outline-light"
                    @click="doRemove()"
                >
                    <icon-ic-remove/>
                    <span>{{ $gettext('Remove') }}</span>
                </button>
            </div>
        </div>

        <div class="card-body p-3 p-lg-4">
            <div class="row g-3">
                <form-group-field
                    :id="'edit_form_start_time_'+index"
                    class="col-md-6 col-xl-3"
                    :field="r$.start_time"
                    :label="$gettext('Start Time')"
                >
                    <template #default="{id, model, fieldClass}">
                        <playlist-time
                            :id="id"
                            v-model="model.$model"
                            :class="fieldClass"
                        />
                    </template>
                </form-group-field>

                <form-group-field
                    :id="'edit_form_end_time_'+index"
                    class="col-md-6 col-xl-3"
                    :field="r$.end_time"
                    :label="$gettext('End Time')"
                    :description="$gettext('If end is before start, the schedule continues overnight.')"
                >
                    <template #default="{id, model, fieldClass}">
                        <playlist-time
                            :id="id"
                            v-model="model.$model"
                            :class="fieldClass"
                        />
                    </template>
                </form-group-field>

                <form-group-field
                    :id="'edit_form_start_date_'+index"
                    class="col-md-6 col-xl-3"
                    :field="r$.start_date"
                    input-type="date"
                    :label="$gettext('Start Date')"
                    :description="$gettext('First date this schedule may run.')"
                />

                <form-group-field
                    :id="'edit_form_end_date_'+index"
                    class="col-md-6 col-xl-3"
                    :field="r$.end_date"
                    input-type="date"
                    :label="$gettext('End Date')"
                    :description="$gettext('Last date this schedule may run.')"
                    :required="row.recurrence_end_type !== 'after'"
                    :input-attrs="{ disabled: row.recurrence_end_type === 'after' }"
                />

                <form-group-multi-check
                    :id="'edit_form_days_'+index"
                    class="col-xl-7"
                    :field="r$.days"
                    :label="$gettext('Days of Week')"
                    :description="daysOfWeekFieldDescription"
                    :options="dayOptions"
                    :required="!isMonthlyDatePattern"
                    :disabled="isMonthlyDatePattern"
                    stacked
                />

                <form-markup
                    :id="'station_time_zone_'+index"
                    class="col-xl-5"
                    :label="$gettext('Station Time Zone')"
                >
                    <div class="timezone-card">
                        <time-zone />
                    </div>
                </form-markup>

                <div class="col-12">
                    <hr class="my-1">
                </div>

                <form-markup
                    :id="'edit_form_scheduling_'+index"
                    class="col-xl-6"
                    :label="$gettext('Scheduling Mode')"
                >
                    <div class="mode-grid">
                        <label
                            class="mode-option"
                            :class="{'is-active': schedulingMode === 'flexible'}"
                        >
                            <input
                                :id="'scheduling_flexible_'+index"
                                v-model="schedulingMode"
                                class="form-check-input"
                                type="radio"
                                value="flexible"
                            >
                            <span>
                                <strong>{{ $gettext('Flexible') }}</strong>
                                <small>{{ $gettext('AutoDJ prefers natural song endings before this window. The playlist start behavior below still decides whether the show interrupts.') }}</small>
                            </span>
                        </label>

                        <label
                            class="mode-option"
                            :class="{'is-active': schedulingMode === 'strict'}"
                        >
                            <input
                                :id="'scheduling_strict_'+index"
                                v-model="schedulingMode"
                                class="form-check-input"
                                type="radio"
                                value="strict"
                            >
                            <span>
                                <strong>{{ $gettext('Strict') }}</strong>
                                <small>{{ $gettext('Make this specific schedule a hard wall-clock start. It may cut current audio at the scheduled start if needed.') }}</small>
                            </span>
                        </label>
                    </div>
                </form-markup>

                <form-markup
                    :id="'edit_form_repeat_mode_'+index"
                    class="col-xl-6"
                    :label="$gettext('Repeat During Scheduled Window')"
                >
                    <div class="mode-grid">
                        <label
                            class="mode-option"
                            :class="{'is-active': repeatMode === 'once'}"
                        >
                            <input
                                :id="'scheduling_once_'+index"
                                v-model="repeatMode"
                                class="form-check-input"
                                type="radio"
                                value="once"
                            >
                            <span>
                                <strong>{{ $gettext('Play once per scheduled block') }}</strong>
                                <small>{{ $gettext('Recommended for shows and programmes. The playlist will not start a second cycle in this time slot.') }}</small>
                            </span>
                        </label>

                        <label
                            class="mode-option"
                            :class="{'is-active': repeatMode === 'repeat'}"
                        >
                            <input
                                :id="'scheduling_repeat_'+index"
                                v-model="repeatMode"
                                class="form-check-input"
                                type="radio"
                                value="repeat"
                            >
                            <span>
                                <strong>{{ $gettext('Repeat until end of block') }}</strong>
                                <small>{{ $gettext('Useful for music rotation. The playlist may begin another cycle while this schedule remains active.') }}</small>
                            </span>
                        </label>
                    </div>
                </form-markup>

                <div class="col-12">
                    <label class="request-option">
                        <input
                            :id="'scheduling_prevent_requests_'+index"
                            v-model="row.prevent_requests"
                            class="form-check-input"
                            type="checkbox"
                        >
                        <span>
                            <strong>{{ $gettext('Block listener requests while this schedule is active') }}</strong>
                            <small>{{ $gettext('Requests remain queued, but the automatic request queue will not interrupt this scheduled window.') }}</small>
                        </span>
                    </label>
                </div>

                <div class="col-12">
                    <details class="calendar-rules">
                        <summary>
                            <strong>{{ $gettext('Calendar Repeat & Date Rules') }}</strong>
                            <small>{{ $gettext('Weekly, bi-weekly, monthly and limited-occurrence scheduling.') }}</small>
                        </summary>

                        <div class="row g-3 p-3">
                            <form-group-select
                                :id="'edit_form_recurrence_type_'+index"
                                class="col-md-6 col-xl-4"
                                :field="r$.recurrence_type"
                                :label="$gettext('Repeat')"
                                :description="$gettext('Choose how often this scheduled block repeats.')"
                                :options="recurrenceTypeOptions"
                            />

                            <form-group-field
                                v-if="row.recurrence_type === 'custom'"
                                :id="'edit_form_recurrence_interval_'+index"
                                class="col-md-6 col-xl-4"
                                :field="r$.recurrence_interval"
                                input-type="number"
                                min="1"
                                max="52"
                                :label="$gettext('Every (weeks)')"
                                :description="$gettext('Example: 3 means every 3 weeks.')"
                            />

                            <template v-if="row.recurrence_type === 'monthly'">
                                <form-group-select
                                    :id="'edit_form_recurrence_monthly_pattern_'+index"
                                    class="col-md-6 col-xl-4"
                                    :field="r$.recurrence_monthly_pattern"
                                    :label="$gettext('Monthly Pattern')"
                                    :options="recurrenceMonthlyPatternOptions"
                                />

                                <form-group-field
                                    v-if="row.recurrence_monthly_pattern === 'date'"
                                    :id="'edit_form_recurrence_monthly_day_'+index"
                                    class="col-md-6 col-xl-4"
                                    :field="r$.recurrence_monthly_day"
                                    input-type="number"
                                    min="1"
                                    max="31"
                                    :label="$gettext('Day of Month')"
                                    :description="$gettext('Day of the month from 1 through 31.')"
                                />

                                <form-group-select
                                    v-if="row.recurrence_monthly_pattern === 'day_of_week'"
                                    :id="'edit_form_recurrence_monthly_week_'+index"
                                    class="col-md-6 col-xl-4"
                                    :field="r$.recurrence_monthly_week"
                                    :label="$gettext('Week of Month')"
                                    :description="$gettext('Used with the selected day or days of week above.')"
                                    :options="recurrenceMonthlyWeekOptions"
                                />
                            </template>

                            <form-group-select
                                :id="'edit_form_recurrence_end_type_'+index"
                                class="col-md-6 col-xl-4"
                                :field="r$.recurrence_end_type"
                                :label="$gettext('Stop Recurrence')"
                                :description="$gettext('Use the End Date above or stop after a set number of occurrences.')"
                                :options="recurrenceEndTypeOptions"
                            />

                            <form-group-field
                                v-if="row.recurrence_end_type === 'after'"
                                :id="'edit_form_recurrence_end_after_'+index"
                                class="col-md-6 col-xl-4"
                                :field="r$.recurrence_end_after"
                                input-type="number"
                                min="1"
                                :label="$gettext('Stop After (occurrences)')"
                            />
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import PlaylistTime from "~/components/Common/TimeCode.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import {applyIf, minLength, minValue, required, requiredIf, withMessage} from "@regle/rules";
import {computed, watch} from "vue";
import {useTranslate} from "~/vendor/gettext";
import FormMarkup from "~/components/Form/FormMarkup.vue";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import FormGroupSelect from "~/components/Form/FormGroupSelect.vue";
import TimeZone from "~/components/Stations/Common/TimeZone.vue";
import {useAppScopedRegle} from "~/vendor/regle.ts";
import IconIcRemove from "~icons/ic/baseline-remove";

interface PlaylistScheduleRow {
    start_time: number,
    end_time: number,
    start_date: string,
    end_date: string,
    days: number[],
    loop_once: boolean,
    prevent_requests: boolean,
    strict_start: boolean,
    recurrence_type: string | null,
    recurrence_interval: number,
    recurrence_monthly_pattern: string | null,
    recurrence_monthly_day: number | null,
    recurrence_monthly_week: number | null,
    recurrence_monthly_day_of_week: number | null,
    recurrence_end_type: string,
    recurrence_end_after: number | null,
    recurrence_end_date: string | null,
}

const props = defineProps<{
    index: number,
}>();

const row = defineModel<PlaylistScheduleRow>('row', {required: true});

const emit = defineEmits<{
    (e: 'remove'): void
}>();

const isMonthlyDatePattern = computed(
    () => row.value.recurrence_type === 'monthly' && row.value.recurrence_monthly_pattern === 'date'
);

const isMonthlyDayOfWeekPattern = computed(
    () => row.value.recurrence_type === 'monthly' && row.value.recurrence_monthly_pattern === 'day_of_week'
);

const requiresDaysOfWeek = computed(() => !isMonthlyDatePattern.value);

const {$gettext} = useTranslate();

const schedulingMode = computed({
    get: (): 'flexible' | 'strict' => row.value.strict_start ? 'strict' : 'flexible',
    set: (mode: 'flexible' | 'strict') => {
        row.value.strict_start = mode === 'strict';
    },
});

const repeatMode = computed({
    get: (): 'once' | 'repeat' => row.value.loop_once ? 'once' : 'repeat',
    set: (mode: 'once' | 'repeat') => {
        row.value.loop_once = mode === 'once';
    },
});

const daysOfWeekFieldDescription = computed(() => {
    if (isMonthlyDatePattern.value) {
        return $gettext('Not used for a monthly "day of month" schedule.');
    }
    if (isMonthlyDayOfWeekPattern.value) {
        return $gettext('For a monthly weekday pattern, select the weekday or weekdays here.');
    }
    return $gettext('Select at least one day of the week.');
});

const {r$} = useAppScopedRegle(
    row,
    {
        start_time: {required},
        end_time: {required},
        start_date: {required},
        end_date: {
            required: requiredIf(() => row.value.recurrence_end_type !== 'after'),
        },
        days: {
            minLength: withMessage(
                applyIf(requiresDaysOfWeek, minLength(1)),
                () => $gettext('Select at least one day of the week.')
            ),
        },
        recurrence_end_after: {
            required: requiredIf(() => row.value.recurrence_end_type === 'after'),
            minValue: minValue(1),
        },
        recurrence_monthly_day: {
            required: requiredIf(
                () => row.value.recurrence_type === 'monthly' && row.value.recurrence_monthly_pattern === 'date'
            ),
        },
    },
    {
        namespace: 'stations-playlists'
    }
);

watch(
    () => row.value.recurrence_type,
    (newType: string | null) => {
        if (newType === 'biweekly') {
            row.value.recurrence_interval = 2;
        } else if (newType === 'weekly') {
            row.value.recurrence_interval = 1;
        }
    }
);

watch(
    () => [row.value.recurrence_type, row.value.recurrence_monthly_pattern] as const,
    () => {
        if (isMonthlyDatePattern.value) {
            row.value.days = [];
        }
    }
);

const dayOptions = [
    {value: 1, text: $gettext('Monday')},
    {value: 2, text: $gettext('Tuesday')},
    {value: 3, text: $gettext('Wednesday')},
    {value: 4, text: $gettext('Thursday')},
    {value: 5, text: $gettext('Friday')},
    {value: 6, text: $gettext('Saturday')},
    {value: 7, text: $gettext('Sunday')}
];

const recurrenceTypeOptions = [
    {value: 'weekly', text: $gettext('Weekly (default)')},
    {value: 'biweekly', text: $gettext('Bi-weekly (every 2 weeks)')},
    {value: 'monthly', text: $gettext('Monthly')},
    {value: 'custom', text: $gettext('Custom (every N weeks)')}
];

const recurrenceMonthlyPatternOptions = [
    {value: 'date', text: $gettext('On day of month (e.g. 15th)')},
    {value: 'day_of_week', text: $gettext('Specific day of week (e.g. 3rd Monday)')}
];

const recurrenceMonthlyWeekOptions = [
    {value: 1, text: $gettext('1st')},
    {value: 2, text: $gettext('2nd')},
    {value: 3, text: $gettext('3rd')},
    {value: 4, text: $gettext('4th')},
    {value: 5, text: $gettext('Last')}
];

const recurrenceEndTypeOptions = [
    {value: 'never', text: $gettext('Never (use End Date above)')},
    {value: 'after', text: $gettext('After number of occurrences')}
];

const doRemove = () => {
    emit('remove');
};
</script>

<style scoped>
.schedule-card {
    overflow: hidden;
    border-radius: .75rem;
}

.schedule-card-header {
    padding: .8rem 1rem;
    border-bottom: 0;
    background: linear-gradient(90deg, #1688f8, #0d6efd);
    color: #fff;
}

.mode-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .65rem;
}

.mode-option,
.request-option {
    display: flex;
    align-items: flex-start;
    gap: .7rem;
    padding: .8rem .85rem;
    margin: 0;
    border: 1px solid var(--bs-border-color);
    border-radius: .65rem;
    background: var(--bs-tertiary-bg);
    cursor: pointer;
}

.mode-option.is-active {
    border-color: #2688ff;
    background: rgba(38, 136, 255, .08);
    box-shadow: 0 0 0 .1rem rgba(38, 136, 255, .1);
}

.mode-option input,
.request-option input {
    margin-top: .2rem;
}

.mode-option strong,
.mode-option small,
.request-option strong,
.request-option small,
.calendar-rules summary strong,
.calendar-rules summary small {
    display: block;
}

.mode-option strong,
.request-option strong {
    font-size: .86rem;
}

.mode-option small,
.request-option small,
.calendar-rules summary small {
    margin-top: .2rem;
    color: var(--bs-secondary-color);
    line-height: 1.4;
}

.timezone-card {
    min-height: 2.4rem;
    padding: .55rem .7rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .45rem;
    background: var(--bs-tertiary-bg);
}

.calendar-rules {
    border: 1px solid var(--bs-border-color);
    border-radius: .65rem;
    background: var(--bs-tertiary-bg);
    overflow: hidden;
}

.calendar-rules summary {
    padding: .8rem .9rem;
    cursor: pointer;
}

@media (max-width: 991.98px) {
    .mode-grid {
        grid-template-columns: 1fr;
    }
}
</style>
