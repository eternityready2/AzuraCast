<template>
    <section
        v-if="cards.length > 0"
        class="card media-review"
        aria-labelledby="hdr_media_review"
    >
        <div class="card-header">
            <div class="d-flex align-items-center flex-wrap gap-2">
                <h3
                    id="hdr_media_review"
                    class="card-title flex-fill"
                >
                    {{ $gettext('Review files') }}
                    <span class="badge text-bg-secondary ms-1">{{ cards.length }}</span>
                </h3>
                <div class="buttons">
                    <button
                        type="button"
                        class="btn btn-sm btn-secondary"
                        :disabled="saving"
                        :title="$gettext('Close the review without saving your changes. The uploaded files stay in your library.')"
                        @click="discard"
                    >
                        <icon-ic-close />
                        <span>{{ $gettext('Discard') }}</span>
                    </button>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        :disabled="saving"
                        @click="done"
                    >
                        <icon-ic-check />
                        <span>{{ $gettext('Done') }}</span>
                    </button>
                </div>
            </div>
            <p class="card-subtitle text-body-secondary small mt-1 mb-0">
                {{ $gettext('Check the details AzuraCast read from your files. Changes are saved to each file, so you can still edit them later with Edit.') }}
            </p>
        </div>

        <div class="card-body media-review-grid">
            <div
                v-for="card in cards"
                :key="card.item.id"
                class="review-card card"
                :class="{'border-primary': isDirty(card)}"
            >
                <div class="review-card-head">
                    <span
                        class="review-card-name"
                        :title="card.item.path"
                    >{{ card.item.name }}</span>
                    <button
                        v-if="card.form.type === 'music' && card.record"
                        type="button"
                        class="btn btn-link btn-sm p-0 review-icon-btn"
                        :disabled="card.lookup.running"
                        :title="$gettext('Find song info online (album, year, genre, label, ISRC, cover art)')"
                        @click="findOnline(card)"
                    >
                        <icon-ic-travel-explore />
                    </button>
                    <button
                        type="button"
                        class="btn btn-link btn-sm p-0 review-icon-btn"
                        :title="$gettext('Delete file')"
                        @click="deleteCard(card)"
                    >
                        <icon-ic-delete />
                    </button>
                </div>

                <div
                    v-if="card.loading"
                    class="review-card-loading"
                >
                    {{ $gettext('Loading...') }}
                </div>
                <div
                    v-else-if="card.error"
                    class="review-card-error text-danger"
                >
                    {{ card.error }}
                </div>
                <template v-else>
                    <div class="review-card-body">
                        <div class="review-card-art">
                            <img
                                v-if="artUrl(card)"
                                :src="artUrl(card) ?? undefined"
                                alt=""
                                loading="lazy"
                            >
                        </div>
                        <div class="review-card-fields">
                            <input
                                v-model="card.form.artist"
                                class="form-control form-control-sm"
                                type="text"
                                :placeholder="$gettext('Artist')"
                                :aria-label="$gettext('Artist')"
                            >
                            <input
                                v-model="card.form.album"
                                class="form-control form-control-sm"
                                type="text"
                                :placeholder="$gettext('Album')"
                                :aria-label="$gettext('Album')"
                            >
                            <input
                                v-model="card.form.title"
                                class="form-control form-control-sm"
                                type="text"
                                :placeholder="$gettext('Title')"
                                :aria-label="$gettext('Title')"
                            >
                            <input
                                v-model="card.form.year"
                                class="form-control form-control-sm"
                                type="text"
                                inputmode="numeric"
                                maxlength="10"
                                :placeholder="$gettext('Year')"
                                :aria-label="$gettext('Year')"
                            >
                        </div>
                    </div>
                    <div class="review-card-selects">
                        <select
                            v-model="card.form.categoryId"
                            class="form-select form-select-sm"
                            :class="{'is-placeholder': card.form.categoryId === null}"
                            :aria-label="$gettext('Categories')"
                        >
                            <option :value="null">
                                {{ $gettext('Categories') }}
                            </option>
                            <option
                                v-for="cat in mediaCategories"
                                :key="cat.id"
                                :value="cat.id"
                            >
                                {{ cat.name }}
                            </option>
                        </select>
                        <select
                            v-model="card.form.type"
                            class="form-select form-select-sm"
                            :aria-label="$gettext('Type')"
                        >
                            <option
                                v-for="opt in typeOptions"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.short }}
                            </option>
                        </select>
                    </div>

                    <div
                        v-if="card.lookup.running"
                        class="review-lookup small text-secondary"
                    >
                        {{ $gettext('Looking this song up online...') }}
                    </div>
                    <div
                        v-else-if="card.lookup.error"
                        class="review-lookup small text-danger"
                    >
                        {{ card.lookup.error }}
                    </div>
                    <div
                        v-else-if="card.lookup.result"
                        class="review-lookup"
                    >
                        <div
                            v-if="foundFields(card).length === 0"
                            class="small text-secondary"
                        >
                            {{ $gettext('Nothing new found online.') }}
                        </div>
                        <div
                            v-for="field in foundFields(card)"
                            :key="field"
                            class="review-lookup-row"
                        >
                            <span class="review-lookup-label">{{ lookupLabels[field] }}</span>
                            <img
                                v-if="field === 'art'"
                                class="review-lookup-art"
                                :src="card.lookup.result.fields.art.found ?? ''"
                                alt=""
                            >
                            <span
                                v-else
                                class="review-lookup-value"
                                :title="card.lookup.result.fields[field].found ?? ''"
                            >{{ card.lookup.result.fields[field].found }}</span>
                            <small
                                v-if="card.lookup.result.fields[field].source"
                                class="text-secondary"
                            >{{ card.lookup.result.fields[field].source }}</small>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary py-0 ms-auto"
                                :disabled="card.lookup.applying"
                                @click="useFound(card, field)"
                            >
                                {{ $gettext('Use') }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import {reactive, ref, watch} from "vue";
import {useTranslate} from "~/vendor/gettext";
import {useAxios} from "~/vendor/axios.ts";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useDialog} from "~/components/Common/Dialogs/useDialog.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {formatMediaType, getMediaTypeOptions, MediaTypeValue, normalizeMediaTypeForEditor} from "~/functions/mediaTypes.ts";
import IconIcDelete from "~icons/ic/baseline-delete";
import IconIcClose from "~icons/ic/baseline-close";
import IconIcCheck from "~icons/ic/baseline-check";
import IconIcTravelExplore from "~icons/ic/baseline-travel-explore";

