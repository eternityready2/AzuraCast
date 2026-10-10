<template>
    <modal
        id="metadata_lookup"
        ref="$modal"
        size="xl"
        centered
        :busy="loading"
        :title="$gettext('Find Song Info Online')"
        @hidden="onHidden"
    >
        <p class="mb-3">
            {{ $gettext('This looks each ticked song up online and shows what it found (album, record label, year, genre, ISRC, songwriters, cover art) next to what the song has now. Nothing is changed until you tick what you want and click Save.') }}
        </p>

        <div class="lookup-sources mb-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="fw-semibold">{{ $gettext('Sources, in order:') }}</span>
                <span class="badge text-bg-success">MusicBrainz</span>
                <span
                    class="badge"
                    :class="settings.has_discogs_token ? 'text-bg-success' : 'text-bg-secondary'"
                >Discogs{{ settings.has_discogs_token ? '' : ` (${$gettext('no token')})` }}</span>
                <span
                    class="badge"
                    :class="settings.has_lastfm_key ? 'text-bg-success' : 'text-bg-secondary'"
                >Last.fm{{ settings.has_lastfm_key ? '' : ` (${$gettext('no key')})` }}</span>
            </div>

            <div class="input-group input-group-sm mt-2 lookup-token">
                <input
                    v-model="discogsToken"
                    type="password"
                    class="form-control"
                    autocomplete="off"
                    :placeholder="settings.has_discogs_token
                        ? $gettext('Discogs token saved. Paste a new one to replace it.')
                        : $gettext('Paste your Discogs personal access token')"
                >
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="discogsToken.trim() === ''"
                    @click="saveSettings({discogs_token: discogsToken})"
                >
                    {{ $gettext('Save Token') }}
                </button>
            </div>
            <div class="form-text">
                {{ $gettext('The token is free: discogs.com, Settings, Developers, Generate new token. The Last.fm key is set under Administration, System Settings, Services.') }}
            </div>

            <div class="form-check mt-2">
                <input
                    id="lookup_on_upload"
                    class="form-check-input"
                    type="checkbox"
                    :checked="settings.on_upload"
                    @change="saveSettings({on_upload: ($event.target as HTMLInputElement).checked})"
                >
                <label
                    class="form-check-label"
                    for="lookup_on_upload"
                >
                    {{ $gettext('Look up new music uploads by themselves') }}
                </label>
                <div class="form-text">
                    {{ $gettext('Fills in only what an uploaded track is missing, and never the genre.') }}
                </div>
            </div>
        </div>

        <div
            v-if="!loading && tracks.length === 0"
            class="alert alert-info mb-0"
        >
            {{ $gettext('None of the selected files can be looked up. A lookup needs a file typed Music with a title and an artist.') }}
        </div>

        <template v-else-if="tracks.length > 0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span>
                    <strong>{{ doneCount }}</strong> / {{ tracks.length }} {{ $gettext('tracks looked up') }}
                </span>
                <span
                    v-if="skipped > 0"
                    class="small text-body-secondary"
                >
                    {{ $gettext('%{count} skipped: not music, or no title or artist.', {count: String(skipped)}) }}
                </span>
                <span
                    v-if="running"
                    class="spinner-border spinner-border-sm"
                    role="status"
                />
                <button
                    v-if="running"
                    type="button"
                    class="btn btn-sm btn-outline-secondary ms-auto"
                    @click="running = false"
                >
                    {{ $gettext('Stop') }}
                </button>
                <button
                    v-else-if="doneCount < tracks.length"
                    type="button"
                    class="btn btn-sm btn-primary ms-auto"
                    @click="run"
                >
                    {{ doneCount === 0 ? $gettext('Start Lookup') : $gettext('Continue') }}
                </button>
            </div>

            <div class="small text-body-secondary mb-2">
                {{ $gettext('Tick what you want saved. A detail the track is missing is ticked for you; one it already has, and every genre, is left for you to decide. Nothing is saved until you click Save.') }}
            </div>

            <div class="table-responsive lookup-results">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ $gettext('Track') }}</th>
                            <th
                                v-for="field in fieldKeys"
                                :key="field"
                            >
                                {{ fieldLabels[field] }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="track in tracks"
                            :key="track.path"
                        >
                            <td class="lookup-track">
                                <strong>{{ track.title }}</strong>
                                <div class="small text-body-secondary">
                                    {{ track.artist }}
                                </div>
                                <div
                                    v-if="track.error"
                                    class="small text-danger"
                                >
                                    {{ track.error }}
                                </div>
                                <div
                                    v-else-if="track.result && !hasFound(track)"
                                    class="small text-body-secondary"
                                >
                                    {{ $gettext('Nothing found.') }}
                                </div>
                            </td>
                            <td
                                v-for="field in fieldKeys"
                                :key="field"
                                class="lookup-field"
                            >
                                <template v-if="track.result?.fields[field]?.found">
                                    <label class="d-flex align-items-start gap-1 mb-0">
                                        <input
                                            v-model="track.accepted"
                                            class="form-check-input mt-1 flex-shrink-0"
                                            type="checkbox"
                                            :value="field"
                                        >
                                        <span>
                                            <img
                                                v-if="field === 'art'"
                                                :src="track.result.fields[field].found ?? ''"
                                                class="lookup-art"
                                                alt=""
                                                loading="lazy"
                                            >
                                            <span v-else>{{ track.result.fields[field].found }}</span>
                                            <span
                                                v-if="field === 'writers' && track.result.fields.iswc?.found"
                                                class="d-block small"
                                            >ISWC {{ track.result.fields.iswc.found }}</span>
                                            <span class="d-block small text-body-secondary">
                                                {{ track.result.fields[field].source }}
                                            </span>
                                        </span>
                                    </label>
                                    <div class="small text-body-secondary">
                                        {{ currentLabel(field, track.result.fields[field].current) }}
                                    </div>
                                </template>
                                <span
                                    v-else-if="track.result"
                                    class="text-body-secondary"
                                >-</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <template #modal-footer>
            <button
                type="button"
                class="btn btn-secondary"
                @click="hide"
            >
                {{ $gettext('Close') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="saving || acceptedCount === 0"
                @click="save"
            >
                {{ $gettext('Save %{count} Ticked', {count: String(acceptedCount)}) }}
            </button>
        </template>
    </modal>
</template>

<script setup lang="ts">
import {computed, ref, useTemplateRef} from "vue";
import Modal from "~/components/Common/Modal.vue";
import {useHasModal} from "~/functions/useHasModal.ts";
import {useTranslate} from "~/vendor/gettext.ts";
import {useAxios} from "~/vendor/axios.ts";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";

type LookupFieldKey = 'album' | 'genre' | 'year' | 'label' | 'isrc' | 'writers' | 'iswc' | 'art';

type LookupField = {
    current: string | null,
    found: string | null,
    source: string | null,
};

type LookupResult = {
    fields: Record<LookupFieldKey, LookupField>,
    sources: string[],
    errors: string[],
};

type LookupTrack = {
    path: string,
    title: string,
    artist: string,
    result: LookupResult | null,
    error: string | null,
    accepted: LookupFieldKey[],
};

type LookupSettings = {
    has_discogs_token: boolean,
    has_lastfm_key: boolean,
    on_upload: boolean,
};

type LookupList = LookupSettings & {
    tracks: {path: string, title: string, artist: string}[],
    skipped: number,
};

type LookupBatchResponse<T> = {
    success: boolean,
    errors: string[],
    record: T | null,
};

const props = defineProps<{
    currentDirectory: string,
    batchUrl: string,
}>();

const emit = defineEmits<{
    (e: 'relist'): void,
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifyError, notifySuccess} = useNotify();

// The columns of the review. A song's ISWC has no column of its own: it is
// shown under the writers and saved with them.
const fieldKeys: LookupFieldKey[] = ['album', 'genre', 'year', 'label', 'isrc', 'writers', 'art'];
const fieldLabels: Record<LookupFieldKey, string> = {
    album: $gettext('Album'),
    genre: $gettext('Genre'),
    year: $gettext('Year'),
    label: $gettext('Record Label'),
    isrc: $gettext('ISRC'),
    writers: $gettext('Writers'),
    iswc: $gettext('ISWC'),
    art: $gettext('Cover Art'),
};

// How many tracks go into one save, so a request never waits on too many cover downloads.
const SAVE_CHUNK = 10;

const loading = ref(false);
const running = ref(false);
const saving = ref(false);
const tracks = ref<LookupTrack[]>([]);
const skipped = ref(0);
const discogsToken = ref('');
const settings = ref<LookupSettings>({
    has_discogs_token: false,
    has_lastfm_key: false,
    on_upload: false,
});

const doneCount = computed(() => tracks.value.filter((track) => track.result !== null || track.error !== null).length);
const acceptedCount = computed(() => tracks.value.reduce((sum, track) => sum + track.accepted.length, 0));

const $modal = useTemplateRef('$modal');
const {show, hide} = useHasModal($modal);

const hasFound = (track: LookupTrack): boolean =>
    fieldKeys.some((field) => !!track.result?.fields[field]?.found);

const currentLabel = (field: LookupFieldKey, current: string | null): string => {
    if (current === null) {
        return $gettext('Now: empty');
    }
    return field === 'art'
        ? $gettext('Now: has cover art')
        : `${$gettext('Now:')} ${current}`;
};

const applySettings = (record: LookupSettings) => {
    settings.value = {
        has_discogs_token: record.has_discogs_token,
        has_lastfm_key: record.has_lastfm_key,
        on_upload: record.on_upload,
    };
};

const saveSettings = async (changes: {discogs_token?: string, on_upload?: boolean}) => {
    try {
        const {data} = await axios.put<LookupBatchResponse<LookupSettings>>(props.batchUrl, {
            do: 'lookup-settings',
            ...changes,
        });
        if (data.record) {
            applySettings(data.record);
        }
        discogsToken.value = '';
        notifySuccess($gettext('Lookup settings saved.'));
    } catch {
        notifyError($gettext('Could not save the lookup settings.'));
    }
};

const open = async (files: string[], directories: string[]) => {
    tracks.value = [];
    skipped.value = 0;
    running.value = false;
    loading.value = true;
    show();

    try {
        const {data} = await axios.put<LookupBatchResponse<LookupList>>(props.batchUrl, {
            do: 'lookup-list',
            current_directory: props.currentDirectory,
            files: files,
            dirs: directories,
        });

        if (data.record) {
            applySettings(data.record);
            skipped.value = data.record.skipped;
            tracks.value = data.record.tracks.map((track) => ({
                ...track,
                result: null,
                error: null,
                accepted: [],
            }));
        }
    } catch {
        notifyError($gettext('Could not list the selected files.'));
        hide();
    } finally {
        loading.value = false;
    }

    // One track needs no second click.
    if (tracks.value.length === 1) {
        void run();
    }
};

// One track at a time: the sources allow about one request a second.
const run = async () => {
    running.value = true;

    for (const track of tracks.value) {
        if (!running.value) {
            break;
        }
        if (track.result !== null || track.error !== null) {
            continue;
        }

        try {
            const {data} = await axios.put<LookupBatchResponse<LookupResult>>(props.batchUrl, {
                do: 'lookup',
                files: [track.path],
                dirs: [],
            });

            if (data.record) {
                track.result = data.record;
                // Ticked for the operator: what the track is missing. A genre
                // found online is only ever a suggestion.
                track.accepted = fieldKeys.filter((field) => {
                    const values = data.record?.fields[field];
                    return field !== 'genre' && !!values?.found && values.current === null;
                });
                if (data.record.errors.length > 0) {
                    track.error = data.record.errors.join(' ');
                }
            } else {
                track.error = data.errors.join(' ') || $gettext('Lookup failed.');
            }
        } catch {
            track.error = $gettext('Lookup failed.');
        }
    }

    running.value = false;
};

const save = async () => {
    saving.value = true;

    const accepted = tracks.value
        .filter((track) => track.accepted.length > 0)
        .map((track) => ({
            path: track.path,
            fields: track.accepted.includes('writers') && track.result?.fields.iswc?.found
                ? [...track.accepted, 'iswc']
                : track.accepted,
        }));

    let savedTracks = 0;
    const errors: string[] = [];

    try {
        for (let i = 0; i < accepted.length; i += SAVE_CHUNK) {
            const chunk = accepted.slice(i, i + SAVE_CHUNK);
            const {data} = await axios.put<LookupBatchResponse<{saved: Record<string, string[]>}>>(
                props.batchUrl,
                {do: 'lookup-apply', accepted: chunk}
            );

            errors.push(...data.errors);

            const saved = data.record?.saved ?? {};
            for (const track of tracks.value) {
                const fields = saved[track.path];
                if (!fields || !track.result) {
                    continue;
                }
                savedTracks++;
                // What was saved is what the track has now.
                for (const field of fields as LookupFieldKey[]) {
                    track.result.fields[field].current = field === 'art' ? 'yes' : track.result.fields[field].found;
                }
                track.accepted = track.accepted.filter((field) => !fields.includes(field));
            }
        }

        if (errors.length > 0) {
            notifyError(errors.join(' '));
        }
        if (savedTracks > 0) {
            notifySuccess($gettext('Details saved for %{count} track(s).', {count: String(savedTracks)}));
            emit('relist');
        }
    } catch {
        notifyError($gettext('Could not save the details.'));
    } finally {
        saving.value = false;
    }
};

const onHidden = () => {
    running.value = false;
};

defineExpose({open});
</script>

<style scoped>
.lookup-sources{padding:.7rem .85rem;border-radius:.6rem;background:var(--bs-tertiary-bg)}
.lookup-token{max-width:34rem}
.lookup-results{max-height:55vh;overflow-y:auto}
.lookup-results thead th{position:sticky;top:0;background:var(--bs-body-bg);font-size:.78rem}
.lookup-track{min-width:12rem}
.lookup-field{min-width:8rem;font-size:.84rem}
.lookup-art{width:56px;height:56px;border-radius:.3rem;object-fit:cover}
</style>
