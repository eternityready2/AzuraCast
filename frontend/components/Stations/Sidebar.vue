<template>
    <div class="navdrawer-header offcanvas-header">
        <router-link
            :to="{ name: 'stations:index' }"
            class="navbar-brand"
        >
            {{ name }}
            <div
                id="station-time"
                class="fs-6"
                :title="$gettext('Station Time')"
            >
                {{ clock }}
            </div>
        </router-link>

        <div class="station-player">
            <div class="station-art">
                <img
                    v-if="np.now_playing?.song?.art"
                    :src="np.now_playing.song.art"
                    alt=""
                    loading="lazy"
                >
            </div>
            <div class="station-player-controls">
                <button
                    v-if="np.station?.listen_url"
                    type="button"
                    class="station-play"
                    :title="isListening ? $gettext('Pause') : $gettext('Listen Live')"
                    :aria-label="isListening ? $gettext('Pause') : $gettext('Listen Live')"
                    @click="toggleListening"
                >
                    <icon-ic-pause v-if="isListening" />
                    <icon-ic-play-arrow v-else />
                </button>
                <div
                    class="station-listeners"
                    :title="$gettext('Current Listeners')"
                >
                    <icon-ic-headphones class="align-middle" />
                    <span class="ps-1">{{ np.listeners?.total ?? 0 }}</span>
                </div>
            </div>
        </div>

        <div
            v-if="np.now_playing?.song?.text"
            class="station-onair"
        >
            <div class="onair-head">
                <span class="onair-label">{{ $gettext('ON AIR') }}</span>
                <span
                    v-if="currentTrackDurationDisplay"
                    class="onair-time"
                >{{ currentTrackElapsedDisplay }} / {{ currentTrackDurationDisplay }}</span>
            </div>
            <div
                class="onair-title"
                :title="np.now_playing.song.title || np.now_playing.song.text"
            >
                {{ np.now_playing.song.title || np.now_playing.song.text }}
            </div>
            <div
                v-if="np.now_playing.song.title && np.now_playing.song.artist"
                class="onair-artist"
                :title="np.now_playing.song.artist"
            >
                {{ np.now_playing.song.artist }}
            </div>
            <div
                v-if="currentTrackDurationDisplay"
                class="onair-progress"
            >
                <div
                    class="onair-progress-bar"
                    :style="{width: currentTrackPercent + '%'}"
                />
            </div>
        </div>
    </div>

    <template v-if="userAllowedForStation(StationPermissions.Broadcasting)">
        <div
            v-if="!hasStarted"
            class="navdrawer-alert bg-success-subtle text-success-emphasis"
        >
            <router-link
                :to="{name: 'stations:restart:index'}"
            >
                <span class="fw-bold">{{ $gettext('Start Station') }}</span><br>
                <small>
                    {{ $gettext('Ready to start broadcasting? Click to start your station.') }}
                </small>
            </router-link>
        </div>
        <div
            v-else-if="needsRestart"
            class="navdrawer-alert bg-warning-subtle text-warning-emphasis"
        >
            <router-link
                :to="{name: 'stations:restart:index'}"
            >
                <span class="fw-bold">{{ $gettext('Reload to Apply Changes') }}</span><br>
                <small>
                    {{ $gettext('Your station has changes that require a reload to apply.') }}
                </small>
            </router-link>
        </div>
    </template>

    <div class="offcanvas-body">
        <sidebar-menu
            :menu="menuItems"
            :search-sources="[{section: name ?? '', menu: menuItems}, adminSearch]"
            :data-sources="dataSources"
        />
    </div>
</template>

<script setup lang="ts">
import {computed, ref} from "vue";
import SidebarMenu from "~/components/Common/SidebarMenu.vue";
import {toRefs, useIntervalFn} from "@vueuse/core";
import {useStationsMenu} from "~/components/Stations/menu";
import {useAdminSearchSource} from "~/components/Admin/menu";
import {useStationSearchSources} from "~/components/Stations/searchSources.ts";
import {useAdminSearchSources} from "~/components/Admin/searchSources.ts";
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";
import {useLuxon} from "~/vendor/luxon.ts";
import {ApiNowPlayingVueProps, StationPermissions} from "~/entities/ApiInterfaces.ts";
import {useStationData} from "~/functions/useStationQuery.ts";
import {useUserAllowedForStation} from "~/functions/useUserallowedForStation.ts";
import useNowPlaying from "~/functions/useNowPlaying.ts";
import IconIcHeadphones from "~icons/ic/baseline-headphones";
import IconIcPlayArrow from "~icons/ic/baseline-play-arrow";
import IconIcPause from "~icons/ic/baseline-pause";
import {usePlayerStore} from "~/functions/usePlayerStore.ts";
import getUrlWithoutQuery from "~/functions/getUrlWithoutQuery.ts";
import {storeToRefs} from "pinia";