export type ReviewItem = {
    id: number,
    unique_id: string,
    path: string,
    name: string,
};

type ReviewForm = {
    title: string,
    artist: string,
    album: string,
    year: string,
    genre: string,
    type: MediaTypeValue,
    categoryId: number | null,
};

type MediaRecord = {
    title: string | null,
    artist: string | null,
    album: string | null,
    genre: string | null,
    type: string | null,
    category_id: number | null,
    custom_fields: Record<string, string | null> | unknown[] | null,
    links: {art?: string},
};

type LookupFieldKey = 'album' | 'genre' | 'year' | 'label' | 'isrc' | 'writers' | 'iswc' | 'art';

type LookupResult = {
    fields: Record<LookupFieldKey, {current: string | null, found: string | null, source: string | null}>,
    sources: string[],
    errors: string[],
};

type BatchResponse<T> = {
    success: boolean,
    errors: string[],
    record: T | null,
};

type ReviewCard = {
    item: ReviewItem,
    record: MediaRecord | null,
    form: ReviewForm,
    saved: ReviewForm,
    loading: boolean,
    error: string | null,
    artVersion: number,
    lookup: {
        running: boolean,
        applying: boolean,
        error: string | null,
        result: LookupResult | null,
    },
};

const props = defineProps<{
    batchUrl: string,
    storageKey: string,
    mediaCategories: {id: number; name: string}[],
}>();

const emit = defineEmits<{
    (e: 'relist'): void,
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifyError, notifySuccess} = useNotify();
const {confirmDelete} = useDialog();
const {getStationApiUrl} = useApiRouter();

