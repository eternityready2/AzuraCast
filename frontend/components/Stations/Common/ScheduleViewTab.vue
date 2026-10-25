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
                :options="calendarOptions"
            >
                <template #eventContent="arg">
                    <div class="fc-event-main-frame schedule-event-content px-1">
                        <div class="d-flex align-items-center justify-content-between gap-1">
                            <span class="d-inline-flex flex-shrink-0">
                                <icon-bi-info-circle-fill
                                    v-if="getEventProps(arg.event).plays_via_group_schedule"
                                    class="flex-shrink-0 me-2"
                                    :class="IconSize.Small"
                                />

                                <span
                                    v-if="getEventProps(arg.event).type === 'streamer'"
                                    class="schedule-streamer-art d-inline-flex align-items-center justify-content-center overflow-hidden rounded-circle"
                                >
                                    <img
                                        v-if="getEventProps(arg.event).has_custom_art"
                                        :src="getEventProps(arg.event).art ?? undefined"
                                        class="w-100 h-100 object-fit-cover"
                                        alt="Streamer Artwork"
                                    >
                                    <icon-bi-mic-fill
                                        v-else
                                        :class="IconSize.Small"
                                    />
                                </span>
                                <playlist-source-icon
                                    v-else-if="getEventProps(arg.event).source"
                                    :source="getEventProps(arg.event).source!"
                                    :size="IconSize.Small"
                                />
                            </span>

                            <span
                                v-if="arg.timeText"
                                class="fc-event-time text-truncate"
                            >
                                {{ arg.timeText }}
                            </span>
                        </div>

                        <div class="fc-event-title fc-sticky text-center text-truncate">
                            {{ arg.event.title }}
                        </div>
                    </div>
                </template>
            </schedule>

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

        <schedule-event-overlay
            :visible="overlayVisible"
            :reference-element="overlayReferenceElement"
            :details="overlayDetails"
            @mouseenter="clearHideTimer()"
            @mouseleave="scheduleHide()"
        />
    </tab>
</template>

<script setup lang="ts">
import {Calendar, EventApi, EventClickArg, EventHoveringArg} from "@fullcalendar/core";
import {EventImpl} from "@fullcalendar/core/internal";
import {toRefs, useTimeoutFn} from "@vueuse/core";
import {computed, ref, useTemplateRef} from "vue";
import Schedule from "~/components/Common/ScheduleView.vue";
import Tab from "~/components/Common/Tab.vue";
import PlaylistSourceIcon from "~/components/Stations/Common/PlaylistSourceIcon.vue";
import ScheduleEventOverlay from "~/components/Stations/Common/ScheduleEventOverlay.vue";
import {PlaylistSources} from "~/entities/ApiInterfaces.ts";
import type {ScheduleEventDetails} from "~/entities/StationSchedule.ts";
import {IconSize} from "~/functions/icons.ts";
import {useStationData} from "~/functions/useStationQuery.ts";
import IconBiInfoCircleFill from "~icons/bi/info-circle-fill";
import IconBiMicFill from "~icons/bi/mic-fill";
import IconIcAdd from "~icons/ic/baseline-add";

interface ScheduleEventCellProps {
    type?: ScheduleEventDetails["type"];
    source?: PlaylistSources;
    has_custom_art?: boolean;
    art?: string | null;
    plays_via_group_schedule?: boolean;
}

const props = withDefaults(defineProps<{
    scheduleUrl: string | string[],
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

const getEventProps = (event: EventApi): ScheduleEventCellProps =>
    event.extendedProps;

const onClick = (arg: EventClickArg) => {
    emit('click', arg.event);
};

const overlayVisible = ref<boolean>(false);
const overlayReferenceElement = ref<HTMLElement | null>(null);
const overlayDetails = ref<ScheduleEventDetails | null>(null);

const {start: scheduleHide, stop: clearHideTimer} = useTimeoutFn(
    () => {
        overlayVisible.value = false;
        overlayReferenceElement.value = null;
        overlayDetails.value = null;
    },
    150,
    {immediate: false},
);

const onMouseEnter = (arg: EventHoveringArg) => {
    const extendedProps = arg.event.extendedProps;

    if (!extendedProps.type) {
        return;
    }

    clearHideTimer();
    overlayReferenceElement.value = arg.el;
    overlayDetails.value = {
        ...extendedProps,
        name: arg.event.title,
    } as ScheduleEventDetails;
    overlayVisible.value = true;
};

const calendarOptions = computed(() => {
    const urls = Array.isArray(props.scheduleUrl) ? props.scheduleUrl : [props.scheduleUrl];
    return {
        headerToolbar: {
            left: 'prev,next',
            center: 'title',
            right: 'timeGridWeek,timeGridDay'
        },
        timeZone: timezone.value,
        eventSources: urls,
        eventClick: onClick,
        eventMouseEnter: onMouseEnter,
        eventMouseLeave: () => scheduleHide(),
    };
});

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

<style lang="scss" scoped>
@import "~/scss/_variables.scss";

.schedule-event-content {
    cursor: pointer;
}

.schedule-streamer-art {
    width: $icon-size-sm;
    height: $icon-size-sm;
}
</style>