const menuItems = useStationsMenu();
const adminSearch = useAdminSearchSource();
const dataSources = [...useStationSearchSources(), ...useAdminSearchSources()];
const {userAllowedForStation} = useUserAllowedForStation();

const stationData = useStationData();
const {name, hasStarted, needsRestart, timezone} = toRefs(stationData);

const nowPlayingProps = computed<ApiNowPlayingVueProps>(() => ({
    stationShortName: stationData.value.shortName,
    useStatic: false,
    useSse: true,
}));

const {
    np,
    currentTrackPercent,
    currentTrackDurationDisplay,
    currentTrackElapsedDisplay,
} = useNowPlaying(nowPlayingProps);

// Listen live from the sidebar through the panel's shared player (the top
// bar's volume control drives the same audio). A live stream cannot pause in
// place, so Pause stops it and Play rejoins the stream live.
const playerStore = usePlayerStore();
const {isPlaying, current} = storeToRefs(playerStore);

const isListening = computed(() => {
    const url = np.value.station?.listen_url;
    return isPlaying.value && !!url && getUrlWithoutQuery(current.value.url) === getUrlWithoutQuery(url);
});

const toggleListening = () => {
    playerStore.toggle({
        url: np.value.station?.listen_url,
        title: name.value,
        isStream: true,
    });
};

const {DateTime} = useLuxon();
const {now, formatDateTimeAsTime} = useStationDateTimeFormatter(timezone);

const clock = ref('');

useIntervalFn(() => {
    clock.value = formatDateTimeAsTime(now(), DateTime.TIME_WITH_SHORT_OFFSET);
}, 1000, {
    immediate: true,
    immediateCallback: true
});
</script>

<style scoped>
.station-player {
    display: flex;
    align-items: center;
    gap: .65rem;
    margin-top: .55rem;
}

.station-art {
    flex-shrink: 0;
    width: 64px;
    height: 64px;
    border-radius: .35rem;
    overflow: hidden;
    background: color-mix(in srgb, var(--bs-body-color) 8%, transparent);
    border: 1px solid var(--bs-border-color);
}

.station-art img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.station-player-controls {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: .35rem;
    min-width: 0;
}

.station-play {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: var(--bs-primary);
    color: #fff;
    font-size: 1.35rem;
    line-height: 1;
}

.station-play:hover {
    filter: brightness(1.1);
}

.station-play:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
}

.station-listeners {
    display: flex;
    align-items: center;
    font-size: .78rem;
    opacity: .85;
}

.station-onair {
    display: flex;
    flex-direction: column;
    margin-top: .6rem;
    max-width: 100%;
    min-width: 0;
    font-size: .74rem;
    line-height: 1.35;
}

.onair-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
    margin-bottom: .3rem;
}

.onair-time {
    font-variant-numeric: tabular-nums;
    font-size: .7rem;
    opacity: .75;
    white-space: nowrap;
}

.onair-title,
.onair-artist {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.onair-title {
    color: var(--bs-emphasis-color);
    font-weight: 600;
    font-size: .8rem;
}

.onair-artist {
    opacity: .75;
}

.onair-progress {
    height: 3px;
    margin-top: .4rem;
    border-radius: 2px;
    overflow: hidden;
    background: color-mix(in srgb, var(--bs-body-color) 12%, transparent);
}

.onair-progress-bar {
    height: 100%;
    background: #ff5c5c;
    transition: width 1s linear;
}

.onair-label {
    flex-shrink: 0;
    display: inline-block;
    padding: .12rem .45rem;
    border-radius: .25rem;
    font-weight: 700;
    font-size: .65rem;
    letter-spacing: .05em;
    border: 1px solid #ff5c5c;
    background: rgba(255, 92, 92, .12);
    color: #ff5c5c;
    animation: onair-flash 1.1s ease-in-out infinite;
}

@keyframes onair-flash {
    0%, 100% { opacity: 1; border-color: #ff5c5c; background: rgba(255, 92, 92, .12); }
    50% { opacity: .55; border-color: rgba(255, 92, 92, .4); background: rgba(255, 92, 92, .05); }
}

@media (prefers-reduced-motion: reduce) {
    .onair-label {
        animation: none;
    }

    .onair-progress-bar {
        transition: none;
    }
}
</style>