<template>
    <div class="clock-wheel-live">
        <div
            v-if="conflictMessage.active"
            class="alert alert-warning d-flex align-items-start gap-2 mb-3"
            role="alert"
        >
            <icon-ic-warning class="flex-shrink-0 mt-1" />
            <div>
                <strong>{{ $gettext('Schedule conflict') }}</strong>
                <div class="small mb-0">
                    {{
                        $gettext(
                            'A playlist or streamer schedule entry is active now and may override the clock wheel.'
                        )
                    }}
                    <span v-if="conflictMessage.detail"> ({{ conflictMessage.detail }})</span>
                </div>
            </div>
        </div>

        <div
            v-if="np.live?.is_live"
            class="alert alert-info mb-3"
            role="status"
        >
            <icon-ic-mic class="me-1" />
            {{ $gettext('Live streamer on air') }}: {{ np.live.streamer_name }}
        </div>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div class="small text-muted">
                <span v-if="lastUpdatedLabel">
                    {{ $gettext('Updated') }}: {{ lastUpdatedLabel }}
                </span>
            </div>
            <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                :disabled="isLoading"
                @click="refresh"
            >
                <icon-ic-refresh class="me-1" />
                {{ $gettext('Refresh') }}
            </button>
        </div>

        <loading :loading="isLoading" lazy>
            <template v-if="activeWheel">
                <div
                    class="clock-wheel-live__summary card mb-4"
                    :style="summaryCardStyle"
                >
                    <div class="card-body py-3">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
                            <div>
                                <h3 class="h5 mb-1 d-flex align-items-center gap-2">
                                    <span
                                        class="d-inline-block rounded flex-shrink-0"
                                        style="width: 0.85rem; height: 0.85rem;"
                                        :style="{ backgroundColor: activeWheel.color }"
                                    />
                                    {{ activeWheel.name }}
                                </h3>
                                <p
                                    v-if="segmentSummary.hourWindowLabel"
                                    class="small text-muted mb-0"
                                >
                                    {{ segmentSummary.hourWindowLabel }}
                                </p>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span
                                    class="badge"
                                    :class="hourHealthBadgeClass"
                                >
                                    {{ hourHealthLabel }}
                                </span>
                                <button
                                    v-if="analyticsUrl"
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    @click="openAnalytics"
                                >
                                    {{ $gettext('Analytics') }}
                                </button>
                            </div>
                        </div>

                        <div class="row g-3 small">
                            <div class="col-md-4">
                                <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.65rem;">
                                    {{ $gettext('Current segment') }}
                                </div>
                                <div class="fw-semibold">
                                    {{ segmentSummary.currentLabel ?? $gettext('Between anchors') }}
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.65rem;">
                                    {{ $gettext('Next anchor') }}
                                </div>
                                <div class="fw-semibold">
                                    {{ segmentSummary.nextLabel ?? '—' }}
                                    <span
                                        v-if="segmentSummary.secondsUntilNext !== null"
                                        class="text-muted fw-normal"
                                    >
                                        ({{ formatCountdown(segmentSummary.secondsUntilNext) }})
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.65rem;">
                                    {{ $gettext('Segment progress') }}
                                </div>
                                <div
                                    class="progress mt-1"
                                    style="height: 0.5rem;"
                                >
                                    <div
                                        class="progress-bar"
                                        role="progressbar"
                                        :style="{ width: segmentSummary.segmentProgressPercent + '%' }"
                                        :aria-valuenow="segmentSummary.segmentProgressPercent"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <div class="row g-4 mb-4">
                <div class="col-lg-5">
                    <div class="clock-wheel-live__clock-wrap d-flex flex-column align-items-center">
                        <div
                            class="clock-wheel-live__face"
                            role="img"
                            :aria-label="clockAriaLabel"
                            :style="faceBorderStyle"
                        >
                            <svg
                                class="clock-wheel-live__arcs"
                                viewBox="0 0 100 100"
                                aria-hidden="true"
                            >
                                <circle
                                    cx="50"
                                    cy="50"
                                    r="46"
                                    class="clock-wheel-live__arc clock-wheel-live__arc--elapsed"
                                    :stroke-dasharray="elapsedArcDash"
                                />
                            </svg>

                            <span
                                v-for="tick in hourTicks"
                                :key="'tick-' + tick.minutes"
                                class="clock-wheel-live__tick"
                                :style="tick.style"
                            />

                            <span
                                v-for="(item, idx) in slotsWithTracks"
                                :key="'proj-' + idx"
                                class="clock-wheel-live__ring-dot clock-wheel-live__ring-dot--projected"
                                :style="ringDotStyle(item.slot.position_seconds, 48)"
                                :title="projectedDotTitle(item)"
                            />

                            <span
                                v-for="(item, idx) in slotsWithTracks"
                                :key="'slot-' + idx"
                                class="clock-wheel-live__marker"
                                :class="{
                                    'clock-wheel-live__marker--past': item.status === 'played',
                                    'clock-wheel-live__marker--current': item.status === 'current',
                                }"
                                :style="ringDotStyle(item.slot.position_seconds, 42)"
                                :title="anchorMarkerTitle(item)"
                                role="button"
                                tabindex="0"
                                @click="focusSlotRow(item.index)"
                                @keydown.enter="focusSlotRow(item.index)"
                            />

                            <div
                                class="clock-wheel-live__hand"
                                :style="{ transform: `rotate(${handDegrees}deg)` }"
                            />
                            <div class="clock-wheel-live__hub" />
                        </div>
                        <div class="text-muted small mt-2 text-center">
                            {{ stationTimeLabel }}
                            <span class="mx-1">·</span>
                            {{ timezone }}
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <h3 class="h5 mb-3">
                        {{ $gettext('Now Playing') }}
                    </h3>
                    <div
                        v-if="np.now_playing?.song"
                        class="mb-2"
                    >
                        <div class="fw-semibold">
                            {{ np.now_playing.song.title || np.now_playing.song.text }}
                        </div>
                        <div
                            v-if="np.now_playing.song.artist"
                            class="text-muted"
                        >
                            {{ np.now_playing.song.artist }}
                        </div>
                        <div
                            v-if="np.now_playing.clock_wheel"
                            class="small text-muted mt-1"
                        >
                            {{ $gettext('Clock Wheel') }}: {{ np.now_playing.clock_wheel }}
                        </div>
                        <div
                            v-else-if="np.now_playing.playlist"
                            class="small text-muted mt-1"
                        >
                            {{ $gettext('Playlist') }}: {{ np.now_playing.playlist }}
                        </div>
                    </div>
                    <div
                        v-else
                        class="text-muted"
                    >
                        {{ $gettext('No track on air.') }}
                    </div>

                    <template v-if="activeWheel && slotsWithTracks.length > 0">
                        <h3 class="h6 text-muted mt-4 mb-2">
                            {{ $gettext('Hour at a glance') }}
                        </h3>
                        <div
                            class="clock-wheel-live__timeline mb-3"
                            role="img"
                            :aria-label="$gettext('Hour timeline with anchor positions and current time')"
                        >
                            <div
                                class="clock-wheel-live__timeline-track"
                                :style="timelineTrackStyle"
                            >
                                <span class="clock-wheel-live__timeline-label clock-wheel-live__timeline-label--start">
                                    0:00
                                </span>
                                <span class="clock-wheel-live__timeline-label clock-wheel-live__timeline-label--end">
                                    59:59
                                </span>

                                <div
                                    v-if="currentSegmentStyle"
                                    class="clock-wheel-live__timeline-segment"
                                    :style="currentSegmentStyle"
                                />

                                <div
                                    class="clock-wheel-live__timeline-now"
                                    :style="{ left: nowPercent + '%' }"
                                    :title="stationTimeLabel"
                                />

                                <button
                                    v-for="(item, idx) in slotsWithTracks"
                                    :key="'tl-' + idx"
                                    type="button"
                                    class="clock-wheel-live__timeline-marker"
                                    :class="{
                                        'clock-wheel-live__timeline-marker--past': item.status === 'played',
                                        'clock-wheel-live__timeline-marker--current': item.status === 'current',
                                        'clock-wheel-live__timeline-marker--focused': focusedSlotIndex === item.index,
                                    }"
                                    :style="{ left: timelinePercent(item.slot.position_seconds) + '%' }"
                                    :title="anchorMarkerTitle(item)"
                                    @click="focusSlotRow(item.index)"
                                />
                            </div>
                        </div>
                    </template>

                    <p
                        v-else-if="!isLoading"
                        class="text-muted small mb-0"
                    >
                        {{ $gettext('No clock wheel is scheduled for the current hour.') }}
                    </p>
                </div>
            </div>

            <template v-if="activeWheel && slotsWithTracks.length > 0">
                <h3 class="h5 mb-2">
                    {{ $gettext('This hour — anchors, queue & projection') }}
                </h3>
                <div
                    v-if="hourPreview?.warnings?.length"
                    class="alert alert-warning py-2 small mb-2"
                >
                    <ul class="mb-0 ps-3">
                        <li
                            v-for="(warn, wi) in hourPreview.warnings"
                            :key="wi"
                        >
                            {{ warn }}
                        </li>
                    </ul>
                </div>
                <p class="small text-muted">
                    {{
                        $gettext(
                            'Queued = AutoDJ queue (matched by expected play time when possible). Projected = simulator for this hour.'
                        )
                    }}
                </p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 5rem;">
                                    {{ $gettext('Status') }}
                                </th>
                                <th>{{ $gettext('Position') }}</th>
                                <th>{{ $gettext('Type') }}</th>
                                <th>{{ $gettext('Queued') }}</th>
                                <th>{{ $gettext('Projected') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(item, idx) in slotsWithTracks"
                                :key="idx"
                                :id="slotRowId(item.index)"
                                :class="{
                                    'table-primary': item.status === 'current',
                                    'clock-wheel-live__row--focused': focusedSlotIndex === item.index,
                                }"
                            >
                                <td>
                                    <span
                                        class="badge"
                                        :class="statusBadgeClass(item.status)"
                                    >
                                        {{ statusLabel(item.status) }}
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    {{ formatPosition(item.slot.position_seconds) }}
                                    <span
                                        v-if="item.driftSeconds !== null && Math.abs(item.driftSeconds) >= 1"
                                        class="badge text-bg-warning ms-1"
                                        :title="$gettext('Simulator drift vs anchor')"
                                    >
                                        Δ{{ item.driftSeconds }}s
                                    </span>
                                </td>
                                <td>{{ formatMediaType(item.slot.type, $gettext) }}</td>
                                <td>
                                    <span v-if="item.trackLabel">{{ item.trackLabel }}</span>
                                    <span
                                        v-else
                                        class="text-muted fst-italic"
                                    >{{ $gettext('Not yet queued') }}</span>
                                    <icon-ic-warning
                                        v-if="item.queueMismatch"
                                        class="ms-1 text-warning"
                                        :title="$gettext('Queued track differs from projection')"
                                    />
                                </td>
                                <td>
                                    <span v-if="item.projectedLabel">{{ item.projectedLabel }}</span>
                                    <span
                                        v-else
                                        class="text-muted fst-italic"
                                    >—</span>
                                    <ul
                                        v-if="item.previewWarnings?.length"
                                        class="small text-warning mb-0 ps-3 mt-1"
                                    >
                                        <li
                                            v-for="(w, wi) in item.previewWarnings"
                                            :key="wi"
                                        >
                                            {{ w }}
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <template v-if="upcomingWheelEvents.length > 0">
                <h3 class="h5 mt-4 mb-2">
                    {{ $gettext('Upcoming clock wheels') }}
                </h3>
                <ul class="list-group list-group-flush">
                    <li
                        v-for="(event, idx) in upcomingWheelEvents"
                        :key="idx"
                        class="list-group-item px-0 d-flex justify-content-between gap-2"
                    >
                        <span>{{ event.title ?? event.name }}</span>
                        <span class="text-muted small text-nowrap">
                            {{ formatIsoAsDateTime(event.start) }}
                        </span>
                    </li>
                </ul>
            </template>
        </loading>

        <analytics-modal ref="$analyticsModal" />
    </div>
</template>

<script setup lang="ts">
import {computed, ref, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import Loading from '~/components/Common/Loading.vue';
import useClockWheelLiveData, {
    type ClockWheelLiveSlotRow,
    type SlotStatus,
} from '~/functions/useClockWheelLiveData.ts';
import useNowPlaying from '~/functions/useNowPlaying.ts';
import {useProfilePropsQuery} from '~/components/Stations/Profile/useProfileQuery.ts';
import {toRefs} from '@vueuse/core';
import type {ApiNowPlayingVueProps} from '~/entities/ApiInterfaces.ts';
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';
import {
    formatClockWheelPosition,
    timelinePercent,
    CLOCK_WHEEL_HOUR_SECONDS,
} from '~/functions/clockWheelPosition.ts';
import {formatMediaType} from '~/functions/mediaTypes.ts';
import AnalyticsModal from '~/components/Stations/ClockWheels/AnalyticsModal.vue';
import IconIcWarning from '~icons/ic/baseline-warning';
import IconIcMic from '~icons/ic/baseline-mic';
import IconIcRefresh from '~icons/ic/baseline-refresh';

const props = defineProps<{
    active: boolean;
}>();

const {$gettext} = useTranslate();

const {
    activeWheel,
    hourPreview,
    slotsWithTracks,
    handDegrees,
    nowPercent,
    stationTimeLabel,
    segmentSummary,
    hourHealth,
    analyticsUrl,
    conflictMessage,
    upcomingWheelEvents,
    isLoading,
    lastUpdatedAt,
    refresh,
    stationData,
} = useClockWheelLiveData(() => props.active);

const {timezone} = toRefs(stationData);
const {formatIsoAsDateTime, formatTimestampAsDateTime} = useStationDateTimeFormatter();

const profileQuery = useProfilePropsQuery();

const nowPlayingProps = computed<ApiNowPlayingVueProps>(() => {
    const fromProfile = profileQuery.data.value?.nowPlayingProps;
    if (fromProfile?.stationShortName) {
        return fromProfile;
    }
    return {
        stationShortName: stationData.value.shortName,
        useStatic: false,
        useSse: true,
    };
});

const {np} = useNowPlaying(nowPlayingProps);

const $analyticsModal = useTemplateRef('$analyticsModal');
const focusedSlotIndex = ref<number | null>(null);

const formatPosition = formatClockWheelPosition;

const CIRCLE_CIRCUMFERENCE = 2 * Math.PI * 46;

const elapsedArcDash = computed(() => {
    const len = (nowPercent.value / 100) * CIRCLE_CIRCUMFERENCE;
    return `${len} ${CIRCLE_CIRCUMFERENCE}`;
});

const currentSegmentStyle = computed(() => {
    const current = slotsWithTracks.value.find((r) => r.status === 'current');
    if (!current) {
        return null;
    }
    const rows = slotsWithTracks.value;
    const idx = rows.findIndex((r) => r.status === 'current');
    const next = rows[idx + 1];
    const left = timelinePercent(current.slot.position_seconds);
    const right = timelinePercent(next?.slot.position_seconds ?? CLOCK_WHEEL_HOUR_SECONDS - 1);
    return {
        left: `${left}%`,
        width: `${Math.max(0.5, right - left)}%`,
    };
});

const summaryCardStyle = computed(() => {
    const color = activeWheel.value?.color;
    if (!color) {
        return {};
    }
    return {
        borderLeft: `4px solid ${color}`,
    };
});

const faceBorderStyle = computed(() => {
    const color = activeWheel.value?.color;
    if (!color) {
        return {};
    }
    return {
        borderColor: color,
    };
});

const timelineTrackStyle = computed(() => {
    const color = activeWheel.value?.color;
    if (!color) {
        return {};
    }
    return {
        borderColor: color,
    };
});

const hourTicks = computed(() =>
    [0, 15, 30, 45].map((minutes) => {
        const pct = (minutes * 60) / CLOCK_WHEEL_HOUR_SECONDS;
        const angle = pct * 2 * Math.PI - Math.PI / 2;
        const radius = 47;
        return {
            minutes,
            style: {
                left: `${50 + radius * Math.cos(angle)}%`,
                top: `${50 + radius * Math.sin(angle)}%`,
            },
        };
    })
);

const ringDotStyle = (positionSeconds: number, radiusPercent: number) => {
    const pct = Math.min(CLOCK_WHEEL_HOUR_SECONDS - 1, Math.max(0, positionSeconds)) / CLOCK_WHEEL_HOUR_SECONDS;
    const angle = pct * 2 * Math.PI - Math.PI / 2;
    const r = radiusPercent;
    return {
        left: `${50 + r * Math.cos(angle)}%`,
        top: `${50 + r * Math.sin(angle)}%`,
    };
};

const hourHealthBadgeClass = computed(() => {
    switch (hourHealth.value.level) {
        case 'warning':
            return 'text-bg-warning';
        case 'caution':
            return 'text-bg-secondary';
        default:
            return 'text-bg-success';
    }
});

const hourHealthLabel = computed(() => {
    switch (hourHealth.value.level) {
        case 'warning':
            return $gettext('Hour needs attention');
        case 'caution':
            return $gettext('Minor issues');
        default:
            return $gettext('On track');
    }
});

const lastUpdatedLabel = computed(() => {
    if (!lastUpdatedAt.value) {
        return '';
    }
    return formatTimestampAsDateTime(Math.floor(lastUpdatedAt.value / 1000));
});

const formatCountdown = (seconds: number) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
};

const slotRowId = (index: number) => `clock-wheel-live-slot-${index}`;

const focusSlotRow = (index: number) => {
    focusedSlotIndex.value = index;
    document.getElementById(slotRowId(index))?.scrollIntoView({behavior: 'smooth', block: 'nearest'});
};

const anchorMarkerTitle = (item: ClockWheelLiveSlotRow) =>
    `${formatPosition(item.slot.position_seconds)} — ${formatMediaType(item.slot.type, $gettext)}`;

const projectedDotTitle = (item: ClockWheelLiveSlotRow) => {
    const proj = item.projectedLabel;
    return proj
        ? `${anchorMarkerTitle(item)} · ${$gettext('Projected')}: ${proj}`
        : anchorMarkerTitle(item);
};

const statusLabel = (status: SlotStatus) => {
    switch (status) {
        case 'played':
            return $gettext('Played');
        case 'current':
            return $gettext('Now');
        default:
            return $gettext('Next');
    }
};

const statusBadgeClass = (status: SlotStatus) => {
    switch (status) {
        case 'played':
            return 'text-bg-secondary';
        case 'current':
            return 'text-bg-success';
        default:
            return 'text-bg-light text-dark';
    }
};

const openAnalytics = () => {
    if (!activeWheel.value || !analyticsUrl.value) {
        return;
    }
    void $analyticsModal.value?.open(activeWheel.value.name, analyticsUrl.value);
};

const clockAriaLabel = computed(() =>
    $gettext('Station clock showing current hour with wheel anchor markers')
);

</script>

<style lang="scss" scoped>
.clock-wheel-live__face {
    position: relative;
    width: min(280px, 80vw);
    aspect-ratio: 1;
    border-radius: 50%;
    border: 3px solid var(--bs-border-color);
    background: radial-gradient(circle at 50% 45%, var(--bs-body-bg) 0%, var(--bs-secondary-bg) 100%);
    box-shadow: inset 0 0 12px rgba(0, 0, 0, 0.12);
}

.clock-wheel-live__arcs {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    transform: rotate(-90deg);
    pointer-events: none;
}

.clock-wheel-live__arc {
    fill: none;
    stroke-width: 4;
    stroke-linecap: round;

    &--elapsed {
        stroke: rgba(var(--bs-primary-rgb), 0.25);
    }

}

.clock-wheel-live__tick {
    position: absolute;
    width: 2px;
    height: 6px;
    background: var(--bs-secondary-color);
    transform: translate(-50%, -50%);
    opacity: 0.6;
}

.clock-wheel-live__hand {
    position: absolute;
    left: 50%;
    bottom: 50%;
    width: 3px;
    height: 42%;
    margin-left: -1.5px;
    background: var(--bs-danger);
    border-radius: 2px;
    transform-origin: bottom center;
    z-index: 3;
    transition: transform 0.15s linear;
}

.clock-wheel-live__hub {
    position: absolute;
    left: 50%;
    top: 50%;
    width: 0.5rem;
    height: 0.5rem;
    margin: -0.25rem 0 0 -0.25rem;
    border-radius: 50%;
    background: var(--bs-danger);
    z-index: 4;
}

.clock-wheel-live__marker {
    position: absolute;
    width: 0.65rem;
    height: 0.65rem;
    transform: translate(-50%, -50%);
    border-radius: 50%;
    background: var(--bs-primary);
    border: 2px solid var(--bs-body-bg);
    z-index: 2;
    cursor: pointer;

    &--past {
        opacity: 0.45;
    }

    &--current {
        background: var(--bs-success);
        opacity: 1;
        box-shadow: 0 0 0 3px rgba(var(--bs-success-rgb), 0.35);
    }
}

.clock-wheel-live__ring-dot {
    position: absolute;
    width: 0.35rem;
    height: 0.35rem;
    transform: translate(-50%, -50%);
    border-radius: 50%;
    z-index: 1;
    pointer-events: none;

    &--projected {
        background: var(--bs-info);
        opacity: 0.65;
    }
}

.clock-wheel-live__timeline-track {
    position: relative;
    height: 2.25rem;
    background: var(--bs-secondary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 0.375rem;
}

.clock-wheel-live__timeline-label {
    position: absolute;
    top: 100%;
    font-size: 0.65rem;
    color: var(--bs-secondary-color);
    margin-top: 0.15rem;

    &--start {
        left: 0;
    }

    &--end {
        right: 0;
    }
}

.clock-wheel-live__timeline-segment {
    position: absolute;
    top: 0;
    bottom: 0;
    background: rgba(var(--bs-success-rgb), 0.2);
    border-radius: 0.25rem;
    pointer-events: none;
}

.clock-wheel-live__timeline-now {
    position: absolute;
    top: -2px;
    bottom: -2px;
    width: 2px;
    background: var(--bs-danger);
    transform: translateX(-50%);
    z-index: 3;
    pointer-events: none;
}

.clock-wheel-live__timeline-marker {
    position: absolute;
    top: 50%;
    width: 0.65rem;
    height: 1.35rem;
    margin: 0;
    padding: 0;
    border: none;
    border-radius: 2px;
    background: var(--bs-primary);
    transform: translate(-50%, -50%);
    cursor: pointer;
    opacity: 0.85;
    z-index: 2;

    &--past {
        opacity: 0.45;
    }

    &--current {
        background: var(--bs-success);
        opacity: 1;
        outline: 2px solid rgba(var(--bs-success-rgb), 0.45);
        outline-offset: 1px;
    }

    &--focused {
        outline: 2px solid var(--bs-primary);
        outline-offset: 2px;
    }

    &:hover,
    &:focus {
        opacity: 1;
    }
}

.clock-wheel-live__row--focused {
    outline: 2px solid rgba(var(--bs-primary-rgb), 0.35);
    outline-offset: -2px;
}
</style>
