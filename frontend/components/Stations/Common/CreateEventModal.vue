<template>
    <component
        :is="inline ? InlineForm : ModalForm"
        ref="$modal"
        :loading="loading"
        :title="modalTitle"
        :error="error"
        :disable-save-button="!isFormValid"
        @submit="doSave"
        @shown="emit('shown')"
        @hidden="onFormHidden"
    >
        <!-- Step 1: What should play? -->
        <div
            v-if="!isScopedMode"
            class="step-heading step-heading-source mb-3"
        >
            <span class="step-icon step-icon-source"><icon-ic-event /></span>
            <span>
                <strong>{{ $gettext('1. What should play?') }}</strong>
                <small>{{ $gettext('Choose the type of content, then pick the specific item to schedule.') }}</small>
            </span>
        </div>

        <div v-if="!isScopedMode" class="row g-3 mb-3">
            <!-- Source -->
            <div class="col-md-6">
                <label class="form-label fw-semibold">{{ $gettext('Source') }}</label>
                <select
                    v-model="form.source"
                    class="form-select"
                    @change="onSourceChange"
                >
                    <option value="clock_wheel">
                        {{ $gettext('Clock Wheel') }}
                    </option>
                    <option value="playlist">
                        {{ $gettext('Playlist') }}
                    </option>
                    <option value="smart_block">
                        {{ $gettext('Smart Block') }}
                    </option>
                    <option value="web_stream">
                        {{ $gettext('Web / Remote Stream') }}
                    </option>
                </select>
            </div>

            <!-- Entity selection -->
            <div class="col-md-6">
                <label class="form-label fw-semibold">
                    {{
                        form.source === 'clock_wheel'
                            ? $gettext('Clock Wheel')
                            : form.source === 'smart_block'
                                ? $gettext('Smart Block')
                                : form.source === 'web_stream'
                                    ? $gettext('Web / Remote Stream')
                                    : $gettext('Playlist')
                    }}
                </label>
                <select
                    v-model="form.entity_id"
                    class="form-select"
                    :disabled="currentEntityOptions.length === 0"
                    @change="onEntityChange"
                >
                    <option
                        v-for="e in currentEntityOptions"
                        :key="e.id"
                        :value="e.id"
                    >
                        {{ e.name }}
                    </option>
                </select>
            </div>
        </div>

        <div
            v-if="isPlaylistSchedule && (!useNewSchedule || nsTargetRow)"
            class="col-12 mb-3"
        >
            <label class="request-option request-option-warning">
                <input
                    id="edit_form_is_emergency"
                    v-model="emergencyOverride"
                    class="form-check-input"
                    type="checkbox"
                >
                <span>
                    <strong>{{ $gettext('Emergency override') }}</strong>
                    <small>{{ $gettext('While this schedule is active, clock wheel AutoDJ will not run. Use for breaking news or other must-play windows.') }}</small>
                </span>
            </label>
        </div>

        <template v-if="!useNewSchedule">
        <!-- Schedule Row - Time section -->
        <div class="row g-3 mb-3">
            <form-group-field
                id="edit_form_start_time"
                class="col-md-4"
                :field="r$.start_time"
                :label="$gettext('Start Time')"
                :description="$gettext('To play once per day, set start and end to the same value.')"
            >
                <template #default="{id, model, fieldClass}">
                    <am-pm-time-input
                        :input-id="id"
                        v-model="model.$model"
                        :field-class="fieldClass"
                    />
                </template>
            </form-group-field>

            <form-group-field
                id="edit_form_end_time"
                class="col-md-4"
                :field="r$.end_time"
                :label="$gettext('End Time')"
                :description="$gettext('If end is before start, the event plays overnight. Back-to-back events are fine -- e.g. end this one at 2:00 PM and start the next at 2:00 PM.')"
            >
                <template #default="{id, model, fieldClass}">
                    <am-pm-time-input
                        :input-id="id"
                        v-model="model.$model"
                        :field-class="fieldClass"
                    />
                </template>
            </form-group-field>

            <form-markup
                id="edit_form_duration"
                class="col-md-4"
                :label="$gettext('Duration')"
                :description="$gettext('Hours:Minutes')"
            >
                <div class="input-group">
                    <input
                        v-model.number="durationHours"
                        type="number"
                        class="form-control"
                        min="0"
                        max="23"
                        placeholder="HH"
                        @input="updateDurationFromHours"
                    >
                    <span class="input-group-text">:</span>
                    <input
                        v-model.number="durationMinutes"
                        type="number"
                        class="form-control"
                        min="0"
                        max="59"
                        placeholder="MM"
                        @input="updateDurationFromMinutes"
                    >
                </div>
            </form-markup>

            <form-markup
                id="station_time_zone"
                class="col-md-4"
                :label="$gettext('Station Time Zone')"
            >
                <time-zone />
            </form-markup>

            <!-- Date section -->
            <form-group-field
                id="edit_form_start_date"
                class="col-md-4"
                :field="r$.start_date"
                input-type="date"
                :label="$gettext('Start Date')"
                :description="isRecurring ? $gettext('Required. First date this schedule becomes active; combine with End Date/Repeat below to control when it runs.') : $gettext('Required. This is a one-time event -- it plays only on this date.')"
            />

            <form-group-field
                id="edit_form_end_date"
                class="col-md-4"
                :field="r$.end_date"
                input-type="date"
                :label="$gettext('End Date')"
                :description="!isRecurring
                    ? $gettext('Locked to Start Date -- this is a one-time event. Check Recurring above to enable a different End Date.')
                    : (scheduleRow.recurrence_end_type === 'after'
                        ? $gettext('Not used when stopping after a number of occurrences (see below).')
                        : $gettext('Use with Start date to limit when the schedule runs. Recurrence uses this as the last day.'))"
                :required="isRecurring && scheduleRow.recurrence_end_type !== 'after'"
                :input-attrs="{ disabled: !isRecurring || scheduleRow.recurrence_end_type === 'after' }"
            />

            <div
                v-if="endDateSameAsStart"
                class="col-12"
            >
                <div class="alert alert-danger py-2 px-3 mb-0 small">
                    ⚠ {{ $gettext('End Date must be after Start Date for a recurring event. The event would end immediately and never repeat.') }}
                </div>
            </div>

            <form-markup
                v-if="isClockWheelSchedule"
                id="edit_form_clock_wheel_scheduling"
                class="col-md-4"
                :label="$gettext('Clock Wheel Timing')"
            >
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check mb-0">
                        <input
                            id="clock_wheel_scheduling_flexible"
                            v-model="clockWheelScheduleMode"
                            class="form-check-input"
                            type="radio"
                            value="flexible"
                        >
                        <label class="form-check-label" for="clock_wheel_scheduling_flexible">
                            {{ $gettext('Flexible') }}
                        </label>
                    </div>
                    <div class="form-check mb-0">
                        <input
                            id="clock_wheel_scheduling_strict"
                            v-model="clockWheelScheduleMode"
                            class="form-check-input"
                            type="radio"
                            value="strict"
                        >
                        <label class="form-check-label" for="clock_wheel_scheduling_strict">
                            {{ $gettext('Strict') }}
                        </label>
                    </div>
                </div>
                <small class="form-text text-muted d-block mt-2">
                    {{ $gettext('Flexible prefers full songs when they fit; AutoDJ may cut at anchors only when selection cannot guarantee timing (short slots, strict mode, or no track fits the window).') }}
                </small>
            </form-markup>

            <form-markup
                v-if="isPlaylistSchedule && form.source !== 'smart_block'"
                id="edit_form_scheduling"
                class="col-md-4"
                :label="$gettext('Start Timing')"
            >
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check mb-0">
                        <input
                            id="scheduling_flexible"
                            v-model="startTimingMode"
                            class="form-check-input"
                            type="radio"
                            value="flexible"
                        >
                        <label class="form-check-label" for="scheduling_flexible">
                            {{ $gettext('Flexible') }}
                        </label>
                    </div>
                    <div class="form-check mb-0">
                        <input
                            id="scheduling_strict"
                            v-model="startTimingMode"
                            class="form-check-input"
                            type="radio"
                            value="strict"
                        >
                        <label class="form-check-label" for="scheduling_strict">
                            {{ $gettext('Strict') }}
                        </label>
                    </div>
                </div>
                <small class="form-text text-muted d-block mt-2">
                    {{ $gettext('Flexible waits for the currently playing track to finish before starting. Strict cuts the current track to start exactly on time.') }}
                </small>
                <div class="form-check mt-3">
                    <input
                        id="scheduling_loop_once"
                        v-model="scheduleRow.loop_once"
                        class="form-check-input"
                        type="checkbox"
                    >
                    <label class="form-check-label" for="scheduling_loop_once">
                        {{ $gettext('Loop Once') }}
                    </label>
                </div>
                <small class="form-text text-muted d-block">
                    {{ $gettext('Independent of Start Timing above -- controls whether this playlist loops back through its media during its window, rather than playing through once.') }}
                </small>
            </form-markup>
        </div>

        <div class="mb-3">
            <div class="form-check">
                <input
                    id="edit_form_is_recurring"
                    v-model="isRecurring"
                    class="form-check-input"
                    type="checkbox"
                >
                <label class="form-check-label" for="edit_form_is_recurring">
                    {{ $gettext('Recurring') }}
                </label>
            </div>
            <small class="form-text text-muted">
                {{ $gettext('Check this to repeat the event on a schedule (weekly by default, or bi-weekly/monthly/custom below). Leave unchecked for a one-time event that only plays on its exact Start/End Date.') }}
            </small>
        </div>

        <template v-if="isRecurring">
        <!-- Days of Week -->
        <form-group-multi-check
            id="edit_form_days"
            class="mb-3"
            :field="r$.days"
            :label="$gettext('Scheduled Play Days of Week')"
            :description="daysOfWeekFieldDescription"
            :options="dayOptions"
            :required="!isMonthlyDatePattern"
            :disabled="isMonthlyDatePattern"
            stacked
        />

        <!-- Repeat section -->
        <div class="mb-3">
            <h6 class="text-muted mb-2">
                {{ $gettext('Repeat') }}
            </h6>
        </div>

        <div class="row g-3 mb-3">
            <form-group-select
                id="edit_form_recurrence_type"
                class="col-md-4"
                :field="r$.recurrence_type"
                :label="$gettext('Repeat')"
                :description="$gettext('Weekly = every week; Bi-weekly = every 2 weeks; Custom = every N weeks; Monthly = by date or specific day of week.')"
                :options="recurrenceTypeOptions"
            />

            <form-group-field
                v-if="scheduleRow.recurrence_type === 'custom'"
                id="edit_form_recurrence_interval"
                class="col-md-4"
                :field="r$.recurrence_interval"
                input-type="number"
                min="1"
                max="52"
                :label="$gettext('Every (weeks)')"
                :description="$gettext('E.g. 3 = every 3 weeks. Set Start date for correct alignment.')"
            />

            <template v-if="scheduleRow.recurrence_type === 'monthly'">
                <form-group-select
                    id="edit_form_recurrence_monthly_pattern"
                    class="col-md-4"
                    :field="r$.recurrence_monthly_pattern"
                    :label="$gettext('Monthly Pattern')"
                    :options="recurrenceMonthlyPatternOptions"
                />

                <form-group-field
                    v-if="scheduleRow.recurrence_monthly_pattern === 'date'"
                    id="edit_form_recurrence_monthly_day"
                    class="col-md-4"
                    :field="r$.recurrence_monthly_day"
                    input-type="number"
                    min="1"
                    max="31"
                    :label="$gettext('Day of Month')"
                    :description="$gettext('Day of the month (1–31).')"
                />

                <template v-if="scheduleRow.recurrence_monthly_pattern === 'day_of_week'">
                    <form-group-select
                        id="edit_form_recurrence_monthly_week"
                        class="col-md-4"
                        :field="r$.recurrence_monthly_week"
                        :label="$gettext('Week of Month')"
                        :description="$gettext('For monthly specific day of week.')"
                        :options="recurrenceMonthlyWeekOptions"
                    />
                </template>
            </template>

            <form-group-select
                id="edit_form_recurrence_end_type"
                class="col-md-4"
                :field="r$.recurrence_end_type"
                :label="$gettext('Stop Recurrence')"
                :description="$gettext('Optional: stop after a number of occurrences or use End date above.')"
                :options="recurrenceEndTypeOptions"
            />

            <form-group-field
                v-if="scheduleRow.recurrence_end_type === 'after'"
                id="edit_form_recurrence_end_after"
                class="col-md-4"
                :field="r$.recurrence_end_after"
                input-type="number"
                min="1"
                :label="$gettext('Stop After (occurrences)')"
            />
        </div>
    </template>
        </template>

        <!-- New Schedule editor for the selected source -->
        <div v-if="useNewSchedule" class="calendar-new-schedule">
            <div v-if="nsLoading" class="text-center py-4">
                {{ $gettext('Loading schedule...') }}
            </div>

            <!-- Playlist / Web Stream: full New Schedule editor -->
            <calendar-schedule-editor
                v-else-if="usePlaylistEditor"
                v-model:schedule-items="nsItems"
                :highlight-row="nsTargetRow"
                :highlight-label="nsHighlightLabel"
            />

            <!-- Smart Block / Clock Wheel: same New Schedule layout and row editor -->
            <template v-else>
                <div
                    v-if="currentEntityOptions.length === 0"
                    class="schedule-overview is-unscheduled mb-3"
                >
                    <strong>
                        {{ isClockWheelSchedule ? $gettext('No Clock Wheels yet') : $gettext('No Smart Blocks yet') }}
                    </strong>
                    <span>
                        {{ isClockWheelSchedule
                            ? $gettext('Create a Clock Wheel under Playlists & More > Clock Wheels, then schedule it here.')
                            : $gettext('Create a Smart Block under Playlists & More > Smart Blocks, then schedule it here.') }}
                    </span>
                </div>

                <template v-else>
                    <div class="schedule-summary mb-4">
                        <span class="summary-check"><icon-ic-check-circle /></span>
                        <span>
                            <strong>{{ $gettext('Schedule Summary') }}</strong>
                            <small>{{ rowEditorSummary }}</small>
                        </span>
                    </div>

                    <div class="step-heading step-heading-source mb-3">
                        <span class="step-icon step-icon-source"><icon-ic-event /></span>
                        <span>
                            <strong>{{ $gettext('2. When should this play?') }}</strong>
                            <small>{{ $gettext('Set the days, times, date range, timing mode and repeat rules for each window.') }}</small>
                        </span>
                    </div>

                    <playlists-form-schedule-row
                        v-for="(row, index) in nsItems"
                        :key="index"
                        v-model:row="nsItems[index]"
                        :index="index"
                        :highlight-label="nsTargetRow && row === nsTargetRow ? nsHighlightLabel : null"
                        @remove="nsItems.splice(index, 1)"
                    />

                    <div class="buttons mb-4">
                        <button
                            type="button"
                            class="btn btn-primary add-schedule-button"
                            @click="addRowEditorItem"
                        >
                            <icon-ic-add />
                            <span>{{ $gettext('Add Schedule Item') }}</span>
                        </button>
                    </div>

                </template>
            </template>
        </div>

        <template
            v-if="editingScheduleId !== null"
            #modal-footer
        >
            <button
                type="button"
                class="btn btn-danger me-auto"
                :disabled="loading"
                @click="doDelete"
            >
                {{ $gettext('Delete') }}
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
                :disabled="loading || !isFormValid"
                @click="doSave"
            >
                {{ $gettext('Save Changes') }}
            </button>
        </template>
    </component>
