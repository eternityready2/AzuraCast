import {PlaylistOrders, PlaylistRemoteTypes, PlaylistSources, PlaylistTypes} from "~/entities/ApiInterfaces.ts";

export interface ScheduleGroupMember {
    id: number;
    name: string;
    source: PlaylistSources;
    order: PlaylistOrders;
    weight: number;
    count: number | null;
    consecutive_plays: number;
    play_full_cycle: boolean;
    is_enabled: boolean;
}

interface SchedulePlaylistEvent {
    type: "playlist";
    source: PlaylistSources;
    order: PlaylistOrders;
    playlist_type: PlaylistTypes;
    play_per_songs: number;
    play_per_minutes: number;
    play_per_hour_minute: number;
    weight: number;
    is_jingle: boolean;
    include_in_on_demand: boolean;
    avoid_duplicates: boolean;
    num_songs?: number | null;
    total_length?: number | null;
    members?: ScheduleGroupMember[] | null;
    remote_url?: string | null;
    remote_type?: PlaylistRemoteTypes | null;
    plays_via_group_schedule?: boolean;
}

interface ScheduleStreamerEvent {
    type: "streamer";
    streamer_username: string;
    comments: string | null;
    has_custom_art: boolean;
    art: string | null;
}

// Event payloads from GET /playlists/schedule and GET /streamers/schedule, with the event title as "name".
export type ScheduleEventDetails = (SchedulePlaylistEvent | ScheduleStreamerEvent) & {
    name: string;
};
