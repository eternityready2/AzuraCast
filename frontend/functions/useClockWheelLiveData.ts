import {computed, ref, type MaybeRefOrGetter, toValue, watch} from 'vue';
import {useIntervalFn} from '@vueuse/core';
import {useQuery} from '@tanstack/vue-query';
import {useAxios} from '~/vendor/axios.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {QueryKeys, queryKeyWithStation} from '~/entities/Queries.ts';
import {
    ApiNowPlayingStationQueue,
    ApiStationQueueDetailed,
    ApiStationSchedule,
} from '~/entities/ApiInterfaces.ts';
import {useStationData} from '~/functions/useStationQuery.ts';
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';
import type {MediaTypeValue} from '~/functions/mediaTypes.ts';
import {CLOCK_WHEEL_HOUR_SECONDS, formatClockWheelPosition} from '~/functions/clockWheelPosition.ts';

const POLL_MS = 10_000;

export interface ClockWheelListItem {
    id: number;
    name: string;
    color: string;
    is_active: boolean;
    links: {self: string};
}

export interface ClockWheelSlotRow {
    type: MediaTypeValue | string;
    algorithm: string;
    position_seconds: number;
    duration_seconds: number | null;
    is_hard_anchor?: boolean;
    sound_code?: string | null;
}

export interface ClockWheelReconciliationEventRow {
    id: number;
    event_timestamp: string;
    event_kind: string;
    fallback_reason: string | null;
    anchor_type: string | null;
    sound_code: string | null;
    drift_seconds: number | null;
}

export interface ClockWheelDetail {
    id: number;
    name: string;
    color: string;
    is_active: boolean;
    slots: ClockWheelSlotRow[];
}

export type QueueRow = ApiNowPlayingStationQueue & ApiStationQueueDetailed;

export interface ClockWheelPreviewItem {
    position_seconds: number;
    position_label: string;
    slot_type: string;
    title: string | null;
    artist: string | null;
    duration_seconds: number | null;
    drift_seconds: number;
    warnings: string[];
}

export interface ClockWheelPreviewResponse {
    hour_start: string;
    hour_start_timestamp: number;
    items: ClockWheelPreviewItem[];
    warnings: string[];
}

export type SlotStatus = 'played' | 'current' | 'upcoming';

export interface ClockWheelLiveSlotRow {
    index: number;
    slot: ClockWheelSlotRow;
    trackLabel: string | null;
    projectedLabel: string | null;
    previewWarnings: string[];
    driftSeconds: number | null;
    isPast: boolean;
    isCurrent: boolean;
    status: SlotStatus;
    queueMismatch: boolean;
}

export interface ClockWheelSegmentSummary {
    currentLabel: string | null;
    nextLabel: string | null;
    secondsUntilNext: number | null;
    segmentProgressPercent: number;
    hourWindowLabel: string | null;
}

function songLabel(row: QueueRow): string {
    if (row.autodj_custom_uri) {
        return row.autodj_custom_uri;
    }
    if (row.song?.title) {
        const artist = row.song.artist ? ` — ${row.song.artist}` : '';
        return `${row.song.title}${artist}`;
    }
    return row.song?.text ?? '';
}

function normalizeTrackLabel(label: string | null): string {
    return (label ?? '').trim().toLowerCase().replace(/\s+/g, ' ');
}

function labelsMismatch(queued: string | null, projected: string | null): boolean {
    if (!queued || !projected) {
        return false;
    }
    const a = normalizeTrackLabel(queued);
    const b = normalizeTrackLabel(projected);
    return a !== '' && b !== '' && a !== b;
}

/**
 * Map queue rows to anchor slots: first by expected play time within the hour, then fill
 * remaining upcoming slots with unscheduled queue rows in order.
 */