</template>

<script setup lang="ts">
import ModalForm from '~/components/Common/ModalForm.vue';
import InlineForm from '~/components/Common/InlineForm.vue';
import IconIcEvent from '~icons/ic/baseline-event';
import CalendarScheduleEditor from '~/components/Stations/Common/CalendarScheduleEditor.vue';
import PlaylistsFormScheduleRow from '~/components/Stations/Common/CalendarScheduleRow.vue';
import IconIcAdd from '~icons/ic/baseline-add';
import IconIcCheckCircle from '~icons/ic/baseline-check-circle';
import {useStationsPlaylistsForm} from '~/components/Stations/Playlists/Form/form.ts';
import mergeExisting from '~/functions/mergeExisting.ts';
import {storeToRefs} from 'pinia';
import AmPmTimeInput from '~/components/Common/AmPmTimeInput.vue';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import FormGroupCheckbox from '~/components/Form/FormGroupCheckbox.vue';
import FormGroupMultiCheck from '~/components/Form/FormGroupMultiCheck.vue';
import FormGroupSelect from '~/components/Form/FormGroupSelect.vue';
import FormMarkup from '~/components/Form/FormMarkup.vue';
import TimeZone from '~/components/Stations/Common/TimeZone.vue';
import {applyIf, minLength, minValue, required, requiredIf, withMessage} from '@regle/rules';
import {createRule} from '@regle/core';
import {useAppScopedRegle} from '~/vendor/regle.ts';
import {ref, shallowRef, computed, nextTick, onMounted, watch, useTemplateRef} from 'vue';
import {toRefs} from '@vueuse/core';
import {DateTime} from 'luxon';
import {useStationData} from '~/functions/useStationQuery.ts';
import {useTranslate} from '~/vendor/gettext';
import {useAxios} from '~/vendor/axios';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useDialog} from '~/components/Common/Dialogs/useDialog.ts';
import {
    type PlaylistScheduleRow,
    createScheduleItemDefaults,
} from '~/components/Stations/Common/scheduleItemDefaults.ts';
import normalizeStationScheduleDays from '~/functions/normalizeStationScheduleDays';
import {scheduleTimeWindowForHourOfDay} from '~/functions/amPmTime.ts';

