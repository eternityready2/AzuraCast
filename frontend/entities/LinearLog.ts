export type LinearLogStatus = "idle" | "queued" | "building" | "ready" | "failed";

export interface LinearLogItem {
    id: string;
    queue_id: number;
    song_id: string;
    played_at: number | null;
    cued_at: number;
    duration: number;
    title: string | null;
    artist: string | null;
    album: string | null;
    text: string | null;
    playlist: string | null;
    playlist_id: number | null;
    playlist_chain: string[] | null;
    clock_wheel: string | null;
    clock_wheel_id: number | null;
    media_type: string;
    source_type: string;
    is_request: boolean;
    is_live_queue: boolean;
    sent_to_autodj: boolean;
    top_of_hour_legal_id: boolean;
    autodj_custom_uri: string | null;
    clock_wheel_schedule_mode: string | null;
    clock_wheel_enforce_cap: boolean;
    clock_wheel_stretch_ratio: number | null;
    clock_wheel_legal_id_substitute: boolean;
    hour_boundary_enforce_cap: boolean;
    hour_boundary_max_play_seconds: number | null;
    top_of_hour_pre_id_fade: boolean;
    // Saved linear log (only when the log controls playout).
    log_entry_id?: number | null;
    log_status?: "planned" | "queued" | "aired" | "swapped" | "replaced" | "dropped" | null;
    log_note?: string | null;
    aired_at?: number | null;
    is_locked?: boolean;
    timing?: 'hard' | 'soft';
    timing_reason?: string | null;
    // Hand edits on this line, newest first, and whether Undo can still put it back.
    hand_edits?: LinearLogHandEdit[];
    can_undo?: boolean;
}

export interface LinearLogHandEdit {
    edit: "lock" | "unlock" | "up" | "down" | "remove" | "replace" | "undo";
    at: number;
    // What the line was before the edit, where that says something.
    was: string | null;
    undone: boolean;
}

export interface LinearLogAlert {
    type: "hole" | "short_hour" | "autodj";
    level: "danger" | "warning";
    at: number;
    seconds: number;
    message: string;
}

export interface LinearLogGap {
    started_at: number;
    duration: number;
    reason: string;
}

export interface LinearLogAiDjShift {
    schedule_id: number;
    schedule_name: string;
    dj_id: number;
    dj_name: string;
    starts_at: number;
    ends_at: number;
}

export interface LinearLogResponse {
    status: LinearLogStatus;
    enabled: boolean;
    playout_enabled?: boolean;
    hours: number;
    configured_hours: number;
    built_at: number | null;
    coverage_start: number | null;
    coverage_end: number | null;
    entries: LinearLogItem[];
    gaps: LinearLogGap[];
    ai_dj_shifts: LinearLogAiDjShift[];
    alerts?: LinearLogAlert[];
    error: string | null;
}

export interface LinearLogHourGroup {
    epochHour: number;
    label: string;
    isCurrent: boolean;
    items: LinearLogItem[];
    // Lines that air: a dropped line is listed, but not counted or timed.
    airableCount: number;
    totalDurationFormatted: string;
    hasId: boolean;
    // Saved lines in this hour that can still be locked, and whether all of them are.
    lockableIds: number[];
    allLocked: boolean;
}

export interface LinearLogMediaOption {
    id: number;
    title: string | null;
    artist: string | null;
    text: string | null;
    length: number;
    type: string;
}