const typeOptions = getMediaTypeOptions($gettext).map((opt) => ({
    ...opt,
    short: formatMediaType(opt.value, $gettext),
}));

// Shown as suggestions under a card after "find online". ISWC is saved along with the writers.
const lookupKeys: LookupFieldKey[] = ['album', 'year', 'genre', 'label', 'isrc', 'writers', 'art'];
const lookupLabels: Record<LookupFieldKey, string> = {
    album: $gettext('Album'),
    genre: $gettext('Genre'),
    year: $gettext('Year'),
    label: $gettext('Record Label'),
    isrc: $gettext('ISRC'),
    writers: $gettext('Writers'),
    iswc: $gettext('ISWC'),
    art: $gettext('Cover Art'),
};
// These fill the card and are saved on Done; the rest are saved straight away.
const formLookupKeys: LookupFieldKey[] = ['album', 'year', 'genre'];

const cards = reactive<ReviewCard[]>([]);
const saving = ref(false);

const emptyForm = (): ReviewForm => ({
    title: '',
    artist: '',
    album: '',
    year: '',
    genre: '',
    type: 'music',
    categoryId: null,
});

const fileUrl = (card: ReviewCard) => getStationApiUrl(`/file/${card.item.id}`).value;

const customFieldsOf = (record: MediaRecord | null): Record<string, string | null> => {
    const fields = record?.custom_fields;
    return (fields && !Array.isArray(fields)) ? {...fields} : {};
};

const formFromRecord = (record: MediaRecord): ReviewForm => ({
    title: record.title ?? '',
    artist: record.artist ?? '',
    album: record.album ?? '',
    year: customFieldsOf(record).year ?? '',
    genre: record.genre ?? '',
    type: normalizeMediaTypeForEditor(record.type),
    categoryId: record.category_id ?? null,
});

const formKeys = Object.keys(emptyForm()) as (keyof ReviewForm)[];

const isDirty = (card: ReviewCard) =>
    formKeys.some((key) => card.form[key] !== card.saved[key]);

const artUrl = (card: ReviewCard): string | null => {
    const art = card.record?.links?.art;
    if (!art) {
        return null;
    }
    return `${art}${art.includes('?') ? '&' : '?'}v=${card.artVersion}`;
};

// The list survives a page reload until Done or Discard.
const persist = () => {
    try {
        localStorage.setItem(props.storageKey, JSON.stringify(cards.map((card) => card.item)));
    } catch {
        // Storage unavailable; the review just won't survive a reload.
    }
};

/** Load (or reload) a card from the server. Fields the user has changed are left alone. */
const loadCard = async (card: ReviewCard) => {
    card.loading = card.record === null;
    try {
        const {data} = await axios.get<MediaRecord>(fileUrl(card));
        const fresh = formFromRecord(data);
        const wasDirty = new Set(formKeys.filter((key) => card.form[key] !== card.saved[key]));

        card.record = data;
        formKeys.forEach((key) => {
            if (!wasDirty.has(key)) {
                (card.form as Record<string, unknown>)[key] = fresh[key];
            }
        });
        card.saved = fresh;
        card.artVersion++;
        card.error = null;
    } catch (e: unknown) {
        const status = (e as {response?: {status?: number}})?.response?.status;
        if (status === 404) {
            removeCard(card);
            return;
        }
        card.error = $gettext('Could not load this file.');
    } finally {
        card.loading = false;
    }
};

const removeCard = (card: ReviewCard) => {
    const index = cards.indexOf(card);
    if (index !== -1) {
        cards.splice(index, 1);
    }
    persist();
};

/** Add uploaded files to the review. */
const add = (items: ReviewItem[]) => {
    const known = new Set(cards.map((card) => card.item.id));
    items.filter((item) => !known.has(item.id)).forEach((item) => {
        cards.push({
            item,
            record: null,
            form: emptyForm(),
            saved: emptyForm(),
            loading: true,
            error: null,
            artVersion: 0,
            lookup: {running: false, applying: false, error: null, result: null},
        });
    });
    persist();

    cards.filter((card) => card.record === null).forEach((card) => {
        void loadCard(card);
    });
};

