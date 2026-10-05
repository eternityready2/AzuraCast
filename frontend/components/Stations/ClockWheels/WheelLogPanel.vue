<template>
    <div>
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="small text-muted me-auto">
                {{ mode === 'upcoming'
                    ? $gettext('What the system has actually scheduled for this wheel, from the Linear Log.')
                    : $gettext('What actually aired the last times this wheel was on, from the Linear Log as-run.') }}
            </span>
            <button
                type="button"
                class="btn btn-sm btn-outline-primary"
                :disabled="isBusy"
                @click="reload"
            >
                {{ $gettext('Refresh') }}
            </button>
        </div>

        <loading :loading="isBusy">
            <template v-if="mode === 'upcoming'">
                <p
                    v-if="!log?.upcoming"
                    class="text-muted mb-0"
                >
                    {{ $gettext('This wheel is not scheduled to air in the next two weeks.') }}
                </p>
                <template v-else>
                    <h3 class="h6">
                        {{ $gettext('Next airing') }}: {{ formatTimestampAsDateTime(log.upcoming.start) }}
                        – {{ formatTimestampAsTime(log.upcoming.end) }}
                    </h3>
                    <div
                        v-if="!log.upcoming.in_log"
                        class="alert alert-info py-2 small"
                    >
                        {{ $gettext('The Linear Log is built 24 hours ahead, so the songs for this airing are not chosen yet. They will appear here from') }}
                        {{ formatTimestampAsDateTime(log.upcoming.start - 86400) }}.
                    </div>
                    <log-table
                        v-else
                        :lines="log.upcoming.lines"
                    />
                </template>
            </template>

            <template v-else>
                <p
                    v-if="!log?.aired?.length"
                    class="text-muted mb-0"
                >
                    {{ $gettext('This wheel has not aired in the last two weeks.') }}
                </p>
                <div
                    v-for="airing in log?.aired ?? []"
                    :key="airing.start"
                    class="mb-4"
                >
                    <h3 class="h6">
                        {{ formatTimestampAsDateTime(airing.start) }} – {{ formatTimestampAsTime(airing.end) }}
                    </h3>
                    <log-table :lines="airing.lines" />
                </div>
            </template>
        </loading>
    </div>
</template>

<script setup lang="ts">
import {defineComponent, h, onMounted, ref, watch, type PropType} from 'vue';
import Loading from '~/components/Common/Loading.vue';
import {useAxios} from '~/vendor/axios.ts';
import {useTranslate} from '~/vendor/gettext';
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';

export interface WheelLogLine {
    id: number;
    at: number;
    duration: number;
    status: string;
    title: string | null;
    artist: string | null;
    type: string | null;
    playlist: string | null;
    note: string | null;
    from_wheel: boolean;
}

interface WheelLogWindow {
    start: number;
    end: number;
    lines: WheelLogLine[];
}

export interface WheelLogResponse {
    wheel_id: number;
    log_until: number | null;
    upcoming: (WheelLogWindow & {in_log: boolean}) | null;
    aired: WheelLogWindow[];
}

/**
 * The real Linear Log lines for a wheel: its next airing ("upcoming") or its
 * recent airings as-run ("aired"). Used inline in the wheel editor and in the
 * Preview popup.
 */
const props = defineProps<{
    url: string;
    mode: 'upcoming' | 'aired';
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {formatTimestampAsDateTime, formatTimestampAsTime} = useStationDateTimeFormatter();

const isBusy = ref(false);
const log = ref<WheelLogResponse | null>(null);

const reload = async () => {
    isBusy.value = true;
    try {
        const {data} = await axios.get<WheelLogResponse>(props.url);
        log.value = data;
    } finally {
        isBusy.value = false;
    }
};

onMounted(reload);
watch(() => props.url, reload);

const formatLength = (seconds: number): string => {
    const s = Math.max(0, Math.round(seconds));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
};

const statusLabel = (status: string): string => ({
    planned: $gettext('Planned'),
    queued: $gettext('Queued'),
    aired: $gettext('Aired'),
    swapped: $gettext('Swapped'),
    replaced: $gettext('Replaced'),
} as Record<string, string>)[status] ?? status;

const LogTable = defineComponent({
    props: {
        lines: {type: Array as PropType<WheelLogLine[]>, required: true},
    },
    setup(tableProps) {
        return () => h('div', {class: 'table-responsive'}, [
            h('table', {class: 'table table-sm table-striped align-middle mb-0'}, [
                h('thead', [h('tr', [
                    h('th', $gettext('Time')),
                    h('th', $gettext('Title')),
                    h('th', $gettext('Type')),
                    h('th', $gettext('Length')),
                    h('th', $gettext('Status')),
                ])]),
                h('tbody', tableProps.lines.length === 0
                    ? [h('tr', [h('td', {colspan: 5, class: 'text-muted text-center'}, $gettext('No lines in the log for this hour.'))])]
                    : tableProps.lines.map((line) => h('tr', {key: line.id}, [
                        h('td', {class: 'text-nowrap'}, formatTimestampAsTime(line.at)),
                        h('td', [
                            h('strong', line.title ?? '—'),
                            line.artist ? h('span', {class: 'text-muted'}, ' — ' + line.artist) : null,
                            line.from_wheel
                                ? h('span', {class: 'badge text-bg-primary ms-2'}, $gettext('This wheel'))
                                : null,
                            line.note ? h('div', {class: 'small text-muted'}, line.note) : null,
                        ]),
                        h('td', {class: 'text-nowrap'}, line.type ?? '—'),
                        h('td', {class: 'text-nowrap'}, formatLength(line.duration)),
                        h('td', {class: 'text-nowrap'}, statusLabel(line.status)),
                    ]))
                ),
            ]),
        ]);
    },
});

defineExpose({reload});
</script>
