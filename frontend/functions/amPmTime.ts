/**
 * 12-hour display + parsing for schedule times (HHMM) and daypart hours (0–23).
 */

export type ParsedAmPmTime = {
    hour: number;
    minutes: number;
};

/** AzuraCast schedule API stores time as HHMM (e.g. 930 = 9:30 AM). */
export function formatTimeCodeToAmPm(timeCode: number): string {
    const padded = String(Math.max(0, Math.trunc(timeCode))).padStart(4, '0');
    const hour24 = parseInt(padded.slice(0, 2), 10);
    const minutes = parseInt(padded.slice(2), 10);

    return formatPartsToAmPm(hour24, minutes);
}

/** Daypart start/end hour (minutes always :00). */
export function formatHourOfDayToAmPm(hourOfDay: number): string {
    const hour24 = Math.min(23, Math.max(0, Math.trunc(hourOfDay)));

    return formatPartsToAmPm(hour24, 0);
}

/**
 * Schedule window for a daypart hourly wheel: one hour starting at :00.
 * Returns AzuraCast HHMM values (e.g. 9 → 900–1000, 23 → 2300–0).
 */
export function scheduleTimeWindowForHourOfDay(hourOfDay: number): {
    start_time: number;
    end_time: number;
} {
    const hour = Math.min(23, Math.max(0, Math.trunc(hourOfDay)));

    return {
        start_time: hour * 100,
        end_time: ((hour + 1) % 24) * 100,
    };
}

export function formatPartsToAmPm(hour24: number, minutes: number): string {
    const mins = Math.min(59, Math.max(0, Math.trunc(minutes)));
    let hour12 = Math.trunc(hour24) % 24;
    const period = hour12 >= 12 ? 'PM' : 'AM';

    if (hour12 === 0) {
        hour12 = 12;
    } else if (hour12 > 12) {
        hour12 -= 12;
    }

    return `${hour12}:${String(mins).padStart(2, '0')} ${period}`;
}

/**
 * Parse flexible manual input: "6:00 AM", "6:00am", "06:30 PM", "6 PM".
 */
export function parseAmPmTime(text: string): ParsedAmPmTime | null {
    const trimmed = text.trim();
    if (trimmed === '') {
        return null;
    }

    const match = trimmed.match(
        /^(\d{1,2})(?::(\d{2}))?\s*(a\.?m\.?|p\.?m\.?)\s*$/i
    );

    if (!match) {
        return null;
    }

    let hour12 = parseInt(match[1], 10);
    const minutes = match[2] !== undefined ? parseInt(match[2], 10) : 0;
    const period = match[3].replace(/\./g, '').toUpperCase();

    if (
        Number.isNaN(hour12)
        || Number.isNaN(minutes)
        || hour12 < 1
        || hour12 > 12
        || minutes < 0
        || minutes > 59
    ) {
        return null;
    }

    let hour24 = hour12;

    if (period === 'AM') {
        hour24 = hour12 === 12 ? 0 : hour12;
    } else if (period === 'PM') {
        hour24 = hour12 === 12 ? 12 : hour12 + 12;
    } else {
        return null;
    }

    return {hour: hour24, minutes};
}

export function timeCodeFromParsed(parsed: ParsedAmPmTime): number {
    return parsed.hour * 100 + parsed.minutes;
}

export function parseTimeCodeFromAmPm(text: string): number | null {
    const parsed = parseAmPmTime(text);

    return parsed !== null ? timeCodeFromParsed(parsed) : null;
}

export function parseHourOfDayFromAmPm(text: string, requireWholeHour = true): number | null {
    const parsed = parseAmPmTime(text);

    if (parsed === null) {
        return null;
    }

    if (requireWholeHour && parsed.minutes !== 0) {
        return null;
    }

    return parsed.hour;
}

export type AmPmPeriod = 'AM' | 'PM';

export type AmPmTimeSegments = {
    hour12: number;
    minutes: number;
    period: AmPmPeriod;
};

/** Convert schedule HHMM or daypart hour (0–23) into 12-hour segments. */
export function modelValueToSegments(
    value: number,
    wholeHourOnly: boolean,
): AmPmTimeSegments {
    if (wholeHourOnly) {
        return hour24ToSegments(Math.min(23, Math.max(0, Math.trunc(value))), 0);
    }

    const padded = String(Math.max(0, Math.trunc(value))).padStart(4, '0');
    const hour24 = parseInt(padded.slice(0, 2), 10);
    const minutes = parseInt(padded.slice(2), 10);

    return hour24ToSegments(hour24, minutes);
}

function hour24ToSegments(hour24: number, minutes: number): AmPmTimeSegments {
    const h = ((Math.trunc(hour24) % 24) + 24) % 24;
    const period: AmPmPeriod = h >= 12 ? 'PM' : 'AM';
    let hour12 = h % 12;
    if (hour12 === 0) {
        hour12 = 12;
    }

    return {
        hour12,
        minutes: snapMinuteToFive(Math.min(59, Math.max(0, Math.trunc(minutes)))),
        period,
    };
}

export function segmentsToTimeCode(segments: AmPmTimeSegments): number {
    const parsed = parseAmPmTime(
        `${segments.hour12}:${String(segments.minutes).padStart(2, '0')} ${segments.period}`
    );

    return parsed !== null ? timeCodeFromParsed(parsed) : 0;
}