const {$gettext} = useTranslate();
const {axios} = useAxios();

const stationData = useStationData();
const {timezone: stationTimezone} = toRefs(stationData);
const {getStationApiUrl} = useApiRouter();
const {notifySuccess} = useNotify();
const {confirmDelete} = useDialog();

withDefaults(defineProps<{
    // Render in place on the page (calendar) instead of as a popup.
    inline?: boolean,
}>(), {
    inline: false,
});

const emit = defineEmits<{
    relist: [];
    shown: [];
    hidden: [];
}>();

interface EntityOption {
    id: number;
    name: string;
    self_url: string;
    source?: string;
}

interface ClockWheelOption extends EntityOption {
    /** Set on daypart-generated hourly wheels (0–23). */
    hour_of_day: number | null;
}

const playlists = ref<EntityOption[]>([]);
const smartBlocks = ref<EntityOption[]>([]);
const webStreams = ref<EntityOption[]>([]);
const clockWheels = ref<ClockWheelOption[]>([]);

onMounted(async () => {
    const [plResp, webResp, cwResp, sbResp] = await Promise.all([
        axios.get(getStationApiUrl('/playlists').value),
        axios.get(getStationApiUrl('/playlists').value, {
            params: {rowCount: -1, include_remote_url: 1},
        }),
        axios.get(getStationApiUrl('/clock-wheels').value),
        axios.get(getStationApiUrl('/smart-blocks').value),
    ]);

    smartBlocks.value = (sbResp.data as Array<Record<string, unknown>>).map((b) => ({
        id: b.id as number,
        name: b.name as string,
        self_url: (b.links as Record<string, string>).self,
    }));

    playlists.value = (plResp.data as Array<Record<string, unknown>>).map((p) => ({
        id: p.id as number,
        name: p.name as string,
        self_url: (p.links as Record<string, string>).self,
    }));

    const webRows = Array.isArray(webResp.data)
        ? webResp.data
        : ((webResp.data as {rows?: Array<Record<string, unknown>>}).rows ?? []);

    webStreams.value = webRows.map((p) => ({
        id: p.id as number,
        name: p.name as string,
        self_url: (p.links as Record<string, string>).self,
        source: p.source as string,
    }));

    clockWheels.value = (cwResp.data as Array<Record<string, unknown>>).map((cw) => ({
        id: cw.id as number,
        name: cw.name as string,
        self_url: (cw.links as Record<string, string>).self,
        hour_of_day: cw.hour_of_day != null ? Number(cw.hour_of_day) : null,
    }));
});

const blankForm = () => ({
    source: 'playlist' as 'playlist' | 'smart_block' | 'clock_wheel' | 'web_stream',
    entity_id: null as number | null,
});

const form = ref(blankForm());

/** The entity API each source saves its calendar times to: /playlist/{id},
 *  /clock-wheel/{id} or /smart-block/{id}. Web streams are playlists. Kept in
 *  one place so every caller below agrees. */
const apiEntityType = (source: string): 'playlist' | 'clock-wheel' | 'smart-block' => {
    if ('clock_wheel' === source) {
        return 'clock-wheel';
    }
    return 'smart_block' === source ? 'smart-block' : 'playlist';
};

const startTimingMode = ref<'flexible' | 'strict'>('flexible');
const clockWheelScheduleMode = ref<'flexible' | 'strict'>('flexible');

// Schedule row state - matches PlaylistScheduleRow interface
const scheduleRow = ref<PlaylistScheduleRow>(createScheduleItemDefaults());

// ── New Schedule editor (Playlist / Web Stream sources) ─────────────────────
// Playlist events open the New Schedule editor, which shows every schedule
// window of the playlist plus its start/end behavior, presets and summary.
const playlistFormStore = useStationsPlaylistsForm();
const {form: playlistForm, r$: playlistR$} = storeToRefs(playlistFormStore);

const nsItems = ref<Array<any>>([]);
const nsLoading = ref(false);
const nsTargetRow = shallowRef<Record<string, any> | null>(null);
// True only when the editor was opened from a clicked time slot or a dropped
// item: then a window for that time is added. Opening an existing event or the
// Create Event button shows the saved schedule as-is.
const nsAddNewRow = ref(false);

// Tag on the highlighted window: the clicked event, or the window a clicked
// time slot just added.
const nsHighlightLabel = computed(() => (
    editingScheduleId.value !== null ? $gettext('This event') : $gettext('New')
));

// Every source uses the New Schedule design. Playlists and web streams get the
// full editor (start/end behavior and presets are playlist settings); Smart
// Blocks and Clock Wheels get the same layout and row editor.
const useNewSchedule = computed(() => true);
const usePlaylistEditor = computed(() =>
    form.value.source === 'playlist' || form.value.source === 'web_stream'
);

const apiItemToNewScheduleRow = (item: Record<string, unknown>): Record<string, any> => {
    const row: Record<string, any> = {...item, ...apiScheduleItemToRow(item)};
    delete row.playlist;
    delete row.streamer;
    delete row.clock_wheel;
    return row;
};

