import type {ClockWheelSlotEditorRow} from '~/functions/clockWheelSlotEditor.ts';
import type {ClockWheelSlotLengths} from '~/functions/clockWheelHourFit.ts';
import {isMediaTypeValue, type MediaTypeValue} from '~/functions/mediaTypes.ts';

/**
 * A wheel entry is set with two dropdowns:
 *  - "Type / Category": the type (with its description) or a music category,
 *    one choice instead of separate Type and Category fields that could
 *    contradict each other. Keys: "type:<type>", "cat:<categoryId>".
 *  - "Playlist / Smart block": optionally narrows that to one playlist or
 *    smart block. Key: "pl:<playlistId>", or "" for the whole library.
 */
export interface ClockWheelContentOption {
    key: string;
    label: string;
    type: MediaTypeValue;
}

export interface ClockWheelContentGroup {
    label: string;
    options: ClockWheelContentOption[];
}

export function buildClockWheelContentGroups(
    typeOptions: Array<{value: MediaTypeValue; label: string}>,
    categories: Array<{value: number | null; text: string}>,
    gettext: (msg: string) => string,
): ClockWheelContentGroup[] {
    const groups: ClockWheelContentGroup[] = [{
        label: gettext('Type'),
        options: typeOptions.map((opt) => ({key: `type:${opt.value}`, label: opt.label, type: opt.value})),
    }];

    const realCategories = categories.filter((c) => c.value !== null);
    if (realCategories.length > 0) {
        groups.push({
            label: gettext('Music category'),
            options: realCategories.map((c) => ({
                key: `cat:${c.value}`,
                label: gettext('Music – %{name}').replace('%{name}', c.text),
                type: 'music',
            })),
        });
    }

    return groups;
}

/** Playlist and smart block choices for the second dropdown. */
export function buildClockWheelPlaylistGroups(
    lengths: ClockWheelSlotLengths | null,
    gettext: (msg: string) => string,
): ClockWheelContentGroup[] {
    const asType = (t: string): MediaTypeValue => (isMediaTypeValue(t) ? t : 'music');
    const all = Object.entries(lengths?.playlists ?? {})
        .map(([id, p]) => ({id, ...p}))
        .sort((a, b) => a.name.localeCompare(b.name));

    const groups: ClockWheelContentGroup[] = [
        {
            label: gettext('Playlists'),
            options: all.filter((p) => !p.is_smart_block && p.items > 0)
                .map((p) => ({key: `pl:${p.id}`, label: p.name, type: asType(p.main_type)})),
        },
        {
            label: gettext('Smart blocks'),
            options: all.filter((p) => p.is_smart_block)
                .map((p) => ({key: `pl:${p.id}`, label: p.name, type: 'music'})),
        },
    ];
    return groups.filter((g) => g.options.length > 0);
}

export function clockWheelContentKey(row: Pick<ClockWheelSlotEditorRow, 'type' | 'category_id'>): string {
    return row.category_id ? `cat:${row.category_id}` : `type:${row.type}`;
}

export function clockWheelPlaylistKey(row: Pick<ClockWheelSlotEditorRow, 'playlist_id'>): string {
    return row.playlist_id ? `pl:${row.playlist_id}` : '';
}

/** Applies a "Type / Category" choice; the playlist choice is left as it is. */
export function applyClockWheelContentKey(row: ClockWheelSlotEditorRow, key: string): void {
    const [kind, value] = key.split(':');
    row.category_id = null;
    if (kind === 'cat') {
        row.type = 'music';
        row.category_id = Number(value);
    } else if (isMediaTypeValue(value)) {
        row.type = value;
    }
}

/** Applies a "Playlist / Smart block" choice ("" = whole library). */
export function applyClockWheelPlaylistKey(
    row: ClockWheelSlotEditorRow,
    key: string,
    lengths: ClockWheelSlotLengths | null,
): void {
    const id = key.startsWith('pl:') ? key.slice(3) : '';
    row.playlist_id = id ? Number(id) : null;
    // A smart block has no fixed list of files; it must use its own rotation.
    row.pool_mode = id && lengths?.playlists[id]?.is_smart_block ? 'playlist_rotation' : 'restrict_pool';
}

/** Short description of an entry, e.g. "Music – Praise · Hymns and Favorites". */
export function clockWheelContentLabel(
    row: ClockWheelSlotEditorRow,
    groups: ClockWheelContentGroup[],
    lengths: ClockWheelSlotLengths | null,
): string {
    const key = clockWheelContentKey(row);
    const label = groups.flatMap((g) => g.options).find((o) => o.key === key)?.label ?? row.type;
    const short = label.split(' (')[0];
    const playlist = row.playlist_id ? lengths?.playlists[String(row.playlist_id)]?.name : null;
    return playlist ? `${short} · ${playlist}` : short;
}
