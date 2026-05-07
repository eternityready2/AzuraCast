<template>
    <tab
        id="schedule_view"
        :label="$gettext('Schedule View')"
    >
        <div
            class="card-body-flush"
            style="position: relative;"
        >
            <schedule
                ref="$schedule"
                :options="{
                    headerToolbar: {
                        left: 'prev,next',
                        center: 'title',
                        right: 'timeGridWeek,timeGridDay'
                    },
                    timeZone: timezone,
                    events: scheduleUrl,
                    eventClick: onClick
                }"
            />
            <div
                v-if="showCreateButton"
                style="position: absolute; bottom: 1.25rem; right: 1.25rem; z-index: 10;"
            >
                <button
                    type="button"
                    class="btn btn-primary btn-lg rounded-pill shadow"
                    @click="emit('create')"
                >
                    <icon-ic-add />
                    {{ $gettext('Create Event') }}
                </button>
            </div>
        </div>
    </tab>
</template>

<script setup lang="ts">
import Tab from "~/components/Common/Tab.vue";
import Schedule from "~/components/Common/ScheduleView.vue";
import IconIcAdd from "~icons/ic/baseline-add";
import {Calendar, EventClickArg} from "@fullcalendar/core";
import {EventImpl} from "@fullcalendar/core/internal";
import {useTemplateRef} from "vue";
import {useStationData} from "~/functions/useStationQuery.ts";
import {toRefs} from "@vueuse/core";

withDefaults(defineProps<{
    scheduleUrl: string,
    showCreateButton?: boolean,
}>(), {
    showCreateButton: false,
});

const emit = defineEmits<{
    click: [event: EventImpl],
    create: [],
}>();

const stationData = useStationData();
const {timezone} = toRefs(stationData);

const onClick = (arg: EventClickArg) => {
    emit('click', arg.event);
}

const $schedule = useTemplateRef('$schedule');

const getCalendarApi = (): Calendar | undefined => {
    return $schedule.value?.getCalendarApi();
};

const refresh = () => getCalendarApi()?.refetchEvents();

defineExpose({
    getCalendarApi,
    refresh
});
</script>
