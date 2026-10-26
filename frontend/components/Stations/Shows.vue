<template>
    <div class="shows-page">
        <header class="shows-hero">
            <div class="shows-hero-icon">
                <icon-event />
            </div>
            <div class="shows-hero-copy">
                <h1>{{ $gettext('Shows') }}</h1>
                <p>{{ $gettext('Your station\'s programs: when they air, their episodes, and anything that needs attention.') }}</p>
            </div>
            <button
                type="button"
                class="btn add-show-button"
                @click="openNewShow"
            >
                <icon-add class="me-1" />
                {{ $gettext('New Show') }}
            </button>
        </header>

        <div
            v-if="loading"
            class="shows-empty"
        >
            <span class="spinner-border spinner-border-sm me-2" />
            {{ $gettext('Loading shows...') }}
        </div>

        <template v-else>
            <section class="shows-summary">
                <div class="summary-tile">
                    <span class="summary-label">{{ $gettext('Shows') }}</span>
                    <strong>{{ shows.length }}</strong>
                </div>
                <div class="summary-tile">
                    <span class="summary-label">{{ $gettext('On air now') }}</span>
                    <strong>{{ onAirNow ? onAirNow.name : '—' }}</strong>
                </div>
                <div class="summary-tile">
                    <span class="summary-label">{{ $gettext('Up next') }}</span>
                    <strong v-if="upNext">{{ upNext.show.name }}</strong>
                    <strong v-else>—</strong>
                    <small v-if="upNext">{{ formatNext(upNext.at) }}</small>
                </div>
                <div
                    class="summary-tile"
                    :class="{'needs-attention': attentionCount > 0}"
                >
                    <span class="summary-label">{{ $gettext('Needs attention') }}</span>
                    <strong>{{ attentionCount }}</strong>
                </div>
            </section>

            <section
                v-if="candidates.length"
                class="shows-candidates"
            >
                <div class="candidates-title">
                    {{ $gettext('These look like shows (every file is 20 minutes or longer). Marking them as shows lets the station ID interrupt them instead of dropping them.') }}
                </div>
                <div
                    v-for="candidate in candidates"
                    :key="candidate.id"
                    class="candidate-row"
                >
                    <span>{{ candidate.name }}</span>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        :disabled="isMarking"
                        @click="markAsShow(candidate)"
                    >
                        {{ $gettext('Mark as show') }}
                    </button>
                </div>
            </section>

            <div class="shows-toolbar">
                <div
                    class="btn-group btn-group-sm"
                    role="group"
                    :aria-label="$gettext('View')"
                >
                    <button
                        type="button"
                        class="btn"
                        :class="view === 'shows' ? 'btn-primary' : 'btn-outline-secondary'"
                        @click="view = 'shows'"
                    >
                        <icon-list class="me-1" />
                        {{ $gettext('All Shows') }}
                    </button>
                    <button
                        type="button"
                        class="btn"
                        :class="view === 'week' ? 'btn-primary' : 'btn-outline-secondary'"
                        @click="view = 'week'"
                    >
                        <icon-event class="me-1" />
                        {{ $gettext('This Week') }}
                    </button>
                </div>
                <div class="shows-search">
                    <icon-search />
                    <input
                        v-model="search"
                        type="search"
                        class="form-control form-control-sm"
                        :placeholder="$gettext('Search shows')"
                    >
                </div>
            </div>

            <div
                v-if="shows.length === 0"
                class="shows-empty"
            >
                <h2>{{ $gettext('No shows yet') }}</h2>
                <p>{{ $gettext('A show is a program with a time slot and episodes, like a weekly talk program or a Sunday service. Create one, then upload its episodes.') }}</p>
                <button
                    type="button"
                    class="btn btn-primary"
                    @click="openNewShow"
                >
                    <icon-add class="me-1" />
                    {{ $gettext('New Show') }}
                </button>
            </div>

            <div
                v-else-if="view === 'shows'"
                class="show-grid"
            >
                <article
                    v-for="show in filteredShows"
                    :key="show.id"
                    class="show-card"
                    :class="{'is-on-air': onAirNow?.id === show.id, 'is-off': !show.is_enabled}"
                >
                    <div class="show-card-head">
                        <h3>{{ show.name }}</h3>
                        <span
                            v-for="badge in badgesFor(show)"
                            :key="badge.label"
                            class="badge"
                            :class="badge.cls"
                        >{{ badge.label }}</span>
                    </div>

                    <p
                        v-if="show.description"
                        class="show-description"
                    >
                        {{ show.description }}
                    </p>

                    <dl class="show-facts">
                        <div>
                            <dt>{{ $gettext('Airs') }}</dt>
                            <dd>
                                <template v-if="show.schedule_items.length">
                                    <div
                                        v-for="(slot, index) in slotLabels(show)"
                                        :key="index"
                                    >
                                        {{ slot }}
                                    </div>
                                </template>
                                <span
                                    v-else
                                    class="text-warning"
                                >{{ $gettext('Not scheduled') }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt>{{ $gettext('Next') }}</dt>
                            <dd>{{ nextFor(show) ? formatNext(nextFor(show) as DateTime) : '—' }}</dd>
                        </div>
                        <div>
                            <dt>{{ $gettext('Episodes') }}</dt>
                            <dd>
                                <template v-if="show.num_songs > 0">
                                    {{ show.num_songs === 1
                                        ? $gettext('1 episode')
                                        : $gettext('%{n} episodes', {n: String(show.num_songs)}) }}
                                    <span class="text-body-secondary">
                                        · {{ $gettext('about %{m} min each', {m: String(averageMinutes(show))}) }}
                                    </span>
                                </template>
                                <span
                                    v-else
                                    class="text-danger"
                                >{{ $gettext('None yet') }}</span>
                            </dd>
                        </div>
                    </dl>

                    <div class="show-actions">
                        <router-link
                            class="btn btn-sm btn-primary"
                            :to="{name: 'stations:playlists:edit', params: {playlist_id: show.id}, query: {tab: 'schedule'}}"
                        >
                            {{ $gettext('Edit') }}
                        </router-link>
                        <router-link
                            class="btn btn-sm btn-outline-secondary"
                            :to="{name: 'stations:files:index'}"
                        >
                            {{ $gettext('Upload episodes') }}
                        </router-link>
                    </div>
                </article>
            </div>

            <div
                v-else
                class="week-grid"
            >
                <div
                    v-for="day in week"
                    :key="day.iso"
                    class="week-day"
                    :class="{'is-today': day.isToday}"
                >
                    <div class="week-day-head">
                        <strong>{{ day.label }}</strong>
                        <small>{{ day.date }}</small>
                    </div>
                    <div
                        v-if="day.airings.length === 0"
                        class="week-none"
                    >
                        {{ $gettext('No shows') }}
                    </div>
                    <router-link
                        v-for="airing in day.airings"
                        :key="`${airing.show.id}-${airing.start}`"
                        class="week-airing"
                        :class="{'is-on-air': airing.onAir}"
                        :to="{name: 'stations:playlists:edit', params: {playlist_id: airing.show.id}, query: {tab: 'schedule'}}"
                    >
                        <span class="week-time">{{ airing.time }}</span>
                        <span class="week-name">{{ airing.show.name }}</span>
                    </router-link>
                </div>
            </div>
        </template>

        <div
            v-if="newShowOpen"
            class="new-show-backdrop"
            @click.self="newShowOpen = false"
        >
            <form
                class="new-show-panel"
                @submit.prevent="createShow"
            >
                <h2>{{ $gettext('New Show') }}</h2>

                <label class="form-label" for="new_show_name">{{ $gettext('Show name') }}</label>
                <input
                    id="new_show_name"
                    v-model="newShow.name"
                    class="form-control mb-3"
                    required
                >

                <label class="form-label" for="new_show_description">{{ $gettext('Description (optional)') }}</label>
                <textarea
                    id="new_show_description"
                    v-model="newShow.description"
                    class="form-control mb-3"
                    rows="2"
                />

                <span class="form-label d-block">{{ $gettext('Airs on') }}</span>
                <div class="day-picker mb-3">
                    <button
                        v-for="day in dayOptions"
                        :key="day.value"
                        type="button"
                        class="btn btn-sm"
                        :class="newShow.days.includes(day.value) ? 'btn-primary' : 'btn-outline-secondary'"
                        @click="toggleDay(day.value)"
                    >
                        {{ day.short }}
                    </button>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col">
                        <label class="form-label" for="new_show_start">{{ $gettext('Starts') }}</label>
                        <input
                            id="new_show_start"
                            v-model="newShow.start"
                            type="time"
                            class="form-control"
                            required
                        >
                    </div>
                    <div class="col">
                        <label class="form-label" for="new_show_end">{{ $gettext('Ends') }}</label>
                        <input
                            id="new_show_end"
                            v-model="newShow.end"
                            type="time"
                            class="form-control"
                            required
                        >
                    </div>
                </div>
                <p class="small text-body-secondary">
                    {{ $gettext('Tip: start and end on the hour (like 9:00 to 10:00) so the show fits neatly around the station ID.') }}
                </p>

                <div
                    v-if="newShowError"
                    class="alert alert-danger py-2 small"
                >
                    {{ newShowError }}
                </div>

                <div class="d-flex gap-2 justify-content-end">
                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        @click="newShowOpen = false"
                    >
                        {{ $gettext('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="btn btn-primary"
                        :disabled="isCreating || newShow.days.length === 0"
                    >
                        {{ $gettext('Create Show') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref} from "vue";
import type {DateTime} from "luxon";
import IconAdd from "~icons/ic/baseline-add";
import IconEvent from "~icons/ic/baseline-event";
import IconList from "~icons/ic/baseline-list";
import IconSearch from "~icons/ic/baseline-search";
import {useApiRouter} from "~/functions/useApiRouter";
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";

/**
 * Shows are the station's programs: playlists marked as shows (is_programme),
 * each with its time slots and episode files. This page reads and creates
 * them through the playlists that already air; nothing here is a separate
 * engine.
 */
type ScheduleItem = {
    start_time: number,
    end_time: number,
    days: number[],
    start_date?: string | null,
    end_date?: string | null,
    recurrence_type?: string | null,
    recurrence_interval?: number | null,
};

type ShowRow = {
    id: number,
    name: string,
    description: string | null,
    is_enabled: boolean,
    is_programme: boolean,
    is_smart_block?: boolean,
    source: string,
    num_songs: number,
    total_length: number,
    schedule_items: ScheduleItem[],
};

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {now} = useStationDateTimeFormatter();
const {notifySuccess, notifyError} = useNotify();

const playlistsUrl = getStationApiUrl('/playlists');
const setupCheckUrl = getStationApiUrl('/reports/linear-log/setup-check');
const setupFixUrl = getStationApiUrl('/reports/linear-log/setup-check/fix');

const loading = ref(true);
const allPlaylists = ref<ShowRow[]>([]);
const candidateIds = ref<number[]>([]);
const view = ref<'shows' | 'week'>('shows');
const search = ref('');
const clock = ref(now());
let clockTimer: number | null = null;

const shows = computed(() => allPlaylists.value
    .filter((p) => p.is_programme && !p.is_smart_block)
    .sort((a, b) => a.name.localeCompare(b.name)));

const filteredShows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return needle === ''
        ? shows.value
        : shows.value.filter((s) => s.name.toLowerCase().includes(needle)
            || (s.description ?? '').toLowerCase().includes(needle));
});

const candidates = computed(() => allPlaylists.value.filter((p) => candidateIds.value.includes(p.id)));

const load = async () => {
    try {
        const {data} = await axios.get(playlistsUrl.value, {params: {rowCount: -1}});
        allPlaylists.value = (data.rows ?? data ?? []) as ShowRow[];
    } finally {
        loading.value = false;
    }

    // Playlists that are clearly shows but not marked as one (from the log's
    // setup check, which finds them by file length).
    try {
        const {data} = await axios.get<{issues: Array<{id: string}>}>(setupCheckUrl.value);
        candidateIds.value = (data.issues ?? [])
            .filter((issue) => issue.id.startsWith('show:'))
            .map((issue) => Number(issue.id.slice('show:'.length)));
    } catch {
        candidateIds.value = [];
    }
};

onMounted(() => {
    void load();
    clockTimer = window.setInterval(() => {
        clock.value = now();
    }, 30000);
});
onUnmounted(() => {
    if (clockTimer !== null) window.clearInterval(clockTimer);
});

/* ---------- Times ---------- */

const dayOptions = [
    {value: 1, short: $gettext('Mon'), long: $gettext('Monday')},
    {value: 2, short: $gettext('Tue'), long: $gettext('Tuesday')},
    {value: 3, short: $gettext('Wed'), long: $gettext('Wednesday')},
    {value: 4, short: $gettext('Thu'), long: $gettext('Thursday')},
    {value: 5, short: $gettext('Fri'), long: $gettext('Friday')},
    {value: 6, short: $gettext('Sat'), long: $gettext('Saturday')},
    {value: 7, short: $gettext('Sun'), long: $gettext('Sunday')},
];

const clockLabel = (hhmm: number): string => {
    const hour = Math.floor(hhmm / 100) % 24;
    const minute = hhmm % 100;
    const h12 = hour % 12 === 0 ? 12 : hour % 12;
    return `${h12}:${String(minute).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`;
};

const daysLabel = (days: number[]): string => {
    if (days.length === 0 || days.length === 7) return $gettext('Every day');
    const sorted = [...days].sort((a, b) => a - b);
    if (sorted.join(',') === '1,2,3,4,5') return $gettext('Weekdays');
    if (sorted.join(',') === '6,7') return $gettext('Weekends');
    return sorted.map((d) => dayOptions.find((o) => o.value === d)?.short ?? '').join(', ');
};

const slotLabels = (show: ShowRow): string[] => show.schedule_items.map((item) => {
    const every = (item.recurrence_interval ?? 1) > 1
        ? ' · ' + $gettext('every %{n} weeks', {n: String(item.recurrence_interval)})
        : (item.recurrence_type === 'monthly' ? ' · ' + $gettext('monthly') : '');
    return `${daysLabel(item.days)} · ${clockLabel(item.start_time)}–${clockLabel(item.end_time)}${every}`;
});

const dateAllowed = (item: ScheduleItem, day: DateTime): boolean => {
    const iso = day.toISODate() ?? '';
    if (item.start_date && iso < item.start_date) return false;
    if (item.end_date && iso > item.end_date) return false;
    return item.days.length === 0 || item.days.includes(day.weekday);
};

/** Start times of a show within the next N days (weekly slots; other repeats shown in Edit). */
const airingsFor = (show: ShowRow, fromDay: DateTime, days: number): DateTime[] => {
    const out: DateTime[] = [];
    for (let i = 0; i < days; i++) {
        const day = fromDay.plus({days: i}).startOf('day');
        for (const item of show.schedule_items) {
            if (!dateAllowed(item, day)) continue;
            out.push(day.set({hour: Math.floor(item.start_time / 100) % 24, minute: item.start_time % 100}));
        }
    }
    return out.sort((a, b) => a.toMillis() - b.toMillis());
};

const isOnAir = (show: ShowRow, at: DateTime): boolean => {
    if (!show.is_enabled) return false;
    for (const item of show.schedule_items) {
        for (const offset of [0, -1]) {
            const day = at.plus({days: offset}).startOf('day');
            if (!dateAllowed(item, day)) continue;
            const start = day.set({hour: Math.floor(item.start_time / 100) % 24, minute: item.start_time % 100});
            let end = day.set({hour: Math.floor(item.end_time / 100) % 24, minute: item.end_time % 100});
            if (end <= start) end = end.plus({days: 1});
            if (at >= start && at < end) return true;
        }
    }
    return false;
};

const nextFor = (show: ShowRow): DateTime | null => {
    if (!show.is_enabled) return null;
    return airingsFor(show, clock.value.startOf('day'), 15).find((t) => t > clock.value) ?? null;
};

const formatNext = (at: DateTime): string => {
    const diff = at.diff(clock.value, ['days', 'hours', 'minutes']);
    let rel: string;
    if (diff.days >= 1) {
        rel = $gettext('in %{n} day(s)', {n: String(Math.floor(diff.days))});
    } else if (diff.hours >= 1) {
        rel = $gettext('in %{n} h', {n: String(Math.floor(diff.hours))});
    } else {
        rel = $gettext('in %{n} min', {n: String(Math.max(1, Math.round(diff.minutes)))});
    }
    return `${at.toFormat('ccc h:mm a')} (${rel})`;
};

const onAirNow = computed(() => shows.value.find((s) => isOnAir(s, clock.value)) ?? null);

const upNext = computed(() => {
    let best: {show: ShowRow, at: DateTime} | null = null;
    for (const show of shows.value) {
        const at = nextFor(show);
        if (at && (!best || at < best.at)) best = {show, at};
    }
    return best;
});

const averageMinutes = (show: ShowRow): number =>
    show.num_songs > 0 ? Math.round(show.total_length / show.num_songs / 60) : 0;

/* ---------- Status ---------- */

const badgesFor = (show: ShowRow): Array<{label: string, cls: string}> => {
    const badges: Array<{label: string, cls: string}> = [];
    if (!show.is_enabled) badges.push({label: $gettext('Off'), cls: 'text-bg-secondary'});
    if (onAirNow.value?.id === show.id) badges.push({label: $gettext('On air now'), cls: 'text-bg-success'});
    if (show.num_songs === 0) badges.push({label: $gettext('No episodes'), cls: 'text-bg-danger'});
    if (show.schedule_items.length === 0) badges.push({label: $gettext('Not scheduled'), cls: 'text-bg-warning'});
    return badges;
};

const attentionCount = computed(() => shows.value
    .filter((s) => s.is_enabled && (s.num_songs === 0 || s.schedule_items.length === 0)).length
    + candidates.value.length);

/* ---------- Week ---------- */

const week = computed(() => {
    const today = clock.value.startOf('day');
    const monday = today.minus({days: today.weekday - 1});
    return Array.from({length: 7}, (_, i) => {
        const day = monday.plus({days: i});
        const airings = shows.value
            .filter((s) => s.is_enabled)
            .flatMap((show) => show.schedule_items
                .filter((item) => dateAllowed(item, day))
                .map((item) => {
                    const start = day.set({hour: Math.floor(item.start_time / 100) % 24, minute: item.start_time % 100});
                    return {
                        show,
                        start: item.start_time,
                        time: `${clockLabel(item.start_time)}–${clockLabel(item.end_time)}`,
                        onAir: onAirNow.value?.id === show.id && day.hasSame(clock.value, 'day')
                            && clock.value >= start,
                    };
                }))
            .sort((a, b) => a.start - b.start);
        return {
            iso: day.toISODate() ?? String(i),
            label: day.toFormat('cccc'),
            date: day.toFormat('LLL d'),
            isToday: day.hasSame(today, 'day'),
            airings,
        };
    });
});

/* ---------- Actions ---------- */

const isMarking = ref(false);
const markAsShow = async (playlist: ShowRow) => {
    isMarking.value = true;
    try {
        await axios.post(setupFixUrl.value, {id: `show:${playlist.id}`});
        notifySuccess($gettext('%{name} is now a show.', {name: playlist.name}));
        await load();
    } catch (e: any) {
        notifyError(e?.response?.data?.message ?? String(e));
    } finally {
        isMarking.value = false;
    }
};

const newShowOpen = ref(false);
const isCreating = ref(false);
const newShowError = ref<string | null>(null);
const newShow = ref({name: '', description: '', days: [] as number[], start: '09:00', end: '10:00'});

const openNewShow = () => {
    newShow.value = {name: '', description: '', days: [], start: '09:00', end: '10:00'};
    newShowError.value = null;
    newShowOpen.value = true;
};

const toggleDay = (day: number) => {
    const days = newShow.value.days;
    newShow.value.days = days.includes(day) ? days.filter((d) => d !== day) : [...days, day];
};

const toHhmm = (value: string): number => {
    const [h, m] = value.split(':').map((part) => Number(part));
    return (h ?? 0) * 100 + (m ?? 0);
};

const createShow = async () => {
    isCreating.value = true;
    newShowError.value = null;
    try {
        await axios.post(playlistsUrl.value, {
            name: newShow.value.name.trim(),
            description: newShow.value.description.trim() || null,
            source: 'songs',
            type: 'default',
            order: 'sequential',
            is_enabled: true,
            is_programme: true,
            include_in_requests: false,
            schedule_editor: 'new',
            schedule_items: [{
                start_time: toHhmm(newShow.value.start),
                end_time: toHhmm(newShow.value.end),
                days: [...newShow.value.days].sort((a, b) => a - b),
                loop_once: true,
                recurrence_type: 'weekly',
                recurrence_interval: 1,
                recurrence_end_type: 'never',
            }],
        });
        newShowOpen.value = false;
        notifySuccess($gettext('Show created. Next, upload its episodes on the Media page and add them to this show.'));
        await load();
    } catch (e: any) {
        newShowError.value = e?.response?.data?.message ?? String(e);
    } finally {
        isCreating.value = false;
    }
};
</script>

<style scoped>
.shows-page {
    width: 100%;
    max-width: 1320px;
    margin: 0 auto;
}

.shows-hero {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    margin-bottom: 1.25rem;
    padding: 1.1rem 1.5rem;
    border-radius: 1rem;
    color: #fff;
    background: linear-gradient(90deg, #0a6fc2 0%, #2196f3 100%);
    box-shadow: 0 .6rem 1.4rem rgba(0, 0, 0, .2);
}

.shows-hero-icon {
    display: grid;
    place-items: center;
    width: 3rem;
    height: 3rem;
    border-radius: .8rem;
    background: rgba(255, 255, 255, .18);
    font-size: 1.5rem;
}

.shows-hero-copy {
    flex: 1;
}

.shows-hero-copy h1 {
    margin: 0;
    font-size: 1.45rem;
    font-weight: 700;
}

.shows-hero-copy p {
    margin: .2rem 0 0;
    opacity: .9;
}

.add-show-button {
    color: #0a6fc2;
    background: #fff;
    font-weight: 600;
}

.shows-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: .75rem;
    margin-bottom: 1rem;
}

.summary-tile {
    display: flex;
    flex-direction: column;
    gap: .15rem;
    padding: .85rem 1rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: var(--bs-body-bg);
}

.summary-tile strong {
    font-size: 1.15rem;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.summary-tile small {
    color: var(--bs-secondary-color);
}

.summary-tile.needs-attention {
    border-color: var(--bs-warning);
}

.summary-label {
    color: var(--bs-secondary-color);
    font-size: .75rem;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.shows-candidates {
    margin-bottom: 1rem;
    padding: .85rem 1rem;
    border: 1px solid var(--bs-warning-border-subtle);
    border-radius: .75rem;
    background: var(--bs-warning-bg-subtle);
    color: var(--bs-warning-text-emphasis);
}

.candidates-title {
    margin-bottom: .5rem;
    font-size: .9rem;
}

.candidate-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: .4rem 0;
    border-top: 1px solid rgba(var(--bs-body-color-rgb), .08);
    font-weight: 600;
}

.shows-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    margin-bottom: 1rem;
}

.shows-search {
    display: flex;
    align-items: center;
    gap: .4rem;
    min-width: 220px;
}

.shows-empty {
    padding: 3rem 1rem;
    border: 1px dashed var(--bs-border-color);
    border-radius: .75rem;
    text-align: center;
}

.shows-empty h2 {
    font-size: 1.2rem;
}

.show-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
}

.show-card {
    display: flex;
    flex-direction: column;
    padding: 1rem 1.1rem;
    border: 1px solid var(--bs-border-color);
    border-left: 4px solid var(--bs-primary);
    border-radius: .75rem;
    background: var(--bs-body-bg);
}

.show-card.is-on-air {
    border-left-color: var(--bs-success);
    box-shadow: 0 0 0 1px var(--bs-success-border-subtle);
}

.show-card.is-off {
    opacity: .65;
    border-left-color: var(--bs-secondary);
}

.show-card-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .4rem;
    margin-bottom: .35rem;
}

.show-card-head h3 {
    flex: 1 1 100%;
    margin: 0 0 .2rem;
    font-size: 1.1rem;
    font-weight: 700;
}

.show-description {
    display: -webkit-box;
    margin-bottom: .6rem;
    overflow: hidden;
    color: var(--bs-secondary-color);
    font-size: .88rem;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.show-facts {
    display: grid;
    gap: .45rem;
    margin: 0 0 .9rem;
    font-size: .9rem;
}

.show-facts > div {
    display: grid;
    grid-template-columns: 5.5rem 1fr;
    gap: .5rem;
}

.show-facts dt {
    color: var(--bs-secondary-color);
    font-weight: 600;
}

.show-facts dd {
    margin: 0;
}

.show-actions {
    display: flex;
    gap: .5rem;
    margin-top: auto;
}

.week-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: .6rem;
}

@media (max-width: 992px) {
    .week-grid {
        grid-template-columns: 1fr;
    }
}

.week-day {
    min-height: 160px;
    padding: .6rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: var(--bs-body-bg);
}

.week-day.is-today {
    border-color: var(--bs-primary);
}

.week-day-head {
    display: flex;
    flex-direction: column;
    margin-bottom: .5rem;
}

.week-day-head small,
.week-none {
    color: var(--bs-secondary-color);
    font-size: .8rem;
}

.week-airing {
    display: flex;
    flex-direction: column;
    margin-bottom: .4rem;
    padding: .4rem .5rem;
    border-left: 3px solid var(--bs-primary);
    border-radius: .4rem;
    background: rgba(var(--bs-primary-rgb), .1);
    color: inherit;
    text-decoration: none;
}

.week-airing.is-on-air {
    border-left-color: var(--bs-success);
    background: rgba(var(--bs-success-rgb), .15);
}

.week-time {
    color: var(--bs-secondary-color);
    font-size: .75rem;
}

.week-name {
    font-size: .85rem;
    font-weight: 600;
}

.new-show-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1060;
    display: grid;
    place-items: center;
    padding: 1rem;
    background: rgba(0, 0, 0, .55);
}

.new-show-panel {
    width: min(480px, 100%);
    padding: 1.25rem 1.4rem;
    border-radius: 1rem;
    background: var(--bs-body-bg);
    box-shadow: 0 1rem 2.5rem rgba(0, 0, 0, .35);
}

.new-show-panel h2 {
    margin-bottom: 1rem;
    font-size: 1.25rem;
}

.day-picker {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
}
</style>
