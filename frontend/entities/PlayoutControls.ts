export interface PlayoutControlsSettings {
    hard_clock_enabled: boolean;
    hard_clock_trigger_seconds: number;
    hard_clock_fade_seconds: number;
    stretch_squeeze_enabled: boolean;
    /** @deprecated Superseded by stretch_max_percent / squeeze_max_percent. */
    stretch_squeeze_max_percent: number;
    stretch_max_percent: number;
    squeeze_max_percent: number;
    smart_duck_enabled: boolean;
    smart_duck_attenuation: number;
    smart_duck_delay: number;
}