function mapQueueRowsToSlots(
    slots: ClockWheelSlotRow[],
    queueRows: QueueRow[],
    secondsIntoHour: number,
    playedAtToSecondsIntoHour: (playedAt: number) => number | null,
): Map<number, QueueRow> {
    const sortedSlots = [...slots].sort((a, b) => a.position_seconds - b.position_seconds);
    const map = new Map<number, QueueRow>();
    const usedRows = new Set<QueueRow>();

    for (const row of queueRows) {
        if (row.played_at == null) {
            continue;
        }
        const sec = playedAtToSecondsIntoHour(row.played_at);
        if (sec === null) {
            continue;
        }

        for (let i = 0; i < sortedSlots.length; i++) {
            const start = sortedSlots[i].position_seconds;
            const end = sortedSlots[i + 1]?.position_seconds ?? CLOCK_WHEEL_HOUR_SECONDS;
            if (sec >= start && sec < end) {
                if (!map.has(start)) {
                    map.set(start, row);
                    usedRows.add(row);
                }
                break;
            }
        }
    }

    const unmappedRows = queueRows.filter((row) => !usedRows.has(row));
    const firstUpcomingIndex = sortedSlots.findIndex((slot, i) => {
        const end = sortedSlots[i + 1]?.position_seconds ?? CLOCK_WHEEL_HOUR_SECONDS;
        return secondsIntoHour < end && !map.has(slot.position_seconds);
    });
    const startIndex = firstUpcomingIndex >= 0 ? firstUpcomingIndex : sortedSlots.length;

    let rowIdx = 0;
    for (let i = startIndex; i < sortedSlots.length && rowIdx < unmappedRows.length; i++) {
        const pos = sortedSlots[i].position_seconds;
        if (!map.has(pos)) {
            map.set(pos, unmappedRows[rowIdx]);
            rowIdx++;
        }
    }

    return map;
}

