import type {ClockWheelSlotEditorRow} from '~/functions/clockWheelSlotEditor.ts';
import type {ClockWheelSlotLengths} from '~/functions/clockWheelHourFit.ts';
import {isMediaTypeValue, type MediaTypeValue} from '~/functions/mediaTypes.ts';

/**
 * A wheel entry is one of three kinds, each with its own dropdown:
 *  - content: "Type / Category", the type (with its description) or a music
 *    category, one choice instead of separate Type and Category fields that
 *    could contradict each other. Keys: "type:<type>", "cat:<categoryId>".
 *  - playlist / smart_block: added with "+ Playlist" / "+ Smart block"; the
 *    dropdown lists only that kind. Key: "pl:<playlistId>".
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
export function clockWheelContentKey(row: Pick<ClockWheelSlotEditorRow, 'type' | 'category_id'>): string {
    return row.category_id ? `cat:${row.category_id}` : `type:${row.type}`;
}

export function clockWheelPlaylistKey(row: Pick<ClockWheelSlotEditorRow, 'playlist_id'>): string {
    return row.playlist_id ? `pl:${row.playlist_id}` : '';
}

export type ClockWheelSlotKind = 'content' | 'playlist' | 'smart_block';

export function clockWheelSlotKind(
    row: Pick<ClockWheelSlotEditorRow, 'playlist_id'>,
    lengths: ClockWheelSlotLengths | null,
): ClockWheelSlotKind {
    if (!row.playlist_id) {
        return 'content';
    }
    return lengths?.playlists[String(row.playlist_id)]?.is_smart_block ? 'smart_block' : 'playlist';
}

/** The playlists (with songs) or the smart blocks, for that kind of slot. */
export function clockWheelPlaylistOptions(
    lengths: ClockWheelSlotLengths | null,
    smart: boolean,
): ClockWheelContentOption[] {
    const asType = (t: string): MediaTypeValue => (isMediaTypeValue(t) ? t : 'music');
    return Object.entries(lengths?.playlists ?? {})
        .filter(([, p]) => (smart ? p.is_smart_block : !p.is_smart_block && p.items > 0))
        .map(([id, p]) => ({key: `pl:${id}`, label: p.name, type: smart ? 'music' : asType(p.main_type)}))
        .sort((a, b) => a.label.localeCompare(b.label));
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

/** Sets a slot's playlist or smart block ("" = none). */
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
