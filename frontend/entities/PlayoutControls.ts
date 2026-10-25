export interface PlayoutControlsSettings {
    stretch_squeeze_enabled: boolean;
    /** @deprecated Superseded by stretch_max_percent / squeeze_max_percent. */
    stretch_squeeze_max_percent: number;
    stretch_max_percent: number;
    squeeze_max_percent: number;
    smart_duck_enabled: boolean;
    smart_duck_attenuation: number;
    smart_duck_delay: number;
}
