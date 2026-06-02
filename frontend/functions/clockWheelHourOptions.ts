/**
 * Hour-of-day (0–23) options for clock wheel dayparts.
 */
export type ClockWheelHourOption = {
    value: number;
    text: string;
};

export function formatClockWheelHourLabel(hour: number): string {
    const h = Math.min(23, Math.max(0, Math.trunc(hour)));

    if (h === 0) {
        return '12:00 AM (midnight)';
    }
    if (h === 12) {
        return '12:00 PM (noon)';
    }
    if (h < 12) {
        return `${h}:00 AM`;
    }

    return `${h - 12}:00 PM`;
}

export function buildClockWheelHourOptions(
    labelForHour: (hour: number) => string = formatClockWheelHourLabel,
): ClockWheelHourOption[] {
    return Array.from({length: 24}, (_, hour) => ({
        value: hour,
        text: labelForHour(hour),
    }));
}
