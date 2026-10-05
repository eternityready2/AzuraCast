import {CLOCK_WHEEL_HOUR_SECONDS, type ClockWheelTimelineWarning} from '~/functions/clockWheelPosition.ts';
import {defaultClockWheelSlotEditorRow, type ClockWheelSlotEditorRow} from '~/functions/clockWheelSlotEditor.ts';
import type {MediaTypeValue} from '~/functions/mediaTypes.ts';

/** Library lengths and Top-of-Hour timing from GET /clock-wheels/slot-lengths. */
export interface ClockWheelSlotLengths {
    top_of_hour_id_enabled: boolean;
    id_start_second: number;
    id_seconds: number;
    playlists: Record<string, {
        avg_seconds: number;
        items: number;
        name: string;
        is_smart_block: boolean;
        main_type: string;
    }>;
    categories: Record<string, {avg_seconds: number; items: number}>;
    types: Record<string, {avg_seconds: number; items: number}>;
}

export interface ClockWheelFitEntry {
    position_seconds: number;
    type: string;
    duration_seconds: number | null;
    category_id: number | null;
    playlist_id?: number | null;
}

/** Used only until the library lengths have loaded. */
const FALLBACK_SECONDS: Record<string, number> = {music: 210, talk: 300, id: 30, legal_id: 30, promo: 60, ad: 60};

/** An entry may run this far into the next one; the swap/tempo fit absorbs it. */
const OVERRUN_TOLERANCE_SECONDS = 15;

/** Unused time after an entry worth pointing out. */
const GAP_WARNING_SECONDS = 90;

/** Library media type an entry draws from (ads live with promos). */
const libraryType = (type: string): string => {
    if (type === 'legal_id') {
        return 'id';
    }
    return type === 'ad' ? 'promo' : type;
};

const isShortForm = (type: string): boolean => type === 'promo' || type === 'ad';
const isId = (type: string): boolean => type === 'id' || type === 'legal_id';
const isMusic = (type: string): boolean => type === 'music';

/**
 * How long an entry will really run: its own length cap if set, else the
 * average of its playlist, else of its category, else of its type.
 */
export function estimateEntrySeconds(entry: ClockWheelFitEntry, lengths: ClockWheelSlotLengths | null): number {
    if (entry.duration_seconds != null && entry.duration_seconds > 0) {
        return entry.duration_seconds;
    }
    if (lengths) {
        if (entry.playlist_id != null) {
            const playlist = lengths.playlists[String(entry.playlist_id)];
            if (playlist && playlist.items > 0 && playlist.avg_seconds > 0) {
                return playlist.avg_seconds;
            }
        }
        if (entry.category_id != null) {
            const category = lengths.categories[String(entry.category_id)];
            if (category && category.items > 0) {
                return category.avg_seconds;
            }
        }
        const byType = lengths.types[libraryType(entry.type)];
        if (byType && byType.items > 0) {
            return byType.avg_seconds;
        }
    }
    return FALLBACK_SECONDS[entry.type] ?? 210;
}

/** Where the hour's content must end: the Top-of-Hour ID start, or :60:00. */
export function hourContentEnd(lengths: ClockWheelSlotLengths | null): number {
    return lengths?.top_of_hour_id_enabled ? lengths.id_start_second : CLOCK_WHEEL_HOUR_SECONDS;
}

export interface ClockWheelHourBudget {
    usedSeconds: number;
    availableSeconds: number;
    overSeconds: number;
    freeSeconds: number;
    roomForSongs: number;
}

/**
 * Total estimated content against the time the hour really has. The entries
 * are anchors, but each one is a whole item: if they add up to more than the
 * hour, something is dropped or cut by the ID no matter where they sit.
 */
export function getClockWheelHourBudget(
    entries: ClockWheelFitEntry[],
    lengths: ClockWheelSlotLengths | null,
): ClockWheelHourBudget {
    const availableSeconds = hourContentEnd(lengths);
    const usedSeconds = entries.reduce((sum, entry) => sum + estimateEntrySeconds(entry, lengths), 0);
    const freeSeconds = Math.max(0, availableSeconds - usedSeconds);
    const songSeconds = estimateEntrySeconds({position_seconds: 0, type: 'music', duration_seconds: null, category_id: null}, lengths);

    return {
        usedSeconds,
        availableSeconds,
        overSeconds: Math.max(0, usedSeconds - availableSeconds),
        freeSeconds,
        roomForSongs: songSeconds > 0 ? Math.floor(freeSeconds / songSeconds) : 0,
    };
}

const minutes = (seconds: number): string => {
    const s = Math.max(0, Math.round(seconds));
    if (s < 60) {
        return `${s} sec`;
    }
    const m = Math.floor(s / 60);
    const rest = s % 60;
    return rest === 0 ? `${m} min` : `${m} min ${rest} sec`;
};

/**
 * Problems that make an hour not work on air, in plain words with what to do
 * about it: an entry too short for what it plays, content running into the
 * Top-of-Hour ID, unused air, stacked promos and an ID that doubles the
 * station's own Top-of-Hour ID.
 */