export function segmentsToHourOfDay(segments: AmPmTimeSegments): number {
    const parsed = parseAmPmTime(`${segments.hour12}:00 ${segments.period}`);

    return parsed !== null ? parsed.hour : 0;
}

export function segmentsToModelValue(
    segments: AmPmTimeSegments,
    wholeHourOnly: boolean,
): number {
    return wholeHourOnly
        ? segmentsToHourOfDay({...segments, minutes: 0})
        : segmentsToTimeCode(segments);
}

export const HOUR12_OPTIONS = [12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11] as const;

export const MINUTE_OPTIONS = Array.from({length: 12}, (_, i) => i * 5);

/** Snap API minutes to the nearest 5-minute step used in the UI. */
export function snapMinuteToFive(minutes: number): number {
    return Math.min(55, Math.max(0, Math.round(minutes / 5) * 5));
}

export type AmPmFormatState = {
    /** Normalized display string shown in the input. */
    display: string;
    /** Parsed value when display is complete and valid. */
    parsed: number | null;
    /** True when hour, minutes (:00 for whole-hour), and AM/PM are all set. */
    complete: boolean;
};

function clampHour12(hour12: number): number {
    if (hour12 < 1) {
        return 1;
    }
    if (hour12 > 12) {
        return 12;
    }

    return hour12;
}

function splitDigitsToHourMinute(digits: string): {hour12: number; minutes: number} {
    const d = digits.slice(0, 4);

    if (d.length === 0) {
        return {hour12: 0, minutes: 0};
    }

    if (d.length === 1) {
        return {hour12: clampHour12(parseInt(d, 10)), minutes: 0};
    }

    if (d.length === 2) {
        const n = parseInt(d, 10);
        if (n > 12) {
            return {
                hour12: clampHour12(parseInt(d[0], 10)),
                minutes: Math.min(59, parseInt(d[1], 10) * 10),
            };
        }

        return {hour12: clampHour12(n), minutes: 0};
    }

    if (d.length === 3) {
        return {
            hour12: clampHour12(parseInt(d[0], 10)),
            minutes: Math.min(59, parseInt(d.slice(1), 10)),
        };
    }

    const hh = parseInt(d.slice(0, 2), 10);
    const mm = parseInt(d.slice(2), 10);

    if (hh > 12) {
        return {
            hour12: clampHour12(parseInt(d[0], 10)),
            minutes: Math.min(59, parseInt(d.slice(1, 3), 10)),
        };
    }

    return {hour12: clampHour12(hh), minutes: Math.min(59, mm)};
}

function detectPeriodFromCleaned(cleaned: string): 'AM' | 'PM' | null {
    if (/\bP\b|PM/.test(cleaned)) {
        return 'PM';
    }
    if (/\bA\b|AM/.test(cleaned)) {
        return 'AM';
    }

    return null;
}

/**
 * Auto-formats while typing: strips junk, inserts ":", caps digits, appends AM/PM.
 * Typing "930a" becomes "9:30 AM"; "09:0000" cannot be entered (max 4 digits).
 */
export function formatAmPmTimeAsYouType(
    raw: string,
    wholeHourOnly: boolean,
): AmPmFormatState {
    const cleaned = raw.toUpperCase().replace(/[^0-9APM:\s]/g, '');
    const digits = cleaned.replace(/\D/g, '').slice(0, wholeHourOnly ? 2 : 4);
    const period = detectPeriodFromCleaned(cleaned);

    if (digits.length === 0 && period === null) {
        return {display: '', parsed: null, complete: false};
    }

    const {hour12, minutes} = splitDigitsToHourMinute(digits);
    const mins = wholeHourOnly ? 0 : minutes;

    let display = '';
    if (digits.length === 0) {
        display = '';
    } else if (wholeHourOnly) {
        display = period !== null || digits.length >= 2 || hour12 > 9
            ? `${hour12}:00`
            : `${hour12}`;
    } else if (mins === 0) {
        display = digits.length >= 2 || hour12 > 9
            ? `${hour12}:00`
            : `${hour12}`;
    } else {
        display = `${hour12}:${String(mins).padStart(2, '0')}`;
    }

    if (period !== null) {
        display = display ? `${display} ${period}` : period;
    }

    const complete = digits.length > 0
        && period !== null
        && (wholeHourOnly ? digits.length >= 1 : digits.length >= 3);

    let parsed: number | null = null;
    if (complete && period !== null) {
        const tryText = `${hour12}:${String(mins).padStart(2, '0')} ${period}`;
        parsed = wholeHourOnly
            ? parseHourOfDayFromAmPm(tryText, true)
            : parseTimeCodeFromAmPm(tryText);
    }

    return {display, parsed, complete};
}

/** Suggested values for native autocomplete (datalist). */
export function buildAmPmTimeSuggestions(wholeHourOnly: boolean): string[] {
    const suggestions: string[] = [];

    if (wholeHourOnly) {
        for (let h = 0; h < 24; h++) {
            suggestions.push(formatHourOfDayToAmPm(h));
        }
        return suggestions;
    }

    for (let hour24 = 0; hour24 < 24; hour24++) {
        for (let m = 0; m < 60; m += 15) {
            suggestions.push(formatPartsToAmPm(hour24, m));
        }
    }

    return suggestions;
}
