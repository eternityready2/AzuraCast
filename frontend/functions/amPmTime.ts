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