/** Save one card through the same endpoint as Edit Media, so it can be changed there later. */
const saveCard = async (card: ReviewCard): Promise<boolean> => {
    if (!card.record || !isDirty(card)) {
        return true;
    }

    const customFields = customFieldsOf(card.record);
    const year = card.form.year.trim();
    if (year !== '') {
        customFields.year = year;
    } else {
        delete customFields.year;
    }

    try {
        await axios.put(fileUrl(card), {
            title: card.form.title.trim() || null,
            artist: card.form.artist.trim() || null,
            album: card.form.album.trim() || null,
            genre: card.form.genre.trim() || null,
            type: card.form.type,
            category_id: card.form.categoryId,
            // Edit Media replaces all of a file's custom fields, so send the others back unchanged.
            custom_fields: customFields,
        });
        card.record.custom_fields = customFields;
        card.saved = {...card.form};
        return true;
    } catch {
        notifyError($gettext('Could not save %{file}.', {file: card.item.name}));
        return false;
    }
};

const findOnline = async (card: ReviewCard) => {
    card.lookup = {running: true, applying: false, error: null, result: null};

    // The lookup searches by the stored title and artist, so save edits first.
    if (!(await saveCard(card))) {
        card.lookup.running = false;
        return;
    }

    try {
        const {data} = await axios.put<BatchResponse<LookupResult>>(props.batchUrl, {
            do: 'lookup',
            files: [card.item.path],
            dirs: [],
        });

        if (data.record) {
            card.lookup.result = data.record;
            if (data.record.errors.length > 0 && foundFields(card).length === 0) {
                card.lookup.error = data.record.errors.join(' ');
            }
        } else {
            card.lookup.error = data.errors.join(' ') || $gettext('Lookup failed.');
        }
    } catch {
        card.lookup.error = $gettext('Lookup failed.');
    } finally {
        card.lookup.running = false;
    }
};

const currentValue = (card: ReviewCard, field: LookupFieldKey): string | null => {
    if (field === 'album' || field === 'year' || field === 'genre') {
        return card.form[field].trim() || null;
    }
    return card.lookup.result?.fields[field]?.current ?? null;
};

/** What was found online that differs from what the file has. */
const foundFields = (card: ReviewCard): LookupFieldKey[] => {
    const result = card.lookup.result;
    if (!result) {
        return [];
    }
    return lookupKeys.filter((field) => {
        const found = result.fields[field]?.found;
        if (!found) {
            return false;
        }
        return field === 'art' || found !== currentValue(card, field);
    });
};

const useFound = async (card: ReviewCard, field: LookupFieldKey) => {
    const result = card.lookup.result;
    const found = result?.fields[field]?.found;
    if (!result || !found) {
        return;
    }

    if (formLookupKeys.includes(field)) {
        (card.form as Record<string, unknown>)[field] = found;
        return;
    }

    // Label, ISRC, writers and cover art are saved straight away, the same way the lookup box saves them.
    card.lookup.applying = true;
    try {
        const fields: LookupFieldKey[] = (field === 'writers' && result.fields.iswc?.found)
            ? ['writers', 'iswc']
            : [field];
        const {data} = await axios.put<BatchResponse<{saved: Record<string, string[]>}>>(props.batchUrl, {
            do: 'lookup-apply',
            accepted: [{path: card.item.path, fields}],
        });

        if (data.errors.length > 0) {
            notifyError(data.errors.join(' '));
        }

        const saved = data.record?.saved?.[card.item.path] ?? [];
        saved.forEach((savedField) => {
            const row = result.fields[savedField as LookupFieldKey];
            if (row) {
                row.current = savedField === 'art' ? 'yes' : row.found;
            }
        });
        if (field === 'art' && saved.includes('art')) {
            result.fields.art.found = null;
        }

        // Pick up the custom fields just saved, so Done does not overwrite them.
        await loadCard(card);
    } catch {
        notifyError($gettext('Could not save the details found online.'));
    } finally {
        card.lookup.applying = false;
    }
};

