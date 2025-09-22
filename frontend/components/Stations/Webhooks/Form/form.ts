import {WebhookTypes} from "~/entities/ApiInterfaces.ts";
import {useTranslate} from "~/vendor/gettext.ts";
import {ref} from "vue";
import {merge} from "es-toolkit/compat";
import {WebhookHooks, WebhookRecord, WebhookRecordCommon, WebhookRecordCommonMessages} from "~/entities/Webhooks.ts";
import {isValidHexColor, useAppRegle} from "~/vendor/regle.ts";
import {literal, required} from "@regle/rules";
import {defineStore} from "pinia";
import {createVariant} from "@regle/core";

export const useStationsWebhooksForm = defineStore(
    'form-stations-webhooks',
    () => {
        const {$gettext} = useTranslate();

        const type = ref<WebhookTypes | null>(null);

        const getBlankForm = (formType: WebhookTypes | null): WebhookRecord => {
            const commonConfig: WebhookRecordCommon = {
                name: '',
                triggers: [],
                config: {
                    rate_limit: 0,
                }
            };

            const defaultMessages: WebhookRecordCommonMessages = {
                message: $gettext(
                    'Now playing on %{station}: %{title} by %{artist}! Tune in now: %{url}',
                    {
                        station: '{{ station.name }}',
                        title: '{{ now_playing.song.title }}',
                        artist: '{{ now_playing.song.artist }}',
                        url: '{{ station.public_player_url }}'
                    }
                ),
                message_song_changed_live: $gettext(
                    'Now playing on %{station}: %{title} by %{artist} with your host, %{dj}! Tune in now: %{url}',
                    {
                        station: '{{ station.name }}',
                        title: '{{ now_playing.song.title }}',
                        artist: '{{ now_playing.song.artist }}',
                        dj: '{{ live.streamer_name }}',
                        url: '{{ station.public_player_url }}'
                    }
                ),
                message_live_connect: $gettext(
                    '%{dj} is now live on %{station}! Tune in now: %{url}',
                    {
                        dj: '{{ live.streamer_name }}',
                        station: '{{ station.name }}',
                        url: '{{ station.public_player_url }}'
                    }
                ),
                message_live_disconnect: $gettext(
                    'Thanks for listening to %{station}!',
                    {
                        station: '{{ station.name }}',
                    }
                ),
                message_station_offline: $gettext(
                    '%{station} is going offline for now.',
                    {
                        station: '{{ station.name }}'
                    }
                ),
                message_station_online: $gettext(
                    '%{station} is back online! Tune in now: %{url}',
                    {
                        station: '{{ station.name }}',
                        url: '{{ station.public_player_url }}'
                    }
                )
            };

            let config: WebhookHooks = {
                type: null
            };

            switch (formType) {
                case WebhookTypes.Generic:
                    config = {
                        type: WebhookTypes.Generic,
                        config: {
                            webhook_url: '',
                            basic_auth_username: '',
                            basic_auth_password: '',
                            timeout: 5,
                        }
                    };
                    break;

                case WebhookTypes.Bluesky:
                    config = {
                        type: WebhookTypes.Bluesky,
                        config: {
                            handle: '',
                            app_password: '',
                            ...defaultMessages
                        }
                    };
                    break;

                case WebhookTypes.Discord:
                    config = {
                        type: WebhookTypes.Discord,
                        config: {
                            webhook_url: '',
                            content: $gettext(
                                'Now playing on %{station}:',
                                {'station': '{{ station.name }}'}
                            ),
                            title: '{{ now_playing.song.title }}',
                            description: '{{ now_playing.song.artist }}',
                            url: '{{ station.listen_url }}',
                            author: '{{ live.streamer_name }}',
                            thumbnail: '{{ now_playing.song.art }}',
                            footer: $gettext('Powered by AzuraCast'),
                            color: '#2196F3',
                            include_timestamp: true
                        }
                    };
                    break;

                case WebhookTypes.Email:
                    config = {
                        type: WebhookTypes.Email,
                        config: {
                            to: '',
                            subject: '',
                            message: ''
                        }
                    };
                    break;

                case WebhookTypes.GetMeRadio:
                    config = {
                        type: WebhookTypes.GetMeRadio,
                        config: {
                            token: '',
                            station_id: '',
                        }
                    };
                    break;

                case WebhookTypes.GoogleAnalyticsV4:
                    config = {
                        type: WebhookTypes.GoogleAnalyticsV4,
                        config: {
                            api_secret: '',
                            measurement_id: ''
                        }
                    };
                    break;

                case WebhookTypes.GroupMe:
                    config = {
                        type: WebhookTypes.GroupMe,
                        config: {
                            bot_id: '',
                            api: '',
                            text: $gettext(
                                'Now playing on %{station}: %{title} by %{artist}! Tune in now.',
                                {
                                    station: '{{ station.name }}',
                                    title: '{{ now_playing.song.title }}',
                                    artist: '{{ now_playing.song.artist }}'
                                }
                            )
                        }
                    };
                    break;

                case WebhookTypes.Mastodon:
                    config = {
                        type: WebhookTypes.Mastodon,
                        config: {
                            instance_url: '',
                            access_token: '',
                            visibility: 'public',
                            ...defaultMessages
                        }
                    };
                    break;

                case WebhookTypes.MatomoAnalytics:
                    config = {
                        type: WebhookTypes.MatomoAnalytics,
                        config: {
                            matomo_url: '',
                            site_id: '',
                            token: ''
                        }
                    };
                    break;

                case WebhookTypes.RadioDe:
                    config = {
                        type: WebhookTypes.RadioDe,
                        config: {
                            broadcastsubdomain: '',
                            apikey: ''
                        }
                    };
                    break;

                case WebhookTypes.RadioReg:
                    config = {
                        type: WebhookTypes.RadioReg,
                        config: {
                            webhookurl: '',
                            apikey: ''
                        }
                    };
                    break;

                case WebhookTypes.Telegram:
                    config = {
                        type: WebhookTypes.Telegram,
                        config: {
                            bot_token: '',
                            chat_id: '',
                            api: '',
                            text: $gettext(
                                'Now playing on %{station}: %{title} by %{artist}! Tune in now.',
                                {
                                    station: '{{ station.name }}',
                                    title: '{{ now_playing.song.title }}',
                                    artist: '{{ now_playing.song.artist }}'
                                }
                            ),
                            parse_mode: 'Markdown'
                        }
                    };
                    break;

                case WebhookTypes.TuneIn:
                    config = {
                        type: WebhookTypes.TuneIn,
                        config: {
                            station_id: '',
                            partner_id: '',
                            partner_key: ''
                        }
                    };
                    break;
            }

            return merge(commonConfig, config);
        }

        const form = ref<WebhookRecord>(getBlankForm(null));

        const {r$} = useAppRegle(
            form,
            () => {
                const variant = createVariant(form, 'type', [
                    {
                        type: {
                            literal: literal(WebhookTypes.Generic),
                            required
                        },
                        config: {
                            webhook_url: {required},
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.Bluesky),
                            required
                        },
                        config: {
                            handle: {required},
                            app_password: {required}
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.Discord),
                            required
                        },
                        config: {
                            webhook_url: {required},
                            color: {isValidHexColor},
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.Email),
                            required
                        },
                        config: {
                            to: {required},
                            subject: {required},
                            message: {required}
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.GetMeRadio),
                            required
                        },
                        config: {
                            token: {required},
                            station_id: {required}
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.GoogleAnalyticsV4),
                            required
                        },
                        config: {
                            api_secret: {required},
                            measurement_id: {required}
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.GroupMe),
                            required
                        },
                        config: {
                            bot_id: {required},
                            text: {required}
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.Mastodon),
                            required
                        },
                        config: {
                            instance_url: {required},
                            access_token: {required},
                            visibility: {required}
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.MatomoAnalytics),
                            required
                        },
                        config: {
                            matomo_url: {required},
                            site_id: {required},
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.RadioDe),
                            required
                        },
                        config: {
                            broadcastsubdomain: {required},
                            apikey: {required}
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.RadioReg),
                            required
                        },
                        config: {
                            webhookurl: {required},
                            apikey: {required}
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.Telegram),
                            required
                        },
                        config: {
                            bot_token: {required},
                            chat_id: {required},
                            text: {required},
                            parse_mode: {required}
                        }
                    },
                    {
                        type: {
                            literal: literal(WebhookTypes.TuneIn),
                            required
                        },
                        config: {
                            station_id: {required},
                            partner_id: {required},
                            partner_key: {required},
                        }
                    },
                ]);

                return {
                    name: {required},
                    ...variant.value
                };
            },
            {
                validationGroups: (fields) => {
                    const messageFields = [
                        fields.config.message,
                        fields.config.message_song_changed_live,
                        fields.config.message_live_connect,
                        fields.config.message_live_disconnect,
                        fields.config.message_station_offline,
                        fields.config.message_station_online
                    ];

                    return {
                        basicInfoTab: [
                            fields.name,
                            fields.triggers,
                            fields.config.rate_limit,
                        ],
                        genericTab: [
                            fields.config.webhook_url,
                            fields.config.basic_auth_username,
                            fields.config.basic_auth_password,
                            fields.config.timeout,
                        ],
                        blueskyTab: [
                            fields.config.handle,
                            fields.config.app_password,
                            ...messageFields
                        ],
                        discordTab: [
                            fields.config.webhook_url,
                            fields.config.content,
                            fields.config.title,
                            fields.config.description,
                            fields.config.url,
                            fields.config.author,
                            fields.config.thumbnail,
                            fields.config.footer,
                            fields.config.color,
                            fields.config.include_timestamp
                        ],
                        emailTab: [
                            fields.config.to,
                            fields.config.subject,
                            fields.config.message
                        ],
                        getMeRadioTab: [
                            fields.config.token,
                            fields.config.station_id,
                        ],
                        googleAnalyticsV4Tab: [
                            fields.config.api_secret,
                            fields.config.measurement_id
                        ],
                        groupMeTab: [
                            fields.config.bot_id,
                            fields.config.api,
                            fields.config.text
                        ],
                        mastodonTab: [
                            fields.config.instance_url,
                            fields.config.access_token,
                            fields.config.visibility,
                            ...messageFields
                        ],
                        matomoAnalyticsTab: [
                            fields.config.matomo_url,
                            fields.config.site_id,
                            fields.config.token
                        ],
                        radioDeTab: [
                            fields.config.broadcastsubdomain,
                            fields.config.apikey
                        ],
                        radioRegTab: [
                            fields.config.webhookurl,
                            fields.config.apikey
                        ],
                        telegramTab: [
                            fields.config.bot_token,
                            fields.config.chat_id,
                            fields.config.api,
                            fields.config.text,
                            fields.config.parse_mode
                        ],
                        tuneInTab: [
                            fields.config.station_id,
                            fields.config.partner_id,
                            fields.config.partner_key
                        ]
                    };
                }
            }
        );

        const setType = (newType: WebhookTypes | null): void => {
            type.value = newType;

            r$.$reset({
                toState: getBlankForm(newType)
            });
        }

        const $reset = () => {
            setType(null);
        }

        return {
            type,
            setType,
            form,
            getBlankForm,
            r$,
            $reset
        }
    }
);
