import {useTranslate} from "~/vendor/gettext.ts";
import {filterMenu, MenuCategory, RawMenuCategory} from "~/functions/filterMenu.ts";
import {shallowRef, watch} from "vue";
import {StationPermissions} from "~/entities/ApiInterfaces.ts";
import {useStationData} from "~/functions/useStationQuery.ts";
import IconIcCode from "~icons/ic/baseline-code";
import IconIcImage from "~icons/ic/baseline-image";
import IconIcLibraryMusic from "~icons/ic/baseline-library-music";
import IconIcAssignment from "~icons/ic/baseline-assignment";
import IconIcMic from "~icons/ic/baseline-mic";
import IconIcQueueMusic from "~icons/ic/baseline-queue-music";
import IconIcAutoAwesome from "~icons/ic/baseline-auto-awesome";
import IconIcPodcasts from "~icons/ic/baseline-podcasts";
import IconIcPublic from "~icons/ic/baseline-public";
import IconIcInsertChart from "~icons/ic/baseline-insert-chart";
import IconIcSettingsApplication from "~icons/ic/baseline-settings-applications";
import IconIcPsychology from "~icons/ic/baseline-psychology";
import IconBiBroadcast from "~icons/bi/broadcast";
import IconIcSchedule from "~icons/ic/baseline-schedule";
import IconIcGraphicEq from "~icons/ic/baseline-graphic-eq";
import IconIcCategory from "~icons/ic/baseline-category";
import IconIcList from "~icons/ic/baseline-list";
import IconIcShield from "~icons/ic/baseline-shield";
import {useUserAllowedForStation} from "~/functions/useUserallowedForStation.ts";

