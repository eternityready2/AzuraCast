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

        <div
            class="station-listeners"
            :title="$gettext('Current Listeners')"
        >
            <icon-ic-headphones class="align-middle" />
            <span class="ps-1">{{ np.listeners?.total ?? 0 }}</span>
        </div>

        <div
            v-if="np.now_playing?.song?.text"
            class="station-onair-next"
        >
            <div class="onair-row">
                <span class="onair-label">{{ $gettext('ON AIR') }}</span>
                <span class="onair-text">{{ np.now_playing.song.text }}</span>
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
                :to="{name: 'stations:playout_controls', query: {tab: 'restart_broadcasting'}}"
            >
                <span class="fw-bold">{{ $gettext('Reload to Apply Changes') }}</span><br>
                <small>
                    {{ $gettext('Your station has changes that require a reload to apply.') }}
                </small>
            </router-link>
        </div>
    </template>

    <div class="offcanvas-body">
        <sidebar-menu :menu="menuItems" />
    </div>
</template>

<script setup lang="ts">
import {computed, ref} from "vue";
import SidebarMenu from "~/components/Common/SidebarMenu.vue";
import {toRefs, useIntervalFn} from "@vueuse/core";
import {useStationsMenu} from "~/components/Stations/menu";
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";
import {useLuxon} from "~/vendor/luxon.ts";
import {ApiNowPlayingVueProps, StationPermissions} from "~/entities/ApiInterfaces.ts";
import {useStationData} from "~/functions/useStationQuery.ts";
import {useUserAllowedForStation} from "~/functions/useUserallowedForStation.ts";
import useNowPlaying from "~/functions/useNowPlaying.ts";
import IconIcHeadphones from "~icons/ic/baseline-headphones";

const menuItems = useStationsMenu();
const {userAllowedForStation} = useUserAllowedForStation();

const stationData = useStationData();
const {name, hasStarted, needsRestart, timezone} = toRefs(stationData);

const nowPlayingProps = computed<ApiNowPlayingVueProps>(() => ({
    stationShortName: stationData.value.shortName,
    useStatic: false,
    useSse: true,
}));

const {np} = useNowPlaying(nowPlayingProps);

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
.station-listeners {
    display: flex;
    align-items: center;
    font-size: .78rem;
    opacity: .85;
    margin-top: .1rem;
}

.station-onair-next {
    display: flex;
    flex-direction: column;
    gap: .5rem;
    font-size: .74rem;
    line-height: 1.4;
    margin-top: .55rem;
    max-width: 100%;
    min-width: 0;
    padding: .5rem;
    border-radius: .35rem;
    background: color-mix(in srgb, var(--bs-body-color) 6%, transparent);
    border: 1px solid var(--bs-border-color);
}

.onair-row {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: .3rem;
    max-width: 100%;
    min-width: 0;
    padding-bottom: .5rem;
    border-bottom: 1px solid var(--bs-border-color);
}

.onair-row:last-child {
    padding-bottom: 0;
    border-bottom: none;
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

.onair-text {
    white-space: normal;
    overflow: visible;
    word-break: break-word;
    color: var(--bs-emphasis-color);
    font-weight: 500;
}

@keyframes onair-flash {
    0%, 100% { opacity: 1; border-color: #ff5c5c; background: rgba(255, 92, 92, .12); }
    50% { opacity: .55; border-color: rgba(255, 92, 92, .4); background: rgba(255, 92, 92, .05); }
}

@media (prefers-reduced-motion: reduce) {
    .onair-label {
        animation: none;
    }
}
</style>