const loadNewSchedule = async () => {
    const entityId = form.value.entity_id;
    if (!useNewSchedule.value || !entityId) {
        return;
    }
    // While the Source is switching, entity_id can still hold the previous
    // source's id for a moment; only load an item that belongs to this source.
    if (!currentEntityOptions.value.some((option) => option.id === entityId)) {
        return;
    }

    const source = form.value.source;
    nsLoading.value = true;
    try {
        const {data} = await axios.get(getStationApiUrl(`/${apiEntityType(source)}/${entityId}`).value);
        if (form.value.entity_id !== entityId || form.value.source !== source) {
            return;
        }

        error.value = null;

        if (usePlaylistEditor.value) {
            playlistFormStore.$reset();
            playlistR$.value.$reset({toState: mergeExisting(playlistR$.value.$value, data)});
        }

        const rows = ((data.schedule_items ?? []) as Record<string, unknown>[]).map(apiItemToNewScheduleRow);
        if (source === 'clock_wheel') {
            // Clock wheels store Flexible/Strict as clock_wheel_mode.
            rows.forEach((row) => {
                row.strict_start = row.clock_wheel_mode === 'strict';
            });
        }

        let targetIndex = editingScheduleId.value !== null
            ? rows.findIndex((row) => Number(row.id) === editingScheduleId.value)
            : -1;

        if (targetIndex === -1 && nsAddNewRow.value) {
            // New event from the calendar: add a window at the clicked/dropped time.
            const newRow: Record<string, any> = {
                ...createScheduleItemDefaults(),
                ...scheduleRow.value,
                days: [...(scheduleRow.value.days ?? [])],
                prevent_requests: false,
                reset_queue_at_start: false,
                reset_queue_recursive: false,
            };
            if (newRow.days.length === 0 && newRow.start_date) {
                const weekday = DateTime.fromISO(newRow.start_date).weekday;
                if (weekday) {
                    newRow.days = [weekday];
                }
            }
            if (!newRow.recurrence_type) {
                newRow.recurrence_type = 'weekly';
                newRow.recurrence_interval = 1;
            }
            // A clicked slot only gives a start time; default to a one-hour window.
            if (newRow.start_time !== null && newRow.start_time === newRow.end_time) {
                newRow.end_time = (Number(newRow.start_time) + 100) % 2400;
            }
            delete newRow.id;
            rows.push(newRow);
            targetIndex = rows.length - 1;
        }

        nsItems.value = rows;
        nsTargetRow.value = targetIndex >= 0 ? (nsItems.value[targetIndex] ?? null) : null;

        // Bring the window this event came from into view (once the cards
        // are rendered, i.e. after the loading state ends).
        if (nsTargetRow.value) {
            nsLoading.value = false;
            await nextTick();
            document.querySelector('.calendar-new-schedule .is-this-event')
                ?.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
    } catch (e: unknown) {
        const err = e as {response?: {data?: {message?: string}}};
        error.value = err?.response?.data?.message ?? $gettext('An error occurred.');
    } finally {
        nsLoading.value = false;
    }
};

const addRowEditorItem = () => {
    nsItems.value.push({
        ...createScheduleItemDefaults(),
        start_time: null,
        end_time: null,
        start_date: null,
        end_date: null,
        days: [],
        prevent_requests: false,
        reset_queue_at_start: false,
        reset_queue_recursive: false,
        recurrence_type: 'weekly',
        recurrence_interval: 1,
        recurrence_end_type: 'never',
    });
};

const formatTimeCode = (timeCode: unknown): string => {
    if (timeCode === null || timeCode === undefined || timeCode === '') {
        return '—';
    }
    const padded = String(timeCode).padStart(4, '0');
    const hour24 = Number(padded.slice(0, 2));
    const hour12 = hour24 % 12 || 12;
    return `${hour12}:${padded.slice(2, 4)} ${hour24 >= 12 ? 'PM' : 'AM'}`;
};

const rowEditorSummary = computed(() => {
    const first = nsItems.value[0];
    if (!first) {
        return $gettext('No scheduled window is configured.');
    }
    const dayLabels: Record<number, string> = {
        1: $gettext('Mon'), 2: $gettext('Tue'), 3: $gettext('Wed'), 4: $gettext('Thu'),
        5: $gettext('Fri'), 6: $gettext('Sat'), 7: $gettext('Sun'),
    };
    const days = Array.isArray(first.days) && first.days.length > 0
        ? first.days.map((d: number) => dayLabels[d] ?? String(d)).join(', ')
        : $gettext('Any day');
    const dates = first.start_date && first.end_date
        ? `${first.start_date} – ${first.end_date}`
        : $gettext('Date range not complete');
    const mode = first.strict_start ? $gettext('Strict scheduling') : $gettext('Flexible scheduling');
    const extra = nsItems.value.length > 1
        ? $gettext(' • %{count} schedule windows total', {count: nsItems.value.length})
        : '';
    return `${days} ${formatTimeCode(first.start_time)} – ${formatTimeCode(first.end_time)} • ${dates} • ${mode}${extra}`;
});

const emergencyOverride = computed({
    get: () => useNewSchedule.value
        ? Boolean(nsTargetRow.value?.is_emergency)
        : Boolean(scheduleRow.value.is_emergency),
    set: (value: boolean) => {
        if (useNewSchedule.value) {
            if (nsTargetRow.value) {
                nsTargetRow.value.is_emergency = value;
            }
        } else {
            scheduleRow.value.is_emergency = value;
        }
    },
});

const newSchedulePayload = (): Record<string, unknown>[] => nsItems.value.map((item) => {
    const out: Record<string, unknown> = {...item};
    out.recurrence_type = item.recurrence_type ?? 'weekly';
    out.recurrence_interval = (item.recurrence_type === 'biweekly' ? 2 : Number(item.recurrence_interval)) || 1;
    out.recurrence_end_type = item.recurrence_end_type ?? 'never';
    out.recurrence_end_after = (item.recurrence_end_type === 'after' && item.recurrence_end_after != null)
        ? Number(item.recurrence_end_after) : null;
    out.recurrence_end_date = null;
    if (item.recurrence_end_type === 'after') {
        out.end_date = null;
    }
    const normalizedDays = normalizeStationScheduleDays(item.days);
    if (out.recurrence_type === 'monthly' && out.recurrence_monthly_pattern === 'date') {
        out.days = [];
    } else {
        out.days = normalizedDays;
    }
    if (out.recurrence_type === 'monthly' && out.recurrence_monthly_pattern === 'day_of_week' && normalizedDays.length > 0) {
        out.recurrence_monthly_day_of_week = normalizedDays[0];
    }
    if (out.id == null) {
        delete out.id;
    }
    delete out.playlist;
    delete out.streamer;
    delete out.clock_wheel;
    return out;
});

const loading = ref(false);
const error = ref<string | null>(null);
const $modal = useTemplateRef('$modal');

// Duration state
const durationHours = ref(1);
const durationMinutes = ref(0);

// Recurring toggle
const isRecurring = ref(false);

// When loading an existing schedule item, don't treat the programmatic
// isRecurring assignment like the user just toggled Recurring on/off.
// Without this guard, the watcher below clears the loaded end_date.
const isHydratingExistingSchedule = ref(false);

// Checking "Recurring" requires an explicit repeat pattern (defaults to Weekly)
// rather than silently staying null. Unchecking clears it back to plain,
// non-recurring behavior (event only plays on its exact Start/End Date).
watch(isRecurring, (recurring) => {
    if (isHydratingExistingSchedule.value) {
        return;
    }
    if (recurring) {
        if (!scheduleRow.value.recurrence_type) {
            scheduleRow.value.recurrence_type = 'weekly';
        }
        // Clear end_date when switching to recurring — user must deliberately
        // pick an end date, so it can't accidentally save as the same day as start.
        scheduleRow.value.end_date = '';
    } else {
        scheduleRow.value.recurrence_type = null;
        scheduleRow.value.recurrence_monthly_pattern = null;
        scheduleRow.value.recurrence_monthly_day = null;
        scheduleRow.value.recurrence_monthly_week = null;
        scheduleRow.value.recurrence_monthly_day_of_week = null;
        scheduleRow.value.recurrence_end_type = 'never';
        scheduleRow.value.recurrence_end_after = null;
        scheduleRow.value.days = [];
        scheduleRow.value.end_date = scheduleRow.value.start_date;
    }
}, {flush: 'sync'});

// Keep End Date locked to Start Date for one-time (non-recurring) events at all
// times -- not just at the moment Recurring is unchecked -- so later changing
// Start Date can never leave a stale End Date that predates it.
watch(
    () => scheduleRow.value.start_date,
    (newStartDate) => {
        if (!isRecurring.value) {
            scheduleRow.value.end_date = newStartDate;
        }
    }
);

// Update end_time from duration inputs
const updateDuration = () => {
    const startTime = scheduleRow.value.start_time;
    const startHours = Math.floor(startTime / 100);
    const startMinutes = startTime % 100;
    const durationTotalMinutes = durationHours.value * 60 + durationMinutes.value;
    let endTotalMinutes = startHours * 60 + startMinutes + durationTotalMinutes;
    endTotalMinutes = endTotalMinutes % (24 * 60);
    const endHours = Math.floor(endTotalMinutes / 60);
    const endMinutes = endTotalMinutes % 60;
    const newEndTime = endHours * 100 + endMinutes;

    // Write through r$.end_time.$model (the same path the End Time field itself
    // writes to) rather than the raw scheduleRow object directly -- the field's
    // validation wrapper may not reactively pick up a direct object mutation.
    r$.end_time.$model = newEndTime;
    scheduleRow.value.end_time = newEndTime;
};

const updateDurationFromHours = () => updateDuration();
const updateDurationFromMinutes = () => updateDuration();

// Compute Duration display (hours/minutes) from the actual loaded start/end times,
// so editing an existing event shows its real duration instead of the 1:00 default.
const syncDurationFromTimes = () => {
    const startTime = scheduleRow.value.start_time;
    const endTime = scheduleRow.value.end_time;
    const startTotalMinutes = Math.floor(startTime / 100) * 60 + (startTime % 100);
    let endTotalMinutes = Math.floor(endTime / 100) * 60 + (endTime % 100);

    if (endTotalMinutes < startTotalMinutes) {
        endTotalMinutes += 24 * 60;
    }

    const diffMinutes = endTotalMinutes - startTotalMinutes;
    durationHours.value = Math.floor(diffMinutes / 60);
    durationMinutes.value = diffMinutes % 60;
};

const applyClockWheelHourToSchedule = (entityId: number | null) => {
    if (entityId == null) {
        return;
    }

    const wheel = clockWheels.value.find((w) => w.id === entityId);
    if (wheel?.hour_of_day == null) {
        return;
    }

    const {start_time, end_time} = scheduleTimeWindowForHourOfDay(wheel.hour_of_day);
    scheduleRow.value.start_time = start_time;
    scheduleRow.value.end_time = end_time;
    durationHours.value = 1;
    durationMinutes.value = 0;
};

const onEntityChange = () => {
    if (isClockWheelSchedule.value) {
        applyClockWheelHourToSchedule(form.value.entity_id);
    }
};

const currentEntityOptions = computed(() => {
    if (form.value.source === 'clock_wheel') {
        return clockWheels.value;
    }
    if (form.value.source === 'web_stream') {
        return webStreams.value;
    }
    if (form.value.source === 'smart_block') {
        return smartBlocks.value;
    }
    return playlists.value;
});

const isPlaylistSchedule = computed(() =>
    form.value.source === 'playlist'
    || form.value.source === 'smart_block'
    || form.value.source === 'web_stream'
);
const isClockWheelSchedule = computed(() => form.value.source === 'clock_wheel');

watch(
    () => [useNewSchedule.value, form.value.source, form.value.entity_id] as const,
    ([enabled, , entityId]) => {
        nsItems.value = [];
        nsTargetRow.value = null;
        if (enabled && entityId) {
            void loadNewSchedule();
        }
    }
);

// Auto-select first entity whenever options change or source changes
watch(currentEntityOptions, (opts) => {
    if (opts.length > 0 && (form.value.entity_id === null || !opts.find(e => e.id === form.value.entity_id))) {
        form.value.entity_id = opts[0].id;
    }
}, {immediate: true});

watch(
    () => form.value.entity_id,
    (entityId, previousId) => {
        if (!isClockWheelSchedule.value || entityId == null) {
            return;
        }

        // Editing an existing event: only adjust times when the user picks another wheel.
        if (editingScheduleId.value !== null && previousId == null) {
            return;
        }

        applyClockWheelHourToSchedule(entityId);
    }
);

watch(startTimingMode, (mode) => {
    if (!isPlaylistSchedule.value) {
        return;
    }
    scheduleRow.value.strict_start = mode === 'strict';
});

watch(
    () => form.value.source,
    (source) => {
        if (source === 'clock_wheel') {
            scheduleRow.value.loop_once = false;
            scheduleRow.value.strict_start = false;
            scheduleRow.value.clock_wheel_mode = clockWheelScheduleMode.value;
            scheduleRow.value.is_emergency = false;
        }
    }
);

watch(clockWheelScheduleMode, (mode) => {
    if (isClockWheelSchedule.value) {
        scheduleRow.value.clock_wheel_mode = mode;
    }
});

// Regle validation for schedule row
const isMonthlyDatePattern = computed(
    () => scheduleRow.value.recurrence_type === 'monthly' && scheduleRow.value.recurrence_monthly_pattern === 'date'
);

const isMonthlyDayOfWeekPattern = computed(
    () => scheduleRow.value.recurrence_type === 'monthly' && scheduleRow.value.recurrence_monthly_pattern === 'day_of_week'
);

const requiresDaysOfWeek = computed(() => isRecurring.value && !isMonthlyDatePattern.value);

const daysOfWeekFieldDescription = computed(() => {
    if (isMonthlyDatePattern.value) {
        return $gettext('Not used when monthly pattern is "On day of month" — pick the calendar day below instead.');
    }
    if (isMonthlyDayOfWeekPattern.value) {
        return $gettext('For monthly "specific day of week", select one or more days; each gets that week-of-month (e.g. 1st + Mon–Wed).');
    }
    return $gettext('Select at least one day of the week.');
});

// Custom rule: for recurring events, end date must be strictly after start date.
// Using createRule so the validator function has access to external reactive state
// (isRecurring, scheduleRow.start_date) without being limited to just the field value.
const endDateAfterStart = createRule({
    validator: (endDate: unknown) => {
        if (!isRecurring.value) return true;
        const end = String(endDate ?? '').trim();
        const start = (scheduleRow.value.start_date ?? '').trim();
        if (!end || !start) return true; // required validator handles the empty case
        // YYYY-MM-DD string comparison is safe for ISO dates
        return end > start;
    },
    message: () => $gettext('End Date must be after Start Date for recurring events.'),
});

const {r$} = useAppScopedRegle(
    scheduleRow,
    {
        start_time: {required},
        end_time: {required},
        start_date: {required},
        end_date: {
            required: requiredIf(() => isRecurring.value && scheduleRow.value.recurrence_end_type !== 'after'),
            endDateAfterStart,
        },
        days: {
            minLength: withMessage(
                applyIf(requiresDaysOfWeek, minLength(1)),
                () => $gettext('Select at least one day of the week.')
            ),
        },
        recurrence_type: {
            required: requiredIf(() => isRecurring.value),
        },
        recurrence_end_after: {
            required: requiredIf(() => scheduleRow.value.recurrence_end_type === 'after'),
            minValue: minValue(1),
        },
        recurrence_monthly_day: {
            required: requiredIf(
                () => scheduleRow.value.recurrence_type === 'monthly' && scheduleRow.value.recurrence_monthly_pattern === 'date'
            ),
        },
    },
    {
        namespace: 'stations-playlists'
    }
);

// Keep Duration display live and consistent: whenever Start Time or End Time
// is edited directly (not via the Duration boxes themselves), recalculate
// Duration to reflect the new gap. Duration only ever drives End Time in the
// other direction (see updateDuration above) -- this just mirrors that so
// editing any one of the three fields keeps the other two in sync.
watch(() => scheduleRow.value.start_time, () => {
    syncDurationFromTimes();
});
watch(() => scheduleRow.value.end_time, () => {
    syncDurationFromTimes();
});

// Sync recurrence_interval when type changes
watch(
    () => scheduleRow.value.recurrence_type,
    (newType: string | null) => {
        if (newType === 'biweekly') {
            scheduleRow.value.recurrence_interval = 2;
        } else if (newType === 'weekly') {
            scheduleRow.value.recurrence_interval = 1;
        }
    }
);

// Clear days when monthly date pattern is selected
watch(
    () => [scheduleRow.value.recurrence_type, scheduleRow.value.recurrence_monthly_pattern] as const,
    () => {
        if (isMonthlyDatePattern.value) {
            scheduleRow.value.days = [];
        }
    }
);

// True when end_date is set for a recurring event but is NOT after start_date.
// Checked both here (to disable Save) and in the template (to show a warning).
const endDateSameAsStart = computed(() =>
    isRecurring.value
    && !!scheduleRow.value.end_date
    && !!scheduleRow.value.start_date
    && scheduleRow.value.end_date <= scheduleRow.value.start_date
);

const isFormValid = computed(() => {
    if (form.value.entity_id === null) {
        return false;
    }
    if (useNewSchedule.value) {
        return !nsLoading.value && nsItems.value.every((row) =>
            row.start_time !== null && row.start_time !== undefined && row.start_time !== ''
            && row.end_time !== null && row.end_time !== undefined && row.end_time !== ''
            && !!row.start_date
        );
    }
    return !r$.$invalid && !endDateSameAsStart.value;
});

const onSourceChange = () => {
    error.value = null;
    // Pick the first item of the new source. Browsers flush Vue's watchers between
    // the select's input and change events, so the auto-select watcher has already
    // run by now and would otherwise be undone here, leaving the picker blank.
    form.value.entity_id = currentEntityOptions.value[0]?.id ?? null;
    if (form.value.source === 'clock_wheel') {
        scheduleRow.value.loop_once = false;
        scheduleRow.value.strict_start = false;
        clockWheelScheduleMode.value = scheduleRow.value.clock_wheel_mode ?? 'flexible';
    }
};

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
    {value: 'never', text: $gettext('Never (use End date above to limit range)')},
    {value: 'after', text: $gettext('After number of occurrences')}
];