export function getClockWheelFitWarnings(
    entries: ClockWheelFitEntry[],
    lengths: ClockWheelSlotLengths | null,
    gettext: (msg: string) => string,
): ClockWheelTimelineWarning[] {
    const warnings: ClockWheelTimelineWarning[] = [];
    const sorted = [...entries].sort((a, b) => a.position_seconds - b.position_seconds);
    const contentEnd = hourContentEnd(lengths);
    const tohOn = Boolean(lengths?.top_of_hour_id_enabled);
    const fill = (msg: string, values: Record<string, string>): string =>
        Object.entries(values).reduce((out, [key, value]) => out.replace(`%{${key}}`, value), msg);

    for (let i = 0; i < sorted.length; i++) {
        const entry = sorted[i];
        const next = sorted[i + 1];
        const index = entries.indexOf(entry);
        const length = estimateEntrySeconds(entry, lengths);
        const windowEnd = next ? next.position_seconds : contentEnd;
        const window = windowEnd - entry.position_seconds;

        if (tohOn && entry.position_seconds >= contentEnd && !isId(entry.type)) {
            warnings.push({
                index,
                message: gettext('This starts after :59:59, when the station ID is already playing, so it will never air. Remove it or move it earlier.'),
            });
            continue;
        }

        if (tohOn && isId(entry.type) && (entry.position_seconds < 60 || entry.position_seconds >= contentEnd - 60)) {
            warnings.push({
                index,
                message: gettext('The station already plays its ID automatically at :59:59, so this ID would play twice. You can remove this one.'),
            });
        }

        if (length > window + OVERRUN_TOLERANCE_SECONDS) {
            warnings.push({
                index,
                message: fill(
                    next
                        ? gettext('Not enough time: this gets %{window} but what it plays usually runs about %{length}. Move the next entry later, or click Fix my hour.')
                        : gettext('Not enough time before the :59:59 ID: this gets %{window} but usually runs about %{length}, so the ID would cut it off. Click Fix my hour.'),
                    {window: minutes(Math.max(0, window)), length: minutes(length)},
                ),
            });
        } else if (
            window - length > GAP_WARNING_SECONDS
            // The last song of the hour is swapped at air time for one that
            // ends on the :59:59 ID, so up to a song's length left over is fine.
            && !(!next && tohOn && isMusic(entry.type) && window <= 2 * length + OVERRUN_TOLERANCE_SECONDS)
        ) {
            warnings.push({
                index,
                message: fill(
                    next
                        ? gettext('About %{gap} of extra time after this. Add a song after it, or click Fix my hour.')
                        : gettext('About %{gap} left empty before the :59:59 ID. Add a song at the end, or click Fix my hour.'),
                    {gap: minutes(window - length)},
                ),
            });
        }

        if (next && isShortForm(entry.type) && isShortForm(next.type)) {
            const third = sorted[i + 2];
            if (third && isShortForm(third.type) && !(i > 0 && isShortForm(sorted[i - 1].type))) {
                warnings.push({
                    index,
                    message: gettext('Three or more promos/ads play back to back here. Listeners may tune out; spread them out, or click Fix my hour.'),
                });
            }
        }
    }

    return warnings;
}

// ---------------------------------------------------------------------------
// Fix my hour / Build one for me
// ---------------------------------------------------------------------------

export interface ClockWheelFixResult {
    entries: ClockWheelSlotEditorRow[];
    changes: string[];
}

const cloneRow = (row: ClockWheelSlotEditorRow, position: number): ClockWheelSlotEditorRow => ({
    ...row,
    position_seconds: Math.max(0, Math.min(CLOCK_WHEEL_HOUR_SECONDS - 1, Math.round(position))),
});

/**
 * Re-lays a wheel so it fills the hour cleanly, keeping what the user put in:
 * non-music entries (IDs, promos, ads, talk) stay in order at about the same
 * time; music entries are re-spaced between them using real song lengths,
 * with music added or removed so the hour ends right at the :59:59 ID; a
 * third promo in a row is moved after the next song, and an ID that doubles
 * the automatic Top-of-Hour ID is removed. Nothing is saved: the caller shows
 * the result and the user applies it.
 */
