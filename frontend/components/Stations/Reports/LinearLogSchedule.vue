<template>
    <section v-for="group in groups" :key="group.epochHour" class="hour-group">
        <div class="hour-header" :class="{'current-hour': group.isCurrent}">
            <span v-if="group.isCurrent" class="badge text-bg-primary">{{ $gettext('NOW') }}</span>
            <strong>{{ group.label }}</strong>
            <span class="hour-summary">
                {{ group.items.length }} {{ $gettext('items') }} / {{ group.totalDurationFormatted }}
            </span>
            <span v-if="group.hasId" class="badge text-bg-danger ms-auto">{{ $gettext('Station ID') }}</span>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 linear-table">
                <tbody>
                    <tr
                        v-for="item in group.items"
                        :key="item.id"
                        class="queue-row"
                        :class="rowClasses(item)"
                    >
                        <td v-if="visibleColumns.includes('time')" class="queue-time ps-3">
                            {{ formatTime(item.played_at) }}
                        </td>

                        <td v-if="visibleColumns.includes('title')" class="py-2">
                            <div class="d-flex align-items-start gap-2">
                                <span v-if="isOnAir(item)" class="onair-marker">{{ $gettext('ON AIR') }}</span>
                                <span v-else-if="isNextUp(item)" class="next-marker">{{ $gettext('NEXT') }}</span>
                                <span v-else-if="item.is_live_queue" class="live-marker">{{ $gettext('LIVE QUEUE') }}</span>
                                <span
                                    v-else-if="logMarker(item)"
                                    :class="logMarker(item)?.cls"
                                    :title="item.log_note ?? ''"
                                >{{ logMarker(item)?.label }}</span>
                                <div>
                                    <div v-if="item.autodj_custom_uri" class="small text-body-secondary">
                                        {{ item.autodj_custom_uri }}
                                    </div>
                                    <template v-else>
                                        <strong class="track-title">{{ displayTitle(item) }}</strong>
                                        <div v-if="item.artist" class="small track-artist">{{ item.artist }}</div>
                                        <div v-if="item.album" class="small text-body-secondary">{{ item.album }}</div>
                                        <div v-if="item.log_note" class="small log-note">{{ item.log_note }}</div>
                                    </template>
                                </div>
                            </div>
                        </td>

                        <td v-if="visibleColumns.includes('source')" class="playlist-cell">
                            <div>{{ sourceLabel(item) }}</div>
                            <div v-if="item.playlist_chain?.length" class="small text-body-secondary">
                                {{ item.playlist_chain.join(' → ') }}
                            </div>
                        </td>

                        <td v-if="visibleColumns.includes('type')" class="type-cell">
                            <span :class="typeBadgeClass(item)">{{ typeLabel(item) }}</span>
                        </td>

                        <td v-if="visibleColumns.includes('rules')" class="rules-cell">
                            <span v-if="item.source_type === 'scheduled_programme'" class="badge text-bg-primary me-1">
                                {{ $gettext('STRICT') }}
                            </span>
                            <span v-if="item.top_of_hour_legal_id" class="badge text-bg-danger me-1">TOH</span>
                            <span v-if="item.clock_wheel_enforce_cap" class="badge text-bg-secondary me-1">CAP</span>
                            <span v-if="item.clock_wheel_stretch_ratio" class="badge text-bg-info me-1">
                                {{ formatStretch(item.clock_wheel_stretch_ratio) }}
                            </span>
                            <span v-if="item.hour_boundary_enforce_cap" class="badge text-bg-warning me-1">BOUNDARY</span>
                            <span v-if="item.is_request" class="badge text-bg-primary me-1">REQUEST</span>
                            <span v-if="item.is_locked" class="badge text-bg-dark">LOCKED</span>
                        </td>

                        <td v-if="visibleColumns.includes('aired')" class="queue-time">
                            {{ item.aired_at ? formatTime(item.aired_at) : '-' }}
                        </td>

                        <td v-if="visibleColumns.includes('status')" class="status-cell">
                            {{ statusLabel(item) }}
                        </td>

                        <td v-if="visibleColumns.includes('edit')" class="edit-cell">
                            <div v-if="isEditable(item)" class="btn-group btn-group-sm" role="group">
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    :title="$gettext('Move up')"
                                    :disabled="busy"
                                    @click="emit('edit', item, 'up')"
                                >&uarr;</button>
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    :title="$gettext('Move down')"
                                    :disabled="busy"
                                    @click="emit('edit', item, 'down')"
                                >&darr;</button>
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    :title="item.is_locked ? $gettext('Unlock') : $gettext('Lock')"
                                    :disabled="busy"
                                    @click="emit('edit', item, item.is_locked ? 'unlock' : 'lock')"
                                >{{ item.is_locked ? $gettext('Unlock') : $gettext('Lock') }}</button>
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    :title="$gettext('Replace')"
                                    :disabled="busy"
                                    @click="emit('replace', item)"
                                >{{ $gettext('Replace') }}</button>
                                <button
                                    type="button"
                                    class="btn btn-outline-danger"
                                    :title="$gettext('Remove')"
                                    :disabled="busy"
                                    @click="emit('edit', item, 'remove')"
                                >&times;</button>
                            </div>
                        </td>

                        <td v-if="visibleColumns.includes('duration')" class="duration-cell pe-3">
                            {{ formatDuration(item.duration) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<script setup lang="ts">
import type {LinearLogHourGroup, LinearLogItem} from "~/entities/LinearLog";
import {useTranslate} from "~/vendor/gettext";

const props = defineProps<{
    groups: LinearLogHourGroup[];
    visibleColumns: string[];
    nowTs: number;
    busy?: boolean;
}>();

const emit = defineEmits<{
    (e: "edit", item: LinearLogItem, edit: string): void;
    (e: "replace", item: LinearLogItem): void;
}>();

// Only saved lines that have not been handed to AutoDJ yet can change.
function isEditable(item: LinearLogItem): boolean {
    return !!item.log_entry_id && item.log_status === "planned";
}

const {$gettext} = useTranslate();

function isNextUp(item: LinearLogItem): boolean {
    return (item.played_at ?? 0) >= props.nowTs && item.is_live_queue;
}

function isOnAir(item: LinearLogItem): boolean {
    if (!item.aired_at) return false;
    return item.aired_at <= props.nowTs && props.nowTs < item.aired_at + (item.duration ?? 0);
}

function logMarker(item: LinearLogItem): {label: string, cls: string} | null {
    switch (item.log_status) {
        case "aired":
            return {label: $gettext("AIRED"), cls: "aired-marker"};
        case "swapped":
            return {label: $gettext("SWAPPED"), cls: "swapped-marker"};
        case "replaced":
            return {label: $gettext("REPLACED"), cls: "swapped-marker"};
        case "dropped":
            return {label: $gettext("DROPPED"), cls: "dropped-marker"};
        default:
            return null;
    }
}

function statusLabel(item: LinearLogItem): string {
    if (isOnAir(item)) return $gettext("On air");
    const labels: Record<string, string> = {
        planned: $gettext("Planned"),
        queued: $gettext("Queued"),
        aired: $gettext("Aired"),
        swapped: $gettext("Swapped"),
        replaced: $gettext("Replaced"),
        dropped: $gettext("Dropped"),
    };
    return item.log_status ? (labels[item.log_status] ?? "-") : "-";
}

function displayTitle(item: LinearLogItem): string {
    return item.title || item.text || $gettext("Untitled");
}

function sourceLabel(item: LinearLogItem): string {
    if (item.source_type === "scheduled_programme" && item.playlist) {
        return `${$gettext("Scheduled Program")}: ${item.playlist}`;
    }
    if (item.clock_wheel) return item.clock_wheel;
    if (item.playlist) return item.playlist;
    if (item.is_request) return $gettext("Listener Request");
    if (item.autodj_custom_uri) return $gettext("Remote Stream");
    return $gettext("General Rotation");
}

function resolveType(item: LinearLogItem): string {
    if (item.source_type === "scheduled_programme" || item.media_type === "programme") return "programme";
    if (item.is_request) return "request";
    if (item.clock_wheel) return "clock_wheel";
    if (item.top_of_hour_legal_id || item.media_type === "id") return "id";
    if (item.autodj_custom_uri) return "stream";
    return item.media_type || "music";
}

function typeLabel(item: LinearLogItem): string {
    const labels: Record<string, string> = {
        programme: $gettext("Scheduled Program"),
        music: $gettext("Music"),
        talk: $gettext("Talk"),
        id: $gettext("ID"),
        promo: $gettext("Promo"),
        jingle: $gettext("Jingle"),
        podcast: $gettext("Podcast"),
        stream: $gettext("Stream"),
        request: $gettext("Request"),
        clock_wheel: $gettext("Clock"),
    };
    return labels[resolveType(item)] ?? $gettext("Music");
}

function typeBadgeClass(item: LinearLogItem): string {
    const classes: Record<string, string> = {
        programme: "badge text-bg-primary",
        music: "badge text-bg-success",
        talk: "badge text-bg-warning",
        id: "badge text-bg-danger",
        promo: "badge text-bg-info",
        jingle: "badge text-bg-secondary",
        podcast: "badge text-bg-primary",
        stream: "badge text-bg-dark",
        request: "badge text-bg-primary",
        clock_wheel: "badge text-bg-primary",
    };
    return classes[resolveType(item)] ?? "badge text-bg-success";
}

function rowClasses(item: LinearLogItem): Record<string, boolean> {
    return {
        "next-up": isNextUp(item),
        "legal-id": item.top_of_hour_legal_id,
        "live-queue": item.is_live_queue,
        "scheduled-programme": item.source_type === "scheduled_programme",
        "log-done": ["aired", "swapped", "replaced"].includes(item.log_status ?? "") && !isOnAir(item),
        "log-dropped": item.log_status === "dropped",
    };
}

function formatTime(timestamp: number | null): string {
    if (!timestamp) return "-";
    return new Date(timestamp * 1000).toLocaleTimeString([], {
        hour: "numeric",
        minute: "2-digit",
        second: "2-digit",
        hour12: true,
    });
}

function formatDuration(seconds: number): string {
    const minutes = Math.floor((seconds ?? 0) / 60);
    const remain = Math.floor((seconds ?? 0) % 60);
    return `${minutes}:${String(remain).padStart(2, "0")}`;
}

function formatStretch(ratio: number): string {
    return `${(ratio * 100).toFixed(1)}%`;
}
</script>

<style scoped>
.hour-header{display:flex;align-items:center;gap:.6rem;padding:.62rem 1rem;border-bottom:1px solid var(--bs-border-color);background:color-mix(in srgb,var(--bs-secondary-bg) 72%,var(--bs-body-bg))}
.hour-header.current-hour{background:linear-gradient(90deg,color-mix(in srgb,var(--bs-primary) 18%,var(--bs-body-bg)),color-mix(in srgb,var(--bs-primary) 8%,var(--bs-body-bg)));box-shadow:inset 3px 0 0 var(--bs-primary)}
.hour-summary{color:var(--bs-secondary-color);font-size:.76rem}
.linear-table{--bs-table-color:var(--bs-body-color);--bs-table-bg:var(--bs-body-bg);--bs-table-hover-color:var(--bs-body-color);--bs-table-hover-bg:color-mix(in srgb,var(--bs-secondary-bg) 72%,var(--bs-body-bg))}
.queue-row td{border-color:var(--bs-border-color)}
.queue-row.next-up td{background:color-mix(in srgb,var(--bs-success-bg-subtle) 34%,var(--bs-body-bg))}
.queue-row.legal-id td{background:color-mix(in srgb,var(--bs-danger-bg-subtle) 28%,var(--bs-body-bg))}
.queue-row.live-queue td{box-shadow:inset 2px 0 0 color-mix(in srgb,var(--bs-success) 55%,transparent)}
.queue-row.scheduled-programme td{background:color-mix(in srgb,var(--bs-primary-bg-subtle) 45%,var(--bs-body-bg));box-shadow:inset 3px 0 0 var(--bs-primary)}
.queue-time,.duration-cell{font-family:var(--bs-font-monospace);font-size:.76rem;white-space:nowrap}
.queue-time{width:100px}
.duration-cell{width:72px;text-align:right}
.playlist-cell{width:210px;font-size:.79rem}
.type-cell{width:135px}
.rules-cell{width:185px}
.track-title{color:var(--bs-body-color)}
.track-artist{color:var(--bs-secondary-color)!important}
.next-marker,.live-marker{display:inline-block;padding:.16rem .32rem;border-radius:.28rem;color:#fff;font-size:.58rem;font-weight:750;letter-spacing:.035em;white-space:nowrap}
.next-marker,.onair-marker,.aired-marker,.swapped-marker,.dropped-marker{display:inline-block;padding:.16rem .32rem;border-radius:.28rem;color:#fff;font-size:.58rem;font-weight:750;letter-spacing:.035em;white-space:nowrap}
.next-marker{background:var(--bs-success)}
.onair-marker{background:var(--bs-danger)}
.aired-marker{background:var(--bs-secondary)}
.swapped-marker{background:var(--bs-warning);color:#000}
.dropped-marker{background:var(--bs-dark)}
.log-note{color:var(--bs-secondary-color)}
.edit-cell{width:1%;white-space:nowrap}
.status-cell{width:95px;font-size:.76rem}
.queue-row.log-done td{opacity:.72}
.queue-row.log-dropped td{opacity:.55;text-decoration:line-through}
.live-marker{background:var(--bs-secondary)}
@media(max-width:767px){.rules-cell{min-width:160px}}
</style>