const editingScheduleId = ref<number | null>(null);

const modalTitle = computed(() =>
    editingScheduleId.value !== null
        ? $gettext('Edit Event')
        : $gettext('Create Event')
);

const applyCalendarTimesToRow = (start: Date, end?: Date) => {
    // IMPORTANT: the calendar displays (and drag-drop positions events) in the
    // STATION's configured timezone, not necessarily the browser's local timezone.
    // Reading .getHours()/.getMinutes() directly off the JS Date object returns
    // components in the *browser's* local time, and .toISOString() returns the
    // date in UTC -- mixing those two was the bug that caused a dropped/clicked
    // slot to end up saved at a different time than where it visually landed
    // whenever the station's timezone differs from the visitor's own. Converting
    // explicitly into the station's timezone first keeps date and time consistent
    // with what was actually clicked/dropped on the calendar.
    const startInStationTz = DateTime.fromJSDate(start, {zone: stationTimezone.value});

    scheduleRow.value.start_date = startInStationTz.toFormat('yyyy-MM-dd');
    scheduleRow.value.end_date = startInStationTz.toFormat('yyyy-MM-dd');
    scheduleRow.value.start_time = Number(startInStationTz.toFormat('HHmm'));

    if (end) {
        const endInStationTz = DateTime.fromJSDate(end, {zone: stationTimezone.value});
        scheduleRow.value.end_time = Number(endInStationTz.toFormat('HHmm'));
    }
};