export function fixClockWheelHour(
    rows: ClockWheelSlotEditorRow[],
    lengths: ClockWheelSlotLengths | null,
    gettext: (msg: string) => string,
): ClockWheelFixResult {
    const changes: string[] = [];
    const contentEnd = hourContentEnd(lengths);
    const tohOn = Boolean(lengths?.top_of_hour_id_enabled);
    const sorted = [...rows].sort((a, b) => a.position_seconds - b.position_seconds);
    const len = (row: ClockWheelSlotEditorRow) => estimateEntrySeconds(row, lengths);

    // Music choices in the order the user had them, to keep the wheel's mix.
    let musicRows = sorted.filter((r) => isMusic(r.type));
    const fallbackMusic = defaultClockWheelSlotEditorRow(0);
    if (musicRows.length === 0) {
        musicRows = [fallbackMusic];
    }

    let fixed = sorted.filter((r) => !isMusic(r.type));

    if (tohOn) {
        const before = fixed.length;
        fixed = fixed.filter((r) => !(isId(r.type) && (r.position_seconds < 60 || r.position_seconds >= contentEnd - 60)));
        if (fixed.length < before) {
            changes.push(gettext('Removed an extra ID: the station already plays one at :59:59.'));
        }
        const late = fixed.filter((r) => r.position_seconds >= contentEnd);
        if (late.length > 0) {
            fixed = fixed.filter((r) => r.position_seconds < contentEnd);
            changes.push(gettext('Removed entries placed after :59:59; they could never air.'));
        }
    }

    // How many songs fit: the music picks in order, until the next one would
    // run past the ID. What is left over is less than one song, which the
    // end-of-hour swap fills with a song that ends on the ID at air time.
    const fixedSeconds = fixed.reduce((sum, row) => sum + len(row), 0);
    const musicBudget = contentEnd - fixedSeconds;
    let songCount = 0;
    for (let used = 0; ; songCount++) {
        const next = len(musicRows[songCount % musicRows.length]);
        if (used + next > musicBudget) {
            break;
        }
        used += next;
    }

    // Lay the hour out in order with no gaps: songs run back to back, and each
    // non-music entry goes in at the song break nearest the time it had.
    const out: ClockWheelSlotEditorRow[] = [];
    let t = 0;
    let musicIndex = 0;
    let songsLeft = songCount;
    let shortRun = 0;
    let brokeStack = false;

    const placeSong = () => {
        const pick = musicRows[musicIndex % musicRows.length];
        out.push(cloneRow(pick, t));
        t += len(pick);
        musicIndex++;
        songsLeft--;
        shortRun = 0;
    };

    for (const row of fixed) {
        while (songsLeft > 0 && t + len(musicRows[musicIndex % musicRows.length]) / 2 <= row.position_seconds) {
            placeSong();
        }
        // No more than two promos/ads back to back.
        if (isShortForm(row.type) && shortRun >= 2 && songsLeft > 0) {
            placeSong();
            brokeStack = true;
        }
        out.push(cloneRow(row, t));
        t += len(row);
        shortRun = isShortForm(row.type) ? shortRun + 1 : 0;
    }
    while (songsLeft > 0) {
        placeSong();
    }

    if (brokeStack) {
        changes.push(gettext('Put a song between promos so no more than two play back to back.'));
    }

    let songsAdded = 0;
    let songsRemoved = 0;
    const originalMusicCount = sorted.filter((r) => isMusic(r.type)).length;

    const newMusicCount = out.filter((r) => isMusic(r.type)).length;
    if (newMusicCount > originalMusicCount) {
        songsAdded = newMusicCount - originalMusicCount;
    } else {
        songsRemoved = originalMusicCount - newMusicCount;
    }
    if (songsAdded > 0) {
        changes.push(gettext('Added %{n} song slot(s) to fill the hour.').replace('%{n}', String(songsAdded)));
    }
    if (songsRemoved > 0) {
        changes.push(gettext('Removed %{n} song slot(s) that did not fit before the :59:59 ID.').replace('%{n}', String(songsRemoved)));
    }
    changes.push(gettext('Spaced every entry by real song lengths so the hour ends at the :59:59 ID.'));

    return {entries: out, changes};
}

export interface ClockWheelBuildOptions {
    /** The music every song slot plays from (a "What plays here" row). */
    music: ClockWheelSlotEditorRow;
    promoBreaks: number;
    promosPerBreak: number;
    /** Station ID at the top of the hour, when the station has no automatic one. */
    idAtTop: boolean;
}

/**
 * Builds a whole wheel from a few plain choices: promo breaks spread evenly
 * through the hour, then the same fill as Fix my hour puts real-length
 * songs around them up to the :59:59 ID.
 */
export function buildClockWheelHour(
    options: ClockWheelBuildOptions,
    lengths: ClockWheelSlotLengths | null,
    gettext: (msg: string) => string,
): ClockWheelFixResult {
    const contentEnd = hourContentEnd(lengths);
    const rows: ClockWheelSlotEditorRow[] = [];
    const typed = (type: MediaTypeValue, position: number): ClockWheelSlotEditorRow => ({
        ...defaultClockWheelSlotEditorRow(position),
        type,
    });

    if (options.idAtTop && !lengths?.top_of_hour_id_enabled) {
        rows.push(typed('id', 0));
    }

    const spread = (count: number, offset: number): number[] =>
        Array.from({length: count}, (_, i) => Math.round(((i + offset) * contentEnd) / (count + 1)));

    for (const at of spread(options.promoBreaks, 1)) {
        for (let p = 0; p < Math.max(1, options.promosPerBreak); p++) {
            rows.push(typed('promo', at + p));
        }
    }
    rows.push(cloneRow(options.music, 1));

    return fixClockWheelHour(rows, lengths, gettext);
}
