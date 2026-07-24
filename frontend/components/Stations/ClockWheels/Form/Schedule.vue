<template>
    <tab :label="$gettext('Schedule')">
        <form-markup
            v-if="scheduleItems.length === 0"
            id="no_scheduled_entries"
        >
            <template #label>
                {{ $gettext('Not Scheduled') }}
            </template>
            <p>
                {{
                    $gettext('This clock wheel currently has no scheduled times. To add a new scheduled time, click the button below.')
                }}
            </p>
        </form-markup>

        <clock-wheels-form-schedule-row
            v-for="(row, index) in scheduleItems"
            :key="index"
            v-model:row="scheduleItems[index]"
            :index="index"
            @remove="remove(index)"
        />

        <div class="buttons">
            <button
                type="button"
                class="btn btn-sm btn-primary"
                @click="add"
            >
                <icon-ic-add/>
                <span>
                    {{ $gettext('Add Schedule Item') }}
                </span>
            </button>
        </div>
    </tab>
</template>

<script setup lang="ts">
import ClockWheelsFormScheduleRow from "~/components/Stations/ClockWheels/Form/ScheduleRow.vue";
import FormMarkup from "~/components/Form/FormMarkup.vue";
import Tab from "~/components/Common/Tab.vue";
import IconIcAdd from "~icons/ic/baseline-add";
import type {ClockWheelScheduleRow} from "~/components/Stations/ClockWheels/Form/ScheduleRow.vue";

const scheduleItems = defineModel<ClockWheelScheduleRow[]>('scheduleItems', {
    default: () => []
});

const add = () => {
    scheduleItems.value.push({
        start_time: null as unknown as number,
        end_time: null as unknown as number,
        start_date: null as unknown as string,
        end_date: null as unknown as string,
        days: [],
        loop_once: false,
        clock_wheel_mode: 'flexible',
        recurrence_type: 'weekly',
        recurrence_interval: 1,
        recurrence_monthly_pattern: null,
        recurrence_monthly_day: null,
        recurrence_monthly_week: null,
        recurrence_monthly_day_of_week: null,
        recurrence_end_type: 'never',
        recurrence_end_after: null,
        recurrence_end_date: null,
    });
};

const remove = (index: number) => {
    scheduleItems.value.splice(index, 1);
};
</script>