const apiScheduleItemToRow = (item: Record<string, unknown>): PlaylistScheduleRow => {
    const endType = (item.recurrence_end_type as string | undefined) ?? 'never';
    const recurrenceType = item.recurrence_type as string | null | undefined;

    const row: PlaylistScheduleRow = {
        start_time: Number(item.start_time),
        end_time: Number(item.end_time),
        start_date: String(item.start_date ?? ''),
        end_date: String(item.end_date ?? ''),
        days: normalizeStationScheduleDays(item.days),
        loop_once: Boolean(item.loop_once),
        is_emergency: Boolean(item.is_emergency),
        strict_start: Boolean(item.strict_start),
        clock_wheel_mode: (item.clock_wheel_mode === 'strict' ? 'strict' : 'flexible') as 'flexible' | 'strict',
        recurrence_type: recurrenceType ?? null,
        recurrence_interval: Number(item.recurrence_interval ?? 1),
        recurrence_monthly_pattern: (item.recurrence_monthly_pattern as string | null) ?? null,
        recurrence_monthly_day: item.recurrence_monthly_day != null ? Number(item.recurrence_monthly_day) : null,
        recurrence_monthly_week: item.recurrence_monthly_week != null ? Number(item.recurrence_monthly_week) : null,
        recurrence_monthly_day_of_week: item.recurrence_monthly_day_of_week != null
            ? Number(item.recurrence_monthly_day_of_week)
            : null,
        recurrence_end_type: endType === 'on_date' ? 'never' : endType,
        recurrence_end_after: endType === 'after' && item.recurrence_end_after != null
            ? Number(item.recurrence_end_after)
            : null,
        recurrence_end_date: null,
    };

    if (
        row.recurrence_type === 'monthly'
        && row.recurrence_monthly_pattern === 'day_of_week'
        && row.recurrence_monthly_day_of_week != null
        && row.days.length === 0
    ) {
        row.days = [row.recurrence_monthly_day_of_week];
    }

    return row;
};

const buildSchedulePayload = (
    row: PlaylistScheduleRow,
    scheduleId?: number
): PlaylistScheduleRow & {id?: number} => {
    const out: PlaylistScheduleRow & {id?: number} = {
        ...row,
        end_date: row.end_date || row.start_date,
        recurrence_type: row.recurrence_type,
        recurrence_interval: (row.recurrence_type === 'biweekly' ? 2 : Number(row.recurrence_interval)) || 1,
        recurrence_end_type: row.recurrence_end_type ?? 'never',
        recurrence_end_after: (row.recurrence_end_type === 'after' && row.recurrence_end_after != null)
            ? Number(row.recurrence_end_after)
            : null,
        recurrence_end_date: null,
    };

    if (out.recurrence_end_type === 'after') {
        out.end_date = '';
    }

    const normalizedDays = normalizeStationScheduleDays(row.days);
    if (out.recurrence_type === 'monthly' && out.recurrence_monthly_pattern === 'date') {
        out.days = [];
    } else {
        out.days = normalizedDays;
    }

    if (
        out.recurrence_type === 'monthly'
        && out.recurrence_monthly_pattern === 'day_of_week'
        && normalizedDays.length > 0
    ) {
        out.recurrence_monthly_day_of_week = normalizedDays[0];
    }

    if (scheduleId !== undefined) {
        out.id = scheduleId;
    }

    return out;
};

const isScopedMode = ref(false);

const clearForm = () => {
    form.value = blankForm();
    startTimingMode.value = 'flexible';
    clockWheelScheduleMode.value = 'flexible';
    scheduleRow.value = createScheduleItemDefaults();
    isRecurring.value = false;
    error.value = null;
    editingScheduleId.value = null;
    isScopedMode.value = false;
    nsItems.value = [];
    nsTargetRow.value = null;
    nsAddNewRow.value = false;
};

const onFormHidden = () => {
    clearForm();
    emit('hidden');
};

const open = () => {
    clearForm();
    // If options are already loaded, auto-select the first one (watch won't re-fire if options didn't change)
    if (currentEntityOptions.value.length > 0) {
        form.value.entity_id = currentEntityOptions.value[0].id;
    }
    ($modal.value as any)?.show();
};

const openForEdit = async (event: EventImpl) => {
    clearForm();

    const editUrl = event.extendedProps.edit_url as string | undefined;
    const scheduleIdRaw = event.extendedProps.schedule_id as number | string | undefined;
    const scheduleId = scheduleIdRaw !== undefined ? Number(scheduleIdRaw) : NaN;
    editingScheduleId.value = Number.isFinite(scheduleId) ? scheduleId : null;

    if (editUrl?.includes('/clock-wheel/')) {
        form.value.source = 'clock_wheel';
    } else if (editUrl?.includes('/smart-block/')) {
        form.value.source = 'smart_block';
    } else if (event.extendedProps.source === 'remote_url') {
        form.value.source = 'web_stream';
    } else {
        form.value.source = 'playlist';
    }

    if (editUrl) {
        const m = editUrl.match(/\/(playlist|clock-wheel|smart-block)\/(\d+)/);
        if (m?.[2]) {
            form.value.entity_id = Number(m[2]);
        }
    }

    if (!form.value.entity_id && currentEntityOptions.value.length > 0) {
        form.value.entity_id = currentEntityOptions.value[0].id;
    }

    ($modal.value as any)?.show();

    const start = event.start;
    const end = event.end ?? undefined;

    if (form.value.entity_id && editingScheduleId.value !== null) {
        loading.value = true;
        error.value = null;

        try {
            const entityType = apiEntityType(form.value.source);
            const entityApiUrl = getStationApiUrl(`/${entityType}/${form.value.entity_id}`).value;
            const {data: entityData} = await axios.get(entityApiUrl);
            const items = (entityData.schedule_items as Record<string, unknown>[] | undefined) ?? [];
            const existing = items.find((row) => Number(row.id) === editingScheduleId.value);

            if (existing) {
                isHydratingExistingSchedule.value = true;
                scheduleRow.value = apiScheduleItemToRow(existing);
                isRecurring.value = existing.recurrence_type != null && existing.recurrence_type !== '';
                isHydratingExistingSchedule.value = false;

                syncDurationFromTimes();

                if (form.value.source === 'clock_wheel') {
                    scheduleRow.value.loop_once = false;
                    clockWheelScheduleMode.value = scheduleRow.value.clock_wheel_mode ?? 'flexible';
                } else {
                    startTimingMode.value = scheduleRow.value.strict_start ? 'strict' : 'flexible';
                }
            } else if (start) {
                applyCalendarTimesToRow(start, end);
                syncDurationFromTimes();
            }
        } catch (e: unknown) {
            const err = e as {response?: {data?: {message?: string}}};
            error.value = err?.response?.data?.message ?? $gettext('An error occurred.');
            if (start) {
                applyCalendarTimesToRow(start, end);
                syncDurationFromTimes();
            }
        } finally {
            loading.value = false;
        }
    } else if (start) {
        applyCalendarTimesToRow(start, end);
    }
};

// Used from the Playlist/Clock Wheel edit modals' own "Schedule" tab, where
// we already know exactly which item we're scheduling -- no FullCalendar
// event object involved, so no Source/Entity dropdown needed at all.
const openScopedForCreate = (
    source: 'playlist' | 'smart_block' | 'clock_wheel' | 'web_stream',
    entityId: number
) => {
    clearForm();
    nsAddNewRow.value = true;
    isScopedMode.value = true;
    form.value.source = source;
    form.value.entity_id = entityId;
    ($modal.value as any)?.show();
};

