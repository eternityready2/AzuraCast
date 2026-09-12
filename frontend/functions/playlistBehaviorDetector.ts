export type PlaylistBehavior = 'rotation' | 'programme' | 'priority';

export interface PlaylistBehaviorScheduleLike {
    start_time?: number | string | null;
    end_time?: number | string | null;
    strict_start?: boolean | null;
    is_emergency?: boolean | null;
}

export interface PlaylistBehaviorInput {
    name?: string | null;
    description?: string | null;
    hasSchedule: boolean;
    scheduleItems?: PlaylistBehaviorScheduleLike[] | null;
}

export interface PlaylistBehaviorDetection {
    behavior: PlaylistBehavior;
    reason: string;
}

const priorityPattern = /\b(news|bulletin|breaking|alert|emergency|warning|urgent|weather\s+alert|traffic\s+alert)\b/i;
const musicPattern = /\b(music|hymn|hymns|favorite|favorites|favourite|favourites|song|songs|worship|praise|gospel|rotation|mix|music\s+block)\b/i;
const programmePattern = /\b(show|programme|program|podcast|sermon|ministry|talk|episode|broadcast|feature|special)\b/i;

const timeCodeToMinutes = (value: number | string | null | undefined): number | null => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const text = String(value).padStart(4, '0');
    if (!/^\d{4}$/.test(text)) {
        return null;
    }

    const hours = Number(text.slice(0, 2));
    const minutes = Number(text.slice(2, 4));
    if (hours > 23 || minutes > 59) {
        return null;
    }

    return (hours * 60) + minutes;
};

const scheduleDurationMinutes = (schedule: PlaylistBehaviorScheduleLike): number | null => {
    const start = timeCodeToMinutes(schedule.start_time);
    const end = timeCodeToMinutes(schedule.end_time);
    if (start === null || end === null) {
        return null;
    }

    if (start === end) {
        return 24 * 60;
    }

    return end > start ? end - start : (24 * 60) - start + end;
};

export const detectPlaylistBehavior = (input: PlaylistBehaviorInput): PlaylistBehaviorDetection => {
    const scheduleItems = input.scheduleItems ?? [];
    const searchable = `${input.name ?? ''} ${input.description ?? ''}`.trim();

    if (scheduleItems.some((schedule) => Boolean(schedule.is_emergency)) || priorityPattern.test(searchable)) {
        return {
            behavior: 'priority',
            reason: 'Time-sensitive news, alert or priority programming was detected.',
        };
    }

    if (!input.hasSchedule) {
        return {
            behavior: 'rotation',
            reason: 'No schedule is attached, so normal rotation behavior is the safest default.',
        };
    }

    // A per-row Strict / Exact Time choice is authoritative. It must beat name
    // and duration heuristics; otherwise a schedule such as "Hymns and Favorites"
    // is incorrectly changed back to Rotation merely because it contains music.
    if (scheduleItems.some((schedule) => Boolean(schedule.strict_start))) {
        return {
            behavior: 'programme',
            reason: 'An exact-start scheduled programme was detected.',
        };
    }

    if (musicPattern.test(searchable)) {
        return {
            behavior: 'rotation',
            reason: 'Scheduled music or rotation content was detected.',
        };
    }

    if (programmePattern.test(searchable)) {
        return {
            behavior: 'programme',
            reason: 'A scheduled show or programme was detected.',
        };
    }

    const durations = scheduleItems
        .map(scheduleDurationMinutes)
        .filter((duration): duration is number => duration !== null);

    if (durations.length > 0 && Math.max(...durations) >= 180) {
        return {
            behavior: 'rotation',
            reason: 'A long scheduled block was detected, so continuous rotation behavior was selected.',
        };
    }

    return {
        behavior: 'programme',
        reason: 'A scheduled programming block was detected.',
    };
};