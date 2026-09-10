<template>
    <tab :label="$gettext('Schedule & Playback')">
        <div
            class="schedule-overview mb-4"
            :class="scheduleItems.length > 0 ? 'is-scheduled' : 'is-unscheduled'"
        >
            <strong>
                {{ scheduleItems.length > 0 ? $gettext('Scheduled Playlist') : $gettext('No Schedule Set') }}
            </strong>
            <span v-if="scheduleItems.length > 0">
                {{ $gettext('This playlist is restricted to the scheduled windows below. Set both when it plays and how it should take over from normal AutoDJ rotation on this page.') }}
            </span>
            <span v-else>
                {{ $gettext('Important: an enabled playlist with no schedule can be selected all day. Add a scheduled time below if this content should only air at specific times.') }}
            </span>
        </div>

        <div class="section-heading mb-3">
            <span class="step-number">1</span>
            <span class="section-copy">
                <strong>{{ $gettext('When should it play?') }}</strong>
                <small>{{ $gettext('Add one or more time windows, then choose the timing and repeat behavior inside each block.') }}</small>
            </span>
        </div>

        <form-markup
            v-if="scheduleItems.length === 0"
            id="no_scheduled_entries"
        >
            <template #label>
                {{ $gettext('Not Scheduled') }}
            </template>
            <p class="mb-0">
                {{ $gettext('This playlist currently has no scheduled times and may play at any time while enabled.') }}
            </p>
        </form-markup>

        <playlists-form-schedule-row
            v-for="(row, index) in scheduleItems"
            :key="index"
            v-model:row="scheduleItems[index]"
            :index="index"
            @remove="remove(index)"
        />

        <div class="buttons mb-4">
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

        <form-playout-rules
            v-if="!form.is_smart_block"
            :has-schedule="scheduleItems.length > 0"
        />
    </tab>
</template>

<script setup lang="ts">
import PlaylistsFormScheduleRow from "~/components/Stations/Playlists/Form/ScheduleRow.vue";
import FormPlayoutRules from "~/components/Stations/Playlists/Form/PlayoutRules.vue";
import FormMarkup from "~/components/Form/FormMarkup.vue";
import Tab from "~/components/Common/Tab.vue";
import IconIcAdd from "~icons/ic/baseline-add";
import {storeToRefs} from "pinia";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form.ts";

const {form} = storeToRefs(useStationsPlaylistsForm());

const scheduleItems = defineModel<Array<any>>('scheduleItems', {
    default: () => []
});

const add = () => {
    scheduleItems.value.push({
        start_time: null,
        end_time: null,
        start_date: null,
        end_date: null,
        days: [],
        loop_once: false,
        prevent_requests: false,
        strict_start: false,
        recurrence_type: 'weekly',
        recurrence_interval: 1,
        recurrence_monthly_pattern: null,
        recurrence_monthly_day: null,
        recurrence_monthly_week: null,
        recurrence_monthly_day_of_week: null,
        recurrence_end_type: 'never',
        recurrence_end_after: null,
        recurrence_end_date: null
    });
};

const remove = (index: number) => {
    scheduleItems.value.splice(index, 1);
};
</script>

<style scoped>
.schedule-overview {
    padding: .9rem 1rem;
    border: 1px solid;
    border-radius: .7rem;
}

.schedule-overview strong,
.schedule-overview span {
    display: block;
}

.schedule-overview strong {
    font-size: .92rem;
}

.schedule-overview span {
    margin-top: .25rem;
    font-size: .8rem;
    line-height: 1.45;
}

.schedule-overview.is-scheduled {
    border-color: #3477b7;
    background: rgba(38, 136, 255, .08);
}

.schedule-overview.is-unscheduled {
    border-color: #b7791f;
    background: rgba(245, 158, 11, .08);
}

.section-heading {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
}

.step-number {
    display: inline-flex;
    width: 1.8rem;
    height: 1.8rem;
    align-items: center;
    justify-content: center;
    flex: 0 0 1.8rem;
    border-radius: 50%;
    background: #2688ff;
    color: #fff;
    font-weight: 700;
    font-size: .82rem;
}

.section-copy strong,
.section-copy small {
    display: block;
}

.section-copy strong {
    font-size: 1rem;
}

.section-copy small {
    margin-top: .15rem;
    color: var(--bs-secondary-color);
    line-height: 1.45;
}
</style>