const openScopedForEdit = async (
    source: 'playlist' | 'smart_block' | 'clock_wheel' | 'web_stream',
    entityId: number,
    scheduleId: number,
) => {
    clearForm();
    isScopedMode.value = true;
    form.value.source = source;
    form.value.entity_id = entityId;
    editingScheduleId.value = scheduleId;

    ($modal.value as any)?.show();

    loading.value = true;
    error.value = null;

    try {
        const entityType = apiEntityType(source);
        const entityApiUrl = getStationApiUrl(`/${entityType}/${entityId}`).value;
        const {data: entityData} = await axios.get(entityApiUrl);
        const items = (entityData.schedule_items as Record<string, unknown>[] | undefined) ?? [];
        const existing = items.find((row) => Number(row.id) === scheduleId);

        if (existing) {
            isHydratingExistingSchedule.value = true;
            scheduleRow.value = apiScheduleItemToRow(existing);
            isRecurring.value = existing.recurrence_type != null && existing.recurrence_type !== '';
            isHydratingExistingSchedule.value = false;

            syncDurationFromTimes();

            if (source === 'clock_wheel') {
                scheduleRow.value.loop_once = false;
                clockWheelScheduleMode.value = scheduleRow.value.clock_wheel_mode ?? 'flexible';
            } else {
                startTimingMode.value = scheduleRow.value.strict_start ? 'strict' : 'flexible';
            }
        }
    } catch (e: unknown) {
        const err = e as {response?: {data?: {message?: string}}};
        error.value = err?.response?.data?.message ?? $gettext('An error occurred.');
    } finally {
        loading.value = false;
    }
};

const doSave = async () => {
    if (!form.value.entity_id) return;

    loading.value = true;
    error.value = null;

    if (useNewSchedule.value) {
        try {
            const entityApiUrl = getStationApiUrl(`/${apiEntityType(form.value.source)}/${form.value.entity_id}`).value;
            if (usePlaylistEditor.value) {
                await axios.put(entityApiUrl, {
                    schedule_items: newSchedulePayload(),
                    backend_options: playlistForm.value.backend_options,
                    is_sponsor: playlistForm.value.is_sponsor,
                    sponsor_name: playlistForm.value.sponsor_name,
                    sponsor_guaranteed_plays_per_day: playlistForm.value.sponsor_guaranteed_plays_per_day,
                });
            } else {
                const items = newSchedulePayload();
                if (form.value.source === 'clock_wheel') {
                    items.forEach((item) => {
                        item.clock_wheel_mode = item.strict_start ? 'strict' : 'flexible';
                        item.strict_start = false;
                        item.loop_once = false;
                        item.is_emergency = false;
                    });
                }
                await axios.put(entityApiUrl, {schedule_items: items});
            }

            notifySuccess(editingScheduleId.value !== null ? $gettext('Event updated.') : $gettext('Event created.'));
            ($modal.value as any)?.hide();
            emit('relist');
        } catch (e: unknown) {
            const err = e as {response?: {data?: {message?: string}}};
            error.value = err?.response?.data?.message ?? $gettext('An error occurred.');
        } finally {
            loading.value = false;
        }
        return;
    }

    try {
        // Build URL using getStationApiUrl to avoid Docker-internal host issues
        // Note: individual endpoints use singular: /playlist/{id} and /clock-wheel/{id}
        const entityType = apiEntityType(form.value.source);
        const entityApiUrl = getStationApiUrl(`/${entityType}/${form.value.entity_id}`).value;

        // Fetch current entity data
        const {data: entityData} = await axios.get(entityApiUrl);

        const newScheduleItem = buildSchedulePayload(
            scheduleRow.value,
            editingScheduleId.value ?? undefined
        );
        if (form.value.source === 'clock_wheel') {
            newScheduleItem.loop_once = false;
            newScheduleItem.strict_start = false;
            newScheduleItem.clock_wheel_mode = scheduleRow.value.clock_wheel_mode ?? 'flexible';
        }

        const existingScheduleItems = (entityData.schedule_items as unknown[]) ?? [];

        let updatedScheduleItems: unknown[];
        if (editingScheduleId.value !== null) {
            let replaced = false;
            updatedScheduleItems = existingScheduleItems.map((row: any) => {
                if (row?.id === editingScheduleId.value) {
                    replaced = true;
                    return newScheduleItem;
                }
                return row;
            });

            if (!replaced) {
                updatedScheduleItems = [...updatedScheduleItems, newScheduleItem];
            }
        } else {
            updatedScheduleItems = [...existingScheduleItems, newScheduleItem];
        }

        // Only send schedule_items — a full entity PUT includes relation arrays (e.g. podcasts)
        // that the serializer cannot denormalize back into Doctrine collections.
        await axios.put(entityApiUrl, {
            schedule_items: updatedScheduleItems,
        });

        notifySuccess(editingScheduleId.value !== null ? $gettext('Event updated.') : $gettext('Event created.'));
        ($modal.value as any)?.hide();
        emit('relist');
    } catch (e: unknown) {
        const err = e as {response?: {data?: {message?: string}}};
        error.value = err?.response?.data?.message ?? $gettext('An error occurred.');
    } finally {
        loading.value = false;
    }
};

const close = () => {
    ($modal.value as any)?.hide();
};

const doDelete = async () => {
    if (!form.value.entity_id || editingScheduleId.value === null) {
        return;
    }

    // Capture these before closing the modal -- @hidden triggers clearForm(),
    // which resets the reactive form/editingScheduleId back to blank.
    const entityId = form.value.entity_id;
    const source = form.value.source;
    const scheduleId = editingScheduleId.value;

    // Close first, matching the pattern used by MediaCategories/EditModal.vue --
    // opening the confirm dialog while this modal is still open leaves it
    // stacked behind the modal (same z-index, this modal painted later) until
    // this modal is dismissed.
    close();

    const {value} = await confirmDelete({
        title: $gettext('Delete this scheduled event?'),
    });

    if (!value) {
        return;
    }

    try {
        const entityType = apiEntityType(source);
        const entityApiUrl = getStationApiUrl(`/${entityType}/${entityId}`).value;

        const {data: entityData} = await axios.get(entityApiUrl);
        const existingScheduleItems = (entityData.schedule_items as unknown[]) ?? [];

        const updatedScheduleItems = existingScheduleItems.filter(
            (row: any) => row?.id !== scheduleId
        );

        await axios.put(entityApiUrl, {
            schedule_items: updatedScheduleItems,
        });

        notifySuccess($gettext('Event deleted.'));
        emit('relist');
    } catch {
        // Errors are already surfaced globally via the axios response interceptor.
    }
};

const openForDrop = (
    entityId: number,
    start: Date,
    end?: Date,
    source: 'playlist' | 'smart_block' | 'clock_wheel' = 'playlist',
) => {
    clearForm();
    nsAddNewRow.value = true;
    form.value.source = source;
    form.value.entity_id = entityId;
    applyCalendarTimesToRow(start, end);
    syncDurationFromTimes();
    ($modal.value as any)?.show();
};

/**
 * Auto-save a dropped item directly to the API without opening the modal.
 * Creates a one-time event at the dropped slot (same defaults openForDrop uses)
 * and immediately PUTs it. Returns an undo function the caller can wire to a toast.
 */
const autoSaveFromDrop = async (
    entityId: number,
    start: Date,
    durationMinutes: number,
    recurrenceType: string | null,
    days: number[],
    source: 'playlist' | 'smart_block' | 'clock_wheel' = 'playlist',
): Promise<{success: boolean}> => {
    try {
        const startInTz = DateTime.fromJSDate(start, {zone: stationTimezone.value});
        const endInTz = startInTz.plus({minutes: durationMinutes});

        const row = createScheduleItemDefaults();
        row.start_date = startInTz.toFormat('yyyy-MM-dd');
        row.end_date = recurrenceType ? '' : startInTz.toFormat('yyyy-MM-dd');
        row.start_time = Number(startInTz.toFormat('HHmm'));
        row.end_time = Number(endInTz.toFormat('HHmm'));
        row.recurrence_type = recurrenceType ?? null;
        row.days = days;

        const entityType = apiEntityType(source);
        const entityApiUrl = getStationApiUrl(`/${entityType}/${entityId}`).value;

        const {data: entityData} = await axios.get(entityApiUrl);
        const existing = (entityData.schedule_items as unknown[]) ?? [];

        const newItem = buildSchedulePayload(row);
        if (source === 'clock_wheel') {
            (newItem as any).loop_once = false;
            (newItem as any).clock_wheel_mode = 'flexible';
        }

        await axios.put(entityApiUrl, {
            schedule_items: [...existing, newItem],
        });

        emit('relist');
        return {success: true};
    } catch {
        return {success: false};
    }
};

