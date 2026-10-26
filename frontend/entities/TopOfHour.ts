export interface TopOfHourCompliance {
    tolerance_seconds: number;
    hours_with_legal_id: number;
    on_time_count: number;
    late_count: number;
    compliance_percent: number | null;
    fallback_count: number;
}

export interface TopOfHourLandingAired {
    at: string;
    title: string;
    seconds: number;
    is_id: boolean;
}

export interface TopOfHourLandingFailure {
    id_at: string;
    title: string;
    kind: 'cut_short' | 'cut_late_start' | 'cut_too_long' | 'ended_early' | null;
    seconds: number;
    resumed: 'after_cut' | 'replayed' | null;
    id_offset_seconds: number;
    late_start_seconds: number;
    under_id: boolean;
    promo_stack: boolean;
    aired: TopOfHourLandingAired[];
}

export interface TopOfHourLandingWorstHour {
    hour: number;
    music_hours: number;
    missed: number;
}

export interface TopOfHourLandingCutSong {
    title: string;
    count: number;
}

export interface TopOfHourLandingDay {
    date: string;
    music_hours: number;
    clean_count: number;
    clean_percent: number | null;
    id_early_count: number;
    id_late_count: number;
}

export interface TopOfHourLanding {
    start: string;
    end: string;
    days?: number;
    target_percent: number;
    id_hours: number;
    music_hours: number;
    show_hours: number;
    clean_count: number;
    clean_percent: number | null;
    previous_music_hours: number;
    previous_clean_percent: number | null;
    cut_count: number;
    cut_percent: number | null;
    early_count: number;
    early_percent: number | null;
    resumed_after_cut_count: number;
    resumed_after_cut_percent: number | null;
    replayed_count: number;
    replayed_percent: number | null;
    swap_hours: number;
    swap_clean_count: number;
    swap_clean_percent: number | null;
    tempo_hours: number;
    tempo_clean_count: number;
    tempo_clean_percent: number | null;
    id_early_count: number;
    id_early_percent: number | null;
    id_early_show_count: number;
    id_late_count: number;
    id_late_percent: number | null;
    after_id_hours: number;
    late_start_count: number;
    late_start_percent: number | null;
    under_id_count: number;
    under_id_percent: number | null;
    promo_stack_count: number;
    promo_stack_percent: number | null;
    streak_current: number;
    streak_best: number;
    id_average_offset_seconds: number | null;
    id_worst_offset_seconds: number;
    after_id_gap_hours: number;
    after_id_average_gap_seconds: number | null;
    after_id_worst_gap_seconds: number;
    cut_songs: TopOfHourLandingCutSong[];
    daily: TopOfHourLandingDay[];
    worst_hours: TopOfHourLandingWorstHour[];
    failures: TopOfHourLandingFailure[];
}

export interface TopOfHourMediaSummary {
    id: number;
    title: string | null;
    artist: string | null;
}

export interface TopOfHourNextPlan {
    mode: 'hard_toh' | 'soft_etm';
    boundary_at: string;
    target_start_at: string;
    duration_seconds: number;
    rigid_zero_event: boolean;
    seconds_available_before_boundary: number;
    will_be_cut_at_boundary: boolean;
    recommended_start_second: number;
    media: TopOfHourMediaSummary;
}

export interface TopOfHourStagingStatus {
    is_staged: boolean;
    queue_id: number | null;
}

export interface TopOfHourSettings {
    top_of_hour_id_enabled: boolean;
    top_of_hour_lookahead_minutes: number;
    top_of_hour_compliance_tolerance_seconds: number;
    top_of_hour_id_max_seconds: number;
    top_of_hour_id_start_second: number;
    top_of_hour_id_start_minute: number;
    top_of_hour_id_fade_seconds: number;
    top_of_hour_swap_enabled: boolean;
    top_of_hour_swap_tolerance_seconds: number;
    top_of_hour_swap_min_gap_seconds: number;
    configured_start_label: string;
    id_media_count: number;
    compliance?: TopOfHourCompliance;
    next: TopOfHourNextPlan | null;
    staging: TopOfHourStagingStatus;
    engine: 'wall_clock_runtime';
}

export interface TopOfHourForm {
    top_of_hour_id_enabled: boolean;
    top_of_hour_lookahead_minutes: number;
    top_of_hour_compliance_tolerance_seconds: number;
    top_of_hour_id_max_seconds: number;
    top_of_hour_id_start_second: number;
    top_of_hour_id_start_minute: number;
    top_of_hour_id_fade_seconds: number;
    top_of_hour_swap_enabled: boolean;
    top_of_hour_swap_tolerance_seconds: number;
    top_of_hour_swap_min_gap_seconds: number;
}
