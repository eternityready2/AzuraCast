export type PlaylistBehaviorPreset = 'show' | 'music' | 'news';

export interface PlaylistBehaviorScheduleRow {
    start_time?: number | string | null;
    end_time?: number | string | null;
    loop_once?: boolean;
    prevent_requests?: boolean;
    strict_start?: boolean;
}

export interface PlaylistBehaviorDetectorInput {
    name?: string | null;
    description?: string | null;
    order?: string | null;
    type?: string | null;
    backendOptions?: string[] | null;
    scheduleItems?: PlaylistBehaviorScheduleRow[] | null;
}

export interface PlaylistBehaviorDetection {
    preset: PlaylistBehaviorPreset;
    reason: 'news_signal' | 'programme_signal' | 'music_signal' | 'music_order' | 'long_block' | 'play_once' | 'scheduled_default';
}

const NEWS_RE = /\b(news|newscast|bulletin|alert|emergency|weather|traffic)\b/i;
const PROGRAMME_RE = /\b(show|programme|program|spotlight|podcast|sermon|teaching|interview|ministry|broadcast|radio hour)\b/i;
const MUSIC_RE = /\b(music|hymn|hymns|favorite|favorites|worship|praise|gospel|song|songs|rotation|mix)\b/i;
const MUSIC_ORDERS = new Set(['random', 'shuffle', 'smart_shuffle']);

const timeCodeToMinutes = (value: number | string | null | undefined): number | null => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const padded = String(value).replace(':', '').padStart(4, '0');
    const hours = Number(padded.slice(0, 2));
    const minutes = Number(padded.slice(2, 4));

    if (!Number.isFinite(hours) || !Number.isFinite(minutes)) {
        return null;
    }

    return (hours * 60) + minutes;
};

export const getPlaylistScheduleWindowMinutes = (
    row: PlaylistBehaviorScheduleRow
): number | null => {
    const start = timeCodeToMinutes(row.start_time);
    const end = timeCodeToMinutes(row.end_time);

    if (start === null || end === null) {
        return null;
    }

    if (end >= start) {
        return end - start;
    }

    return (24 * 60) - start + end;
};

export const detectPlaylistBehavior = (
    input: PlaylistBehaviorDetectorInput
): PlaylistBehaviorDetection => {
    const scheduleItems = input.scheduleItems ?? [];
    const backendOptions = input.backendOptions ?? [];
    const text = `${input.name ?? ''} ${input.description ?? ''}`.trim();

    const hasPrioritySignal = backendOptions.includes('prioritize')
        || scheduleItems.some((item) => Boolean(item.prevent_requests));

    if (hasPrioritySignal || NEWS_RE.test(text)) {
        return {preset: 'news', reason: 'news_signal'};
    }

    const hasProgrammeSignal = PROGRAMME_RE.test(text)
        || backendOptions.includes('merge')
        || input.order === 'sequential';

    if (hasProgrammeSignal) {
        return {preset: 'show', reason: 'programme_signal'};
    }

    if (MUSIC_RE.test(text)) {
        return {preset: 'music', reason: 'music_signal'};
    }

    if (input.type && input.type !== 'default') {
        return {preset: 'music', reason: 'music_order'};
    }

    if (input.order && MUSIC_ORDERS.has(input.order)) {
        return {preset: 'music', reason: 'music_order'};
    }

    const longestWindow = scheduleItems.reduce((longest, row) => {
        const minutes = getPlaylistScheduleWindowMinutes(row);
        return minutes === null ? longest : Math.max(longest, minutes);
    }, 0);

    if (longestWindow >= 120) {
        return {preset: 'music', reason: 'long_block'};
    }

    if (scheduleItems.length > 0 && scheduleItems.every((item) => Boolean(item.loop_once))) {
        return {preset: 'show', reason: 'play_once'};
    }

    return {preset: 'show', reason: 'scheduled_default'};
};
