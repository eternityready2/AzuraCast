/** Seconds in a broadcast hour (matches backend anchor range). */
export const CLOCK_WHEEL_HOUR_SECONDS = 3600;

export interface ClockWheelTimelineEntry {
    position_seconds: number;
}

export function formatClockWheelPosition(totalSeconds: number): string {
    const clamped = Math.min(CLOCK_WHEEL_HOUR_SECONDS - 1, Math.max(0, totalSeconds));
    const mins = Math.floor(clamped / 60);
    const secs = clamped % 60;
    return `${mins}:${String(secs).padStart(2, '0')}`;
}

export function parseClockWheelPosition(value: string): number {
    const trimmed = value.trim();
    if (trimmed === '') {
        return 0;
    }

    const parts = trimmed.split(':');
    if (parts.length !== 2) {
        return 0;
    }

    const mins = parseInt(parts[0], 10);
    const secs = parseInt(parts[1], 10);
    if (Number.isNaN(mins) || Number.isNaN(secs)) {
        return 0;
    }

    return Math.min(CLOCK_WHEEL_HOUR_SECONDS - 1, Math.max(0, mins * 60 + secs));
}

export function sortClockWheelEntries<T extends ClockWheelTimelineEntry>(entries: T[]): void {
    entries.sort((a, b) => a.position_seconds - b.position_seconds);
}

/**
 * After drag-reorder, keep anchor times but assign them to rows in the new visual order.
 */
export function applyDragOrderToPositions<T extends ClockWheelTimelineEntry>(entries: T[]): void {
    const positions = entries.map((e) => e.position_seconds).sort((a, b) => a - b);
    entries.forEach((entry, index) => {
        entry.position_seconds = positions[index] ?? entry.position_seconds;
    });
    sortClockWheelEntries(entries);
}

export function timelinePercent(positionSeconds: number): number {
    return (Math.min(CLOCK_WHEEL_HOUR_SECONDS - 1, Math.max(0, positionSeconds)) / CLOCK_WHEEL_HOUR_SECONDS) * 100;
}

export interface ClockWheelTimelineWarning {
    index: number;
    message: string;
}

export function getClockWheelTimelineWarnings(
    entries: ClockWheelTimelineEntry[],
    gettext: (msg: string) => string,
): ClockWheelTimelineWarning[] {
    const warnings: ClockWheelTimelineWarning[] = [];
    const sorted = [...entries].sort((a, b) => a.position_seconds - b.position_seconds);

    for (let i = 0; i < sorted.length; i++) {
        const entry = sorted[i];
        const next = sorted[i + 1];

        if (next && next.position_seconds === entry.position_seconds) {
            const index = entries.indexOf(entry);
            warnings.push({
                index,
                message: gettext('Another anchor uses the same time.'),
            });
        }

        if (next) {
            const gap = next.position_seconds - entry.position_seconds;
            if (gap > 0 && gap < 10) {
                const index = entries.indexOf(entry);
                warnings.push({
                    index,
                    message: gettext('Very little time before the next anchor.'),
                });
            }
        }
    }

    return warnings;
}

export interface ClockWheelHourBucket {
    segment: number;
    label: string;
    count: number;
}

/** Count anchors in each 2.5-minute segment of the broadcast hour (24 buckets). */
export function getClockWheelHourDistribution(
    entries: ClockWheelTimelineEntry[],
): ClockWheelHourBucket[] {
    const segmentSeconds = CLOCK_WHEEL_HOUR_SECONDS / 24;
    const counts = Array.from({length: 24}, () => 0);

    for (const entry of entries) {
        const idx = Math.min(23, Math.floor(entry.position_seconds / segmentSeconds));
        counts[idx]++;
    }

    return counts.map((count, segment) => ({
        segment,
        label: formatClockWheelPosition(Math.floor(segment * segmentSeconds)),
        count,
    }));
}

/** Rough loop duration: sum of max(duration cap, 180s default music) per slot. */
export function estimateClockWheelLoopSeconds(
    entries: Array<ClockWheelTimelineEntry & {duration_seconds?: number | null; type?: string}>,
): number {
    let total = 0;
    const sorted = [...entries].sort((a, b) => a.position_seconds - b.position_seconds);

    for (let i = 0; i < sorted.length; i++) {
        const entry = sorted[i];
        const next = sorted[i + 1];
        const window = next
            ? Math.max(1, next.position_seconds - entry.position_seconds)
            : CLOCK_WHEEL_HOUR_SECONDS - entry.position_seconds;

        if (entry.duration_seconds != null && entry.duration_seconds > 0) {
            total += Math.min(entry.duration_seconds, window);
        } else if (entry.type === 'legal_id' || entry.type === 'id' || entry.type === 'sweeper') {
            total += Math.min(30, window);
        } else {
            total += Math.min(210, window);
        }
    }

    return Math.min(CLOCK_WHEEL_HOUR_SECONDS, total);
}

export function isClockWheelLayoutValid(entries: ClockWheelTimelineEntry[]): boolean {
    return getClockWheelTimelineWarnings(entries, (m) => m).length === 0;
}