export default function useClockWheelLiveData(enabled: MaybeRefOrGetter<boolean> = true) {
    const {axios, axiosSilent} = useAxios();
    const {getStationApiUrl} = useApiRouter();
    const stationData = useStationData();
    const {now, timestampToDateTime, formatIsoAsDateTime} = useStationDateTimeFormatter();

    const scheduleUrl = getStationApiUrl('/schedule');
    const queueUrl = getStationApiUrl('/queue');
    const wheelsUrl = getStationApiUrl('/clock-wheels');
    const reconciliationUrl = getStationApiUrl('/clock-wheels/reconciliation-log');

    const queryEnabled = computed(() => toValue(enabled) && stationData.value.id > 0);

    /** Bumps every second so on-air position / countdown stay live while the tab is open. */
    const clockTick = ref(0);
    const {pause: pauseClockTick, resume: resumeClockTick} = useIntervalFn(
        () => {
            clockTick.value++;
        },
        1000,
    );

    watch(
        () => toValue(enabled),
        (isEnabled) => {
            if (isEnabled) {
                resumeClockTick();
            } else {
                pauseClockTick();
            }
        },
        {immediate: true},
    );

    const scheduleQuery = useQuery({
        queryKey: queryKeyWithStation([QueryKeys.StationPlaylists, 'schedule_live']),
        queryFn: async ({signal}) => {
            const {data} = await axios.get<ApiStationSchedule[]>(scheduleUrl.value, {
                signal,
                params: {rows: 40},
            });
            return data;
        },
        enabled: queryEnabled,
        refetchInterval: POLL_MS,
    });

    const wheelsQuery = useQuery({
        queryKey: queryKeyWithStation([QueryKeys.StationPlaylists, 'clock_wheels_list']),
        queryFn: async ({signal}) => {
            const {data} = await axios.get<{rows: ClockWheelListItem[]}>(wheelsUrl.value, {
                signal,
                params: {internal: true, rowCount: 0},
            });
            return data.rows ?? [];
        },
        enabled: queryEnabled,
        staleTime: 5 * 60 * 1000,
        refetchInterval: POLL_MS,
    });

    const queueQuery = useQuery({
        queryKey: queryKeyWithStation([QueryKeys.StationQueue, 'live_tab']),
        queryFn: async ({signal}) => {
            const {data} = await axiosSilent.get<{rows: QueueRow[]}>(queueUrl.value, {
                signal,
                params: {internal: true, rowCount: 0},
            });
            return data.rows ?? [];
        },
        enabled: queryEnabled,
        refetchInterval: POLL_MS,
    });

    const ongoingEvents = computed(() =>
        (scheduleQuery.data.value ?? []).filter((e) => e.is_now)
    );

    const activeClockWheelEvent = computed(() =>
        ongoingEvents.value.find((e) => e.type === 'clock_wheel')
    );

    const activeWheelMeta = computed(() => {
        const event = activeClockWheelEvent.value;
        if (!event?.name) {
            return null;
        }
        const wheels = wheelsQuery.data.value ?? [];
        return wheels.find((w) => w.name === event.name && w.is_active) ?? null;
    });

    const wheelDetailQuery = useQuery({
        queryKey: computed(() =>
            queryKeyWithStation(
                [QueryKeys.StationPlaylists, 'clock_wheel_detail', activeWheelMeta.value?.id],
            )
        ),
        queryFn: async ({signal}) => {
            const meta = activeWheelMeta.value;
            if (!meta) {
                return null;
            }
            const {data} = await axios.get<ClockWheelDetail>(meta.links.self, {signal});
            return data;
        },
        enabled: computed(() => queryEnabled.value && activeWheelMeta.value !== null),
        refetchInterval: POLL_MS,
    });

    const activeWheel = computed(() => wheelDetailQuery.data.value ?? null);

    const currentHourIso = computed(() => now().startOf('hour').toISO());

    const hourStart = computed(() => now().startOf('hour'));

    const previewQuery = useQuery({
        queryKey: computed(() =>
            queryKeyWithStation(
                [QueryKeys.StationPlaylists, 'clock_wheel_live_preview', activeWheelMeta.value?.id, currentHourIso.value],
            )
        ),
        queryFn: async ({signal}) => {
            const meta = activeWheelMeta.value;
            if (!meta) {
                return null;
            }
            const previewBase = meta.links.self.replace(/\/?$/, '') + '/preview';
            const {data} = await axios.get<ClockWheelPreviewResponse>(previewBase, {
                signal,
                params: {hour: currentHourIso.value},
            });
            return data;
        },
        enabled: computed(() => queryEnabled.value && activeWheelMeta.value !== null),
        refetchInterval: POLL_MS,
    });

    const previewByPosition = computed(() => {
        const map = new Map<number, ClockWheelPreviewItem>();
        for (const item of previewQuery.data.value?.items ?? []) {
            map.set(item.position_seconds, item);
        }
        return map;
    });

    const secondsIntoHour = computed(() => {
        void clockTick.value;
        const dt = now();
        return dt.minute * 60 + dt.second;
    });

    const handDegrees = computed(() => (secondsIntoHour.value / CLOCK_WHEEL_HOUR_SECONDS) * 360);

    const nowPercent = computed(() => (secondsIntoHour.value / CLOCK_WHEEL_HOUR_SECONDS) * 100);

    const stationTimeLabel = computed(() => {
        const dt = now();
        return dt.toLocaleString({
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
        });
    });

    const playedAtToSecondsIntoHour = (playedAt: number): number | null => {
        const dt = timestampToDateTime(playedAt);
        const start = hourStart.value;
        if (!dt.isValid || !start.isValid) {
            return null;
        }
        const sec = Math.floor(dt.diff(start, 'seconds').seconds);
        if (sec < 0 || sec >= CLOCK_WHEEL_HOUR_SECONDS) {
            return null;
        }
        return sec;
    };

    const wheelQueueRows = computed(() => {
        const wheelName = activeWheel.value?.name;
        if (!wheelName) {
            return [];
        }
        return (queueQuery.data.value ?? [])
            .filter((row) => row.clock_wheel === wheelName)
            .sort((a, b) => (a.played_at ?? Number.MAX_SAFE_INTEGER) - (b.played_at ?? Number.MAX_SAFE_INTEGER));
    });

    const queueBySlotPosition = computed(() =>
        mapQueueRowsToSlots(
            activeWheel.value?.slots ?? [],
            wheelQueueRows.value,
            secondsIntoHour.value,
            playedAtToSecondsIntoHour,
        )
    );

    const slotsWithTracks = computed((): ClockWheelLiveSlotRow[] => {
        const slots = [...(activeWheel.value?.slots ?? [])].sort(
            (a, b) => a.position_seconds - b.position_seconds
        );

        return slots.map((slot, index) => {
            const previewItem = previewByPosition.value.get(slot.position_seconds);
            const projectedLabel = previewItem?.title
                ? previewItem.artist
                    ? `${previewItem.title} — ${previewItem.artist}`
                    : previewItem.title
                : null;
            const queueRow = queueBySlotPosition.value.get(slot.position_seconds);
            const trackLabel = queueRow ? songLabel(queueRow) : null;

            const next = slots[index + 1];
            const start = slot.position_seconds;
            const end = next?.position_seconds ?? CLOCK_WHEEL_HOUR_SECONDS;
            const isCurrent = secondsIntoHour.value >= start && secondsIntoHour.value < end;
            const isPast = !isCurrent && slot.position_seconds <= secondsIntoHour.value;

            let status: SlotStatus = 'upcoming';
            if (isCurrent) {
                status = 'current';
            } else if (isPast) {
                status = 'played';
            }

            return {
                index,
                slot,
                trackLabel,
                projectedLabel,
                previewWarnings: previewItem?.warnings ?? [],
                driftSeconds: previewItem?.drift_seconds ?? null,
                isPast,
                isCurrent,
                status,
                queueMismatch: labelsMismatch(trackLabel, projectedLabel),
            };
        });
    });

    const currentSlotRow = computed(() =>
        slotsWithTracks.value.find((row) => row.isCurrent) ?? null
    );

    const nextSlotRow = computed(() => {
        const rows = slotsWithTracks.value;
        const currentIndex = rows.findIndex((row) => row.isCurrent);
        if (currentIndex >= 0 && currentIndex < rows.length - 1) {
            return rows[currentIndex + 1];
        }
        if (currentIndex < 0) {
            return rows.find((row) => row.status === 'upcoming') ?? null;
        }
        return null;
    });

    const segmentSummary = computed((): ClockWheelSegmentSummary => {
        const formatSlot = (row: ClockWheelLiveSlotRow | null) =>
            row
                ? `${formatClockWheelPosition(row.slot.position_seconds)} ${row.slot.type}`
                : null;

        const current = currentSlotRow.value;
        const next = nextSlotRow.value;

        let secondsUntilNext: number | null = null;
        let segmentProgressPercent = 0;

        if (current) {
            const slotEnd = (() => {
                const rows = slotsWithTracks.value;
                const idx = rows.findIndex((r) => r.isCurrent);
                const nextSlot = rows[idx + 1];
                return nextSlot?.slot.position_seconds ?? CLOCK_WHEEL_HOUR_SECONDS;
            })();
            const elapsed = secondsIntoHour.value - current.slot.position_seconds;
            const window = Math.max(1, slotEnd - current.slot.position_seconds);
            segmentProgressPercent = Math.min(100, Math.max(0, (elapsed / window) * 100));
            secondsUntilNext = Math.max(0, slotEnd - secondsIntoHour.value);
        } else if (next) {
            secondsUntilNext = Math.max(0, next.slot.position_seconds - secondsIntoHour.value);
        }

        const event = activeClockWheelEvent.value;
        let hourWindowLabel: string | null = null;
        if (event?.start) {
            const endPart = event.end ? ` – ${formatIsoAsDateTime(event.end)}` : '';
            hourWindowLabel = `${formatIsoAsDateTime(event.start)}${endPart}`;
        }

        return {
            currentLabel: formatSlot(current),
            nextLabel: formatSlot(next),
            secondsUntilNext,
            segmentProgressPercent,
            hourWindowLabel,
        };
    });

    const hourHealth = computed(() => {
        const warnings = hourPreview.value?.warnings?.length ?? 0;
        const slotWarnings = slotsWithTracks.value.filter(
            (r) => r.previewWarnings.length > 0 || r.queueMismatch
        ).length;
        const driftSlots = slotsWithTracks.value.filter(
            (r) => r.driftSeconds !== null && Math.abs(r.driftSeconds) >= 5
        ).length;

        if (warnings > 0 || driftSlots > 0) {
            return {level: 'warning' as const, count: warnings + slotWarnings + driftSlots};
        }
        if (slotWarnings > 0) {
            return {level: 'caution' as const, count: slotWarnings};
        }
        return {level: 'ok' as const, count: 0};
    });

    const analyticsUrl = computed(() => {
        const meta = activeWheelMeta.value;
        if (!meta) {
            return null;
        }
        return meta.links.self.replace(/\/?$/, '') + '/analytics';
    });

    const conflictMessage = computed(() => {
        const ongoing = ongoingEvents.value;
        const hasPlaylistOrStreamer = ongoing.some(
            (e) => e.type === 'playlist' || e.type === 'streamer'
        );
        if (hasPlaylistOrStreamer) {
            const names = ongoing
                .filter((e) => e.type === 'playlist' || e.type === 'streamer')
                .map((e) => e.description ?? e.title ?? e.name)
                .join(', ');
            return {active: true, detail: names};
        }
        return {active: false, detail: ''};
    });

    const upcomingWheelEvents = computed(() => {
        const stationNow = now().toSeconds();
        return (scheduleQuery.data.value ?? [])
            .filter((e) => e.type === 'clock_wheel' && !e.is_now && (e.start_timestamp ?? 0) > stationNow)
            .sort((a, b) => (a.start_timestamp ?? 0) - (b.start_timestamp ?? 0))
            .slice(0, 8);
    });

    const isLoading = computed(
        () =>
            scheduleQuery.isPending.value
            || wheelsQuery.isPending.value
            || (activeWheelMeta.value !== null && wheelDetailQuery.isPending.value)
            || (activeWheelMeta.value !== null && previewQuery.isPending.value)
    );

    const hourPreview = computed(() => previewQuery.data.value ?? null);

    const reconciliationQuery = useQuery({
        queryKey: computed(() =>
            queryKeyWithStation(
                [QueryKeys.StationPlaylists, 'clock_wheel_reconciliation', activeWheelMeta.value?.id],
            )
        ),
        queryFn: async ({signal}) => {
            const meta = activeWheelMeta.value;
            const {data} = await axiosSilent.get<{rows: ClockWheelReconciliationEventRow[]}>(
                reconciliationUrl.value,
                {
                    signal,
                    params: {
                        limit: 12,
                        wheel_id: meta?.id,
                    },
                },
            );
            return data.rows ?? [];
        },
        enabled: computed(() => queryEnabled.value && activeWheelMeta.value !== null),
        refetchInterval: POLL_MS,
    });

    const recentEvents = computed(() => reconciliationQuery.data.value ?? []);

    const lastUpdatedAt = computed(() => {
        const times = [
            scheduleQuery.dataUpdatedAt.value,
            queueQuery.dataUpdatedAt.value,
            wheelDetailQuery.dataUpdatedAt.value,
            previewQuery.dataUpdatedAt.value,
        ].filter((t) => t > 0);
        return times.length > 0 ? Math.max(...times) : 0;
    });

    const refresh = async () => {
        await Promise.all([
            scheduleQuery.refetch(),
            wheelsQuery.refetch(),
            queueQuery.refetch(),
            wheelDetailQuery.refetch(),
            previewQuery.refetch(),
            reconciliationQuery.refetch(),
        ]);
    };

    return {
        stationData,
        activeWheel,
        activeWheelMeta,
        activeClockWheelEvent,
        hourPreview,
        slotsWithTracks,
        wheelQueueRows,
        secondsIntoHour,
        handDegrees,
        nowPercent,
        stationTimeLabel,
        segmentSummary,
        currentSlotRow,
        nextSlotRow,
        hourHealth,
        analyticsUrl,
        conflictMessage,
        upcomingWheelEvents,
        recentEvents,
        isLoading,
        lastUpdatedAt,
        refresh,
        now,
    };
}