export function useStationsMenu() {
    const {$gettext} = useTranslate();

    const station = useStationData();
    const {userAllowedForStation} = useUserAllowedForStation();

    const fullMenu: RawMenuCategory[] = [
        {
            key: 'profile',
            label: $gettext('Overview'),
            icon: () => IconIcImage,
            url: {
                name: 'stations:index'
            }
        },
        {
            key: 'edit_profile',
            label: $gettext('Edit Station Settings'),
            icon: () => IconIcSettingsApplication,
            url: {
                name: 'stations:settings:index'
            },
            visible: () => userAllowedForStation(StationPermissions.Profile)
        },
        {
            key: 'public_page',
            label: $gettext('Public Pages'),
            icon: () => IconIcPublic,
            items: [
                {
                    key: 'branding',
                    label: $gettext('Branding'),
                    url: {
                        name: 'stations:branding'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Profile)
                },
                {
                    key: 'public_player',
                    label: $gettext('Public Player'),
                    url: station.value.publicPageUrl,
                    external: true,
                    visible: () => station.value.enablePublicPages,
                },
                {
                    key: 'public_on_demand',
                    label: $gettext('On-Demand Media'),
                    url: station.value.onDemandUrl,
                    external: true,
                    visible: () => station.value.enablePublicPages && station.value.enableOnDemand,
                },
                {
                    key: 'public_podcasts',
                    label: $gettext('Podcasts'),
                    url: station.value.publicPodcastsUrl,
                    external: true,
                    visible: () => station.value.enablePublicPages,
                },
                {
                    key: 'public_schedule',
                    label: $gettext('Schedule'),
                    url: station.value.publicScheduleUrl,
                    external: true,
                    visible: () => station.value.enablePublicPages,
                },
            ]
        },
        {
            key: 'media',
            label: $gettext('Media'),
            icon: () => IconIcLibraryMusic,
            visible: () => station.value.features.media,
            items: [
                {
                    key: 'music_files',
                    label: $gettext('Music Files'),
                    url: {
                        name: 'stations:files:index'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Media)
                },
                {
                    key: 'duplicate_songs',
                    label: $gettext('Duplicate Songs'),
                    url: {
                        name: 'stations:files:index',
                        params: {
                            path: 'special:duplicates'
                        }
                    },
                    visible: () => userAllowedForStation(StationPermissions.Media)
                },
                {
                    key: 'unprocessable',
                    label: $gettext('Unprocessable Files'),
                    url: {
                        name: 'stations:files:index',
                        params: {
                            path: 'special:unprocessable'
                        }
                    },
                    visible: () => userAllowedForStation(StationPermissions.Media)
                },
                {
                    key: 'unassigned',
                    label: $gettext('Unassigned Files'),
                    url: {
                        name: 'stations:files:index',
                        params: {
                            path: 'special:unassigned'
                        }
                    },
                    visible: () => userAllowedForStation(StationPermissions.Media)
                },
                {
                    key: 'ondemand',
                    label: $gettext('On-Demand Media'),
                    url: station.value.onDemandUrl,
                    external: true,
                    visible: () => station.value.enableOnDemand,
                },
                {
                    key: 'sftp_users',
                    label: $gettext('SFTP Users'),
                    url: {
                        name: 'stations:sftp_users:index'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Media)
                        && station.value.features.sftp,
                },
                {
                    key: 'bulk_media',
                    label: $gettext('Bulk Media Import/Export'),
                    url: {
                        name: 'stations:bulk-media'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Media)
                }
            ]
        },
        {
            key: 'schedule',
            label: $gettext('Schedule'),
            icon: () => IconIcSchedule,
            url: {
                name: 'stations:schedule:index'
            },
            visible: () => userAllowedForStation(StationPermissions.Media)
                && station.value.features.media,
        },
        {
            key: 'playlists',
            label: $gettext('Playlists & More'),
            icon: () => IconIcQueueMusic,
            visible: () => userAllowedForStation(StationPermissions.Media)
                && station.value.features.media,
            items: [
                {
                    key: 'playlists_index',
                    label: $gettext('Playlists'),
                    url: {
                        name: 'stations:playlists:index'
                    },
                },
                {
                    key: 'clock_wheels_sub',
                    label: $gettext('Clock Wheels'),
                    url: {
                        name: 'stations:clock_wheels:index'
                    },
                },
                {
                    key: 'smart_blocks_sub',
                    label: $gettext('Smart Blocks'),
                    url: {
                        name: 'stations:smart-blocks:index'
                    },
                },
                {
                    key: 'shows_sub',
                    label: $gettext('Shows'),
                    url: {
                        name: 'stations:shows:index'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Broadcasting),
                },
                {
                    key: 'web_streams_sub',
                    label: $gettext('Web / Remote Streams'),
                    url: {
                        name: 'stations:web_streams:index'
                    },
                },
            ]
        },
        {
            key: 'podcasts',
            label: $gettext('Podcasts & RSS'),
            icon: () => IconIcPodcasts,
            url: {
                name: 'stations:podcasts:index'
            },
            visible: () => userAllowedForStation(StationPermissions.Podcasts)
                && station.value.features.podcasts,
        },
        {
            key: 'media_categories',
            label: $gettext('Categories'),
            icon: () => IconIcCategory,
            url: {
                name: 'stations:media_categories:index'
            },
        },
        {
            key: 'streaming',
            label: $gettext('Live Streaming'),
            icon: () => IconIcMic,
            visible: () => userAllowedForStation(StationPermissions.Streamers)
                && station.value.features.streamers,
            items: [
                {
                    key: 'streamers',
                    label: $gettext('Streamer/DJ Accounts'),
                    url: {
                        name: 'stations:streamers:index',
                    },
                    visible: () => userAllowedForStation(StationPermissions.Streamers)
                },
                {
                    key: 'webdj',
                    label: $gettext('Web DJ'),
                    url: station.value?.webDjUrl,
                    external: true,
                    visible: () => station.value.enablePublicPages
                }
            ]
        },
        {
            key: 'webhooks',
            label: $gettext('Web Hooks'),
            icon: () => IconIcCode,
            url: {
                name: 'stations:webhooks:index'
            },
            visible: () => userAllowedForStation(StationPermissions.WebHooks)
                && station.value.features.webhooks,
        },
        {
            key: 'station_analytics',
            label: $gettext('Station Analytics'),
            icon: () => IconIcInsertChart,
            visible: () => userAllowedForStation(StationPermissions.Reports),
            items: [
                {
                    key: 'reports_overview',
                    label: $gettext('Station Statistics'),
                    url: {
                        name: 'stations:reports:overview',
                    }
                },
                {
                    key: 'reports_listeners',
                    label: $gettext('Listeners'),
                    url: {
                        name: 'stations:reports:listeners'
                    }
                },
                {
                    key: 'reports_timeline',
                    label: $gettext('Song Playback Timeline'),
                    url: {
                        name: 'stations:reports:timeline'
                    }
                },
                {
                    key: 'reports_sponsor_plays',
                    label: $gettext('Sponsor Play Report'),
                    url: {
                        name: 'stations:sponsor_plays'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Broadcasting)
                },
            ]
        },
        {
            key: 'music_licensing',
            label: $gettext('Music Licensing'),
            icon: () => IconIcShield,
            visible: () => userAllowedForStation(StationPermissions.Reports),
            items: [
                {
                    key: 'dmca_compliance',
                    label: $gettext('DMCA Compliance'),
                    url: {
                        name: 'stations:dmca_compliance'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Broadcasting)
                },
                {
                    key: 'reports_soundexchange',
                    label: $gettext('SoundExchange Royalties'),
                    url: {
                        name: 'stations:reports:soundexchange'
                    }
                },
                {
                    key: 'reports_ppca',
                    label: $gettext('PPCA Report'),
                    url: {
                        name: 'stations:reports:ppca'
                    }
                },
                {
                    key: 'reports_ppl',
                    label: $gettext('PPL Report'),
                    url: {
                        name: 'stations:reports:ppl'
                    }
                },
                {
                    key: 'reports_cadence',
                    label: $gettext('NPR Cadence Streaming Report'),
                    url: {
                        name: 'stations:reports:cadence'
                    }
                },
            ]
        },
        {
            key: 'reports_requests',
            label: $gettext('Song Requests'),
            icon: () => IconIcQueueMusic,
            url: {
                name: 'stations:reports:requests'
            },
            visible: () => userAllowedForStation(StationPermissions.Broadcasting)
                && station.value.enableRequests
        },
        {
            key: 'ai',
            label: $gettext('AI Studio'),
            icon: () => IconIcPsychology,
            visible: () => userAllowedForStation(StationPermissions.Broadcasting),
            items: [
                {
                    key: 'ai_news',
                    label: $gettext('AI News'),
                    url: {
                        name: 'stations:ai_news'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Broadcasting)
                },
                {
                    key: 'ai_dj',
                    label: $gettext('AI DJ\'s'),
                    url: {
                        name: 'stations:ai_dj'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Broadcasting)
                },
                {
                    key: 'ai_assistant',
                    label: $gettext('AI Assistant'),
                    url: {
                        name: 'stations:assistant:index'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Broadcasting)
                },
            ]
        },
        {
            key: 'linear_log',
            label: $gettext('Future Playout Queue'),
            icon: () => IconIcList,
            url: {
                name: 'stations:reports:linear-log',
            },
            visible: () => userAllowedForStation(StationPermissions.Broadcasting)
                && station.value.features.autoDjQueue
        },
        {
            key: 'top_of_hour',
            label: $gettext('Top of Hour ID'),
            icon: () => IconIcSchedule,
            url: {
                name: 'stations:top_of_hour'
            },
            visible: () => userAllowedForStation(StationPermissions.Broadcasting)
        },
        {
            key: 'playout_controls',
            label: $gettext('Playout Controls'),
            icon: () => IconIcGraphicEq,
            url: {
                name: 'stations:playout_controls'
            },
            visible: () => userAllowedForStation(StationPermissions.Broadcasting)
        },
        {
            key: 'broadcasting',
            label: $gettext('Broadcasting'),
            icon: () => IconBiBroadcast,
            items: [
                {
                    key: 'mounts',
                    label: $gettext('Mount Points'),
                    url: {
                        name: 'stations:mounts:index',
                    },
                    visible: () => userAllowedForStation(StationPermissions.MountPoints)
                        && station.value.features.mountPoints
                },
                {
                    key: 'hls_streams',
                    label: $gettext('HLS Streams'),
                    url: {
                        name: 'stations:hls_streams:index',
                    },
                    visible: () => userAllowedForStation(StationPermissions.MountPoints)
                        && station.value.features.hlsStreams
                },
                {
                    key: 'remotes',
                    label: $gettext('Remote Relays'),
                    url: {
                        name: 'stations:remotes:index',
                    },
                    visible: () => userAllowedForStation(StationPermissions.RemoteRelays)
                        && station.value.features.remoteRelays
                },
                {
                    key: 'fallback',
                    label: $gettext('Custom Fallback File'),
                    url: {
                        name: 'stations:fallback'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Broadcasting)
                        && station.value.features.media
                },
                {
                    key: 'ls_config',
                    label: $gettext('Edit Liquidsoap Configuration'),
                    url: {
                        name: 'stations:util:ls_config'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Broadcasting)
                        && station.value.features.customLiquidsoapConfig
                },
                {
                    key: 'stereo_tool',
                    label: $gettext('Upload Stereo Tool Configuration'),
                    url: {
                        name: 'stations:stereo_tool_config'
                    },
                    visible: () => userAllowedForStation(StationPermissions.Broadcasting)
                        && station.value.features.media
                },
            ]
        },
        {
            key: 'logs_diag',
            label: $gettext('Logs & Diag'),
            icon: () => IconIcAssignment,
            url: {
                name: 'stations:logs_diag'
            },
            visible: () => userAllowedForStation(StationPermissions.Logs)
        }
    ];

    const menu = shallowRef<MenuCategory[]>([]);

    watch(
        station,
        () => {
            menu.value = filterMenu(fullMenu);
        },
        {
            immediate: true
        }
    );

    return menu;
}
