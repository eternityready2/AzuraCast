import {normalizeMediaTypeForEditor, type MediaTypeValue} from '~/functions/mediaTypes.ts';

/**
 * Slot row shape for the Eternity Ready clock wheel editor (commercial-FM columns removed from UI).
 * Backend still accepts legacy fields; we always send cleared values on save.
 */
export interface ClockWheelSlotEditorRow {
    type: MediaTypeValue;
    algorithm: string;
    position_seconds: number;
    duration_seconds: number | null;
    category_id: number | null;
    separation_override_enabled: boolean;
    separation_artist_minutes: number | null;
    separation_title_minutes: number | null;
}

/** Legacy slot fields cleared when loading or saving from the simplified editor. */
export const CLOCK_WHEEL_SLOT_LEGACY_CLEARED = {
    playlist_id: null,
    pool_mode: 'restrict_pool',
    is_hard_anchor: false,
    research_score: null,
    sound_code: null,
} as const;

type ApiSlotInput = {
    type?: string | null;
    algorithm?: string;
    position_seconds?: number;
    duration_seconds?: number | null;
    category_id?: number | null;
    separation_override_enabled?: boolean;
    separation_artist_minutes?: number | null;
    separation_title_minutes?: number | null;
};

export function normalizeSlotType(type: string | null | undefined): MediaTypeValue {
    return normalizeMediaTypeForEditor(type);
}

export function mapApiSlotToEditorRow(slot: ApiSlotInput): ClockWheelSlotEditorRow {
    return {
        type: normalizeSlotType(slot.type),
        algorithm: slot.algorithm ?? 'random',
        position_seconds: slot.position_seconds ?? 0,
        duration_seconds: slot.duration_seconds ?? null,
        category_id: slot.category_id ?? null,
        separation_override_enabled: Boolean(slot.separation_override_enabled),
        separation_artist_minutes: slot.separation_artist_minutes ?? null,
        separation_title_minutes: slot.separation_title_minutes ?? null,
    };
}

export function mapEditorRowToApiSlot(row: ClockWheelSlotEditorRow): Record<string, unknown> {
    return {
        type: row.type,
        category_id: row.category_id,
        algorithm: row.algorithm,
        position_seconds: row.position_seconds,
        duration_seconds: row.duration_seconds,
        separation_override_enabled: row.separation_override_enabled,
        separation_artist_minutes: row.separation_override_enabled
            ? row.separation_artist_minutes
            : null,
        separation_title_minutes: row.separation_override_enabled
            ? row.separation_title_minutes
            : null,
        ...CLOCK_WHEEL_SLOT_LEGACY_CLEARED,
    };
}

export function defaultClockWheelSlotEditorRow(positionSeconds: number): ClockWheelSlotEditorRow {
    return {
        type: 'music',
        algorithm: 'random',
        position_seconds: Math.min(3599, Math.max(0, positionSeconds)),
        duration_seconds: null,
        category_id: null,
        separation_override_enabled: false,
        separation_artist_minutes: null,
        separation_title_minutes: null,
    };
}