/**
 * Auto-save a drag-to-move/resize operation for an existing schedule item.
 * Fetches the entity, updates start/end times in the matching item, and PUTs.
 * For one-time events also updates start_date/end_date. Recurring events only
 * get new start_time/end_time — the recurrence boundary dates stay untouched.
 * Returns {success} so the caller can revert the calendar event on failure.
 */
const autoSaveMove = async (
    event: EventImpl,
    newStart: Date,
    newEnd: Date | null,
): Promise<{success: boolean}> => {
    const editUrl = event.extendedProps.edit_url as string | undefined;
    const scheduleIdRaw = event.extendedProps.schedule_id as number | string | undefined;
    const scheduleId = scheduleIdRaw !== undefined ? Number(scheduleIdRaw) : NaN;

    if (!editUrl || !Number.isFinite(scheduleId)) return {success: false};

    try {
        const baseUrl = editUrl.replace(/\/schedule\/\d+$/, '');
        const {data: entityData} = await axios.get(baseUrl);
        const existingItems = (entityData.schedule_items as Record<string, unknown>[] | undefined) ?? [];

        const targetItem = existingItems.find((row: any) => Number(row.id) === scheduleId) as Record<string, unknown> | undefined;
        if (!targetItem) return {success: false};

        const startInTz = DateTime.fromJSDate(newStart, {zone: stationTimezone.value});
        const startTime = Number(startInTz.toFormat('HHmm'));
        const startDate = startInTz.toFormat('yyyy-MM-dd');

        let endTime: number;
        let endDate: string | undefined;

        if (newEnd) {
            const endInTz = DateTime.fromJSDate(newEnd, {zone: stationTimezone.value});
            endTime = Number(endInTz.toFormat('HHmm'));
            endDate = endInTz.toFormat('yyyy-MM-dd');
        } else {
            endTime = Number(targetItem.end_time ?? 0);
        }

        const isRecurringItem = targetItem.recurrence_type != null && targetItem.recurrence_type !== '';

        const updatedItem = {
            ...targetItem,
            start_time: startTime,
            end_time: endTime,
            start_date: startDate,
            // Don't overwrite the recurrence boundary end_date for recurring events
            ...(!isRecurringItem && endDate !== undefined ? {end_date: endDate} : {}),
        };

        const updatedItems = existingItems.map((row: any) =>
            Number(row.id) === scheduleId ? updatedItem : row
        );

        await axios.put(baseUrl, {schedule_items: updatedItems});
        emit('relist');
        return {success: true};
    } catch {
        return {success: false};
    }
};

/** Check whether a calendar event's schedule item is recurring, without opening the modal. */
const checkIsRecurring = async (event: EventImpl): Promise<{isRecurring: boolean}> => {
    const editUrl = event.extendedProps.edit_url as string | undefined;
    const scheduleIdRaw = event.extendedProps.schedule_id as number | string | undefined;
    const scheduleId = scheduleIdRaw !== undefined ? Number(scheduleIdRaw) : NaN;

    if (!editUrl || !Number.isFinite(scheduleId)) return {isRecurring: false};

    try {
        const baseUrl = editUrl.replace(/\/schedule\/\d+$/, '');
        const {data: entityData} = await axios.get(baseUrl);
        const items = (entityData.schedule_items as Record<string, unknown>[] | undefined) ?? [];
        const target = items.find((r: any) => Number(r?.id) === scheduleId) as any;
        return {isRecurring: !!(target?.recurrence_type)};
    } catch {
        return {isRecurring: false};
    }
};

/** Called when user clicks an empty calendar slot — opens the modal with time pre-filled. */
const openAtTime = (date: Date) => {
    clearForm();
    nsAddNewRow.value = true;
    if (currentEntityOptions.value.length > 0) {
        form.value.entity_id = currentEntityOptions.value[0].id;
    }
    applyCalendarTimesToRow(date);
    syncDurationFromTimes();
    ($modal.value as any)?.show();
};

defineExpose({open, openForEdit, openScopedForCreate, openScopedForEdit, openForDrop, autoSaveFromDrop, autoSaveMove, checkIsRecurring, openAtTime});
</script>

<style scoped>
.step-heading {
    display: flex;
    align-items: center;
    gap: .85rem;
    padding: .9rem 1rem;
    border-radius: .7rem;
}

.step-heading strong,
.step-heading small {
    display: block;
}

.step-heading strong {
    font-size: 1.08rem;
}

.step-heading small {
    margin-top: .15rem;
    color: var(--bs-secondary-color);
    font-size: .84rem;
}

.step-heading-source {
    border: 1px solid rgba(13, 110, 253, .2);
    background: rgba(13, 110, 253, .12);
    color: #1688f8;
}

.step-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.7rem;
    height: 2.7rem;
    flex: 0 0 2.7rem;
    border-radius: .55rem;
    color: #fff;
    font-size: 1.55rem;
}

.step-icon-source {
    background: #0d6efd;
}

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
    transition: border-color .15s ease, background-color .15s ease;
}

.request-option input {
    margin-top: .2rem;
}

.request-option strong,
.request-option small {
    display: block;
}

.request-option strong {
    font-size: .86rem;
}

.request-option small {
    margin-top: .2rem;
    color: var(--bs-secondary-color);
    line-height: 1.4;
}

.request-option-warning {
    border-color: rgba(255, 193, 7, .45);
    background: rgba(255, 193, 7, .08);
}

.request-option-warning strong {
    color: #ffc107;
}

.request-option-warning:has(input:checked) {
    border-color: #ffc107;
    background: rgba(255, 193, 7, .16);
}
.schedule-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.05rem 1.2rem;
    border-radius: .75rem;
    background: linear-gradient(90deg, #0d6efd, #1688f8);
    color: #fff;
    box-shadow: 0 .2rem .65rem rgba(13, 110, 253, .18);
}

.schedule-hero p {
    color: rgba(255, 255, 255, .9);
    font-size: .92rem;
    line-height: 1.4;
}

.schedule-overview {
    padding: 1rem 1.1rem;
    border: 1px solid;
    border-radius: .7rem;
}

.schedule-overview strong,
.schedule-overview span,
.schedule-summary strong,
.schedule-summary small {
    display: block;
}

.schedule-overview strong {
    font-size: 1rem;
}

.schedule-overview span {
    margin-top: .2rem;
    font-size: .88rem;
    line-height: 1.45;
}

.schedule-overview.is-scheduled {
    border-color: rgba(13, 110, 253, .55);
    background: rgba(13, 110, 253, .1);
}

.schedule-overview.is-unscheduled {
    border-color: rgba(245, 158, 11, .55);
    background: rgba(245, 158, 11, .1);
}

.add-schedule-button {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    font-weight: 600;
}

.schedule-summary {
    display: flex;
    align-items: flex-start;
    gap: .8rem;
    padding: 1rem 1.1rem;
    border: 1px solid rgba(25, 135, 84, .48);
    border-radius: .7rem;
    background: rgba(25, 135, 84, .10);
}

.summary-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.6rem;
    height: 2.6rem;
    flex: 0 0 2.6rem;
    border-radius: 50%;
    background: #198754;
    color: #fff;
    font-size: 1.55rem;
}

.schedule-summary strong {
    color: #23a866;
    font-size: 1rem;
}

.schedule-summary small {
    margin-top: .2rem;
    color: var(--bs-secondary-color);
    font-size: .84rem;
    line-height: 1.45;
}
</style>

<style>
/* Widen the Create/Edit Event modal (popup use, e.g. Web Streams page) while the
   New Schedule editor is shown. Sized via :has() because toggling the modal's
   own class would drop Bootstrap's "show" class. */
.modal-dialog:has(.calendar-new-schedule) {
    width: calc(100vw - 2rem);
    max-width: 960px;
}

@media (max-width: 767.98px) {
    .modal-dialog:has(.calendar-new-schedule) {
        width: calc(100vw - .75rem);
    }
}
</style>