const deleteCard = async (card: ReviewCard) => {
    const {value} = await confirmDelete({
        title: $gettext('Delete %{file}?', {file: card.item.name}),
        confirmButtonText: $gettext('Delete'),
    });
    if (!value) {
        return;
    }

    try {
        const {data} = await axios.put<BatchResponse<unknown>>(props.batchUrl, {
            do: 'delete',
            files: [card.item.path],
            dirs: [],
        });
        if (data.success === false && data.errors?.length) {
            notifyError(data.errors.join(' '));
            return;
        }
        removeCard(card);
        emit('relist');
    } catch {
        notifyError($gettext('Could not delete %{file}.', {file: card.item.name}));
    }
};

const discard = async () => {
    if (cards.some(isDirty)) {
        const {value} = await confirmDelete({
            title: $gettext('Discard your changes? The uploaded files stay in your library.'),
            confirmButtonText: $gettext('Discard'),
        });
        if (!value) {
            return;
        }
    }
    cards.splice(0, cards.length);
    persist();
};

const done = async () => {
    saving.value = true;
    const results = await Promise.all(cards.map((card) => saveCard(card)));
    saving.value = false;

    const savedCount = results.filter(Boolean).length;
    if (results.every(Boolean)) {
        cards.splice(0, cards.length);
        persist();
    } else {
        // Keep the ones that failed so nothing is lost.
        cards.filter((card) => !isDirty(card)).forEach(removeCard);
    }

    if (savedCount > 0) {
        notifySuccess($gettext('Review saved.'));
    }
    emit('relist');
};

// Restore a review that was open before a reload.
watch(
    () => props.storageKey,
    (key) => {
        cards.splice(0, cards.length);
        try {
            const stored = JSON.parse(localStorage.getItem(key) ?? '[]');
            if (Array.isArray(stored)) {
                add(stored as ReviewItem[]);
            }
        } catch {
            // Nothing stored.
        }
    },
    {immediate: true}
);

defineExpose({
    add
});
</script>

<style lang="scss" scoped>
.media-review {
    margin-bottom: 1rem;
}

.media-review-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 24rem), 1fr));
    gap: 0.75rem;
    background: var(--bs-tertiary-bg);
}

.review-card {
    padding: 0.5rem;
}

.review-card-head {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    margin-bottom: 0.4rem;
    font-size: 0.85rem;
}

.review-card-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.review-icon-btn {
    color: var(--bs-secondary-color);
    line-height: 1;

    &:hover {
        color: var(--bs-body-color);
    }

    svg {
        width: 1.2rem;
        height: 1.2rem;
    }
}

.review-card-body {
    display: grid;
    grid-template-columns: 3.75rem minmax(0, 1fr);
    gap: 0.35rem;
}

.review-card-art {
    width: 3.75rem;
    height: 3.75rem;
    align-self: center;
    border-radius: var(--bs-border-radius-sm);
    overflow: hidden;
    background: var(--bs-secondary-bg);

    img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
}

.review-card-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.35rem;
}

.review-card-selects {
    display: grid;
    grid-template-columns: calc(3.75rem + 0.35rem + (100% - 3.75rem - 0.7rem) / 2) 1fr;
    gap: 0.35rem;
    margin-top: 0.35rem;

    .is-placeholder {
        color: var(--bs-secondary-color);
    }
}

.review-card-loading,
.review-card-error {
    padding: 1rem 0;
    font-size: 0.85rem;
    text-align: center;
}

.review-lookup {
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px dashed var(--bs-border-color);
}

.review-lookup-row {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.8rem;
    padding: 0.15rem 0;
}

.review-lookup-label {
    flex: 0 0 5.5rem;
    color: var(--bs-secondary-color);
}

.review-lookup-value {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.review-lookup-art {
    width: 2.5rem;
    height: 2.5rem;
    object-fit: cover;
    border-radius: var(--bs-border-radius-sm);
}
</style>
