<template>
    <div>
        <p class="text-muted small mb-3">
            {{ $gettext('Manage reusable content for your AI DJ personalities.') }}
        </p>

        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
            <ul class="nav nav-pills flex-wrap gap-1 mb-0">
                <li
                    v-for="tab in tabs"
                    :key="tab.type"
                    class="nav-item"
                >
                    <button
                        type="button"
                        class="nav-link d-inline-flex align-items-center gap-1"
                        :class="{active: activeTab === tab.type}"
                        @click="setTab(tab.type)"
                    >
                        {{ tab.label }}
                        <span
                            v-if="countByType(tab.type) > 0"
                            class="badge text-bg-secondary"
                        >{{ countByType(tab.type) }}</span>
                    </button>
                </li>
            </ul>

            <div class="d-flex gap-2 flex-shrink-0">
                <button
                    v-if="!showNewCategoryInput"
                    type="button"
                    class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                    @click="showNewCategoryInput = true"
                >
                    <icon-ic-baseline-add />
                    {{ $gettext('New Category') }}
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                    @click="manageCategoriesOpen = !manageCategoriesOpen"
                >
                    <icon-ic-baseline-settings />
                    {{ $gettext('Manage Categories') }}
                </button>
            </div>
        </div>

        <div
            v-if="manageCategoriesOpen"
            class="card card-body bg-body-tertiary mb-3"
        >
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h4 class="h6 mb-0">
                    {{ $gettext('Manage Categories') }}
                </h4>
                <button
                    type="button"
                    class="btn-close"
                    :aria-label="$gettext('Close')"
                    @click="manageCategoriesOpen = false"
                />
            </div>
            <p class="text-muted small mb-3">
                {{ $gettext("Song Intros and Post-Song are required by the AI DJ engine and can't be removed, but you can still clear their content. Other categories can be deleted entirely.") }}
            </p>
            <ul class="list-group">
                <li
                    v-for="tab in tabs"
                    :key="tab.type"
                    class="list-group-item d-flex align-items-center justify-content-between gap-2"
                >
                    <div class="d-flex align-items-center gap-2">
                        <span class="fw-semibold">{{ tab.label }}</span>
                        <span class="badge text-bg-secondary">
                            {{ $gettext('%{count} items', {count: countByType(tab.type)}) }}
                        </span>
                    </div>

                    <span
                        v-if="tab.is_required"
                        class="badge text-bg-secondary d-inline-flex align-items-center gap-1"
                    >
                        <icon-ic-baseline-lock />
                        {{ $gettext('System') }}
                    </span>
                    <button
                        v-else-if="categoryDeleteTarget?.type !== tab.type"
                        type="button"
                        class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1"
                        @click="categoryDeleteTarget = tab"
                    >
                        <icon-ic-baseline-delete />
                        {{ $gettext('Delete') }}
                    </button>
                    <div
                        v-else
                        class="d-flex gap-1"
                    >
                        <button
                            type="button"
                            class="btn btn-sm btn-danger"
                            @click="deleteCategory(tab)"
                        >
                            {{ $gettext('Confirm') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            @click="categoryDeleteTarget = null"
                        >
                            {{ $gettext('Cancel') }}
                        </button>
                    </div>
                </li>
            </ul>
        </div>

        <div
            v-if="showNewCategoryInput"
            class="input-group input-group-sm mb-3"
            style="max-width: 26rem;"
        >
            <input
                v-model="newCategoryName"
                type="text"
                class="form-control"
                :placeholder="$gettext('Category name (e.g. Prayer Requests)')"
                @keyup.enter="createCategory"
                @keyup.escape="cancelNewCategory"
            >
            <button
                type="button"
                class="btn btn-primary"
                :disabled="!newCategoryName.trim()"
                @click="createCategory"
            >
                {{ $gettext('Create') }}
            </button>
            <button
                type="button"
                class="btn btn-secondary"
                @click="cancelNewCategory"
            >
                {{ $gettext('Cancel') }}
            </button>
        </div>

        <loading
            v-if="!manageCategoriesOpen"
            :loading="isLoading"
            lazy
        >
            <div
                v-if="activeTab === 'song_intro_template'"
                class="mb-3 small"
            >
                <span class="text-muted me-2">{{ $gettext('Available variables:') }}</span>
                <button
                    v-for="v in templateVars"
                    :key="v"
                    type="button"
                    class="badge text-bg-secondary font-monospace fw-normal border-0 me-1"
                    :title="$gettext('Click to copy')"
                    @click="copyVar(v)"
                >{{ v }}</button>
            </div>

            <div
                v-if="activeTab === 'post_song_template'"
                class="mb-3 small"
            >
                <span class="text-muted me-2">{{ $gettext('Available variables:') }}</span>
                <button
                    v-for="v in postSongVars"
                    :key="v"
                    type="button"
                    class="badge text-bg-secondary font-monospace fw-normal border-0 me-1"
                    :title="$gettext('Click to copy')"
                    @click="copyVar(v)"
                >{{ v }}</button>
            </div>

            <div class="d-flex align-items-center gap-2 mb-3">
                <input
                    v-model="searchQuery"
                    type="text"
                    class="form-control form-control-sm"
                    style="max-width: 20rem;"
                    :placeholder="$gettext('Search content…')"
                    @input="currentPage = 1"
                >
                <span class="text-muted small text-nowrap">
                    {{ $gettext('%{count} items', {count: filteredItems.length}) }}
                </span>
            </div>

            <div
                v-if="filteredItems.length > 0 && !editorOpen && !bulkImportOpen"
                class="d-flex flex-wrap align-items-center gap-2 mb-3"
            >
                <div class="form-check mb-0 me-2">
                    <input
                        id="cl_select_all"
                        type="checkbox"
                        class="form-check-input"
                        :checked="selectedIds.size === activeItems.length && activeItems.length > 0"
                        @change="toggleSelectAll"
                    >
                    <label
                        class="form-check-label"
                        for="cl_select_all"
                    >
                        {{ $gettext('Select All') }}
                    </label>
                </div>
                <button
                    v-if="selectedIds.size > 0"
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    :disabled="isBulkDeleting"
                    @click="bulkDelete"
                >
                    {{ isBulkDeleting ? $gettext('Deleting…') : $gettext('Delete Selected') }} ({{ selectedIds.size }})
                </button>
                <button
                    v-if="!isActiveTabRequired"
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    :disabled="isBulkDeleting"
                    @click="deleteAllInCategory"
                >
                    {{ $gettext('Delete All in Category') }} ({{ countByType(activeTab) }})
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary ms-auto"
                    @click="bulkImportOpen = true"
                >
                    {{ $gettext('Bulk Import') }}
                </button>
            </div>

            <div
                v-if="bulkImportOpen"
                class="card card-body bg-body-tertiary mb-3"
            >
                <h4 class="h6">
                    {{ $gettext('Bulk Import') }}
                </h4>
                <p class="form-text mt-0 mb-2">
                    {{ $gettext('Paste multiple items, one per line. Each line becomes a separate content entry.') }}
                </p>
                <textarea
                    v-model="bulkText"
                    class="form-control mb-2"
                    rows="8"
                    :placeholder="$gettext('Line 1\nLine 2\nLine 3…')"
                />
                <div class="d-flex gap-2">
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="isBulkImporting || !bulkText.trim()"
                        @click="doBulkImport"
                    >
                        {{ isBulkImporting ? $gettext('Importing…') : $gettext('Import') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-secondary btn-sm"
                        @click="cancelBulkImport"
                    >
                        {{ $gettext('Cancel') }}
                    </button>
                </div>
            </div>

            <p
                v-if="activeItems.length === 0 && !editorOpen"
                class="text-muted text-center py-4"
            >
                {{ searchQuery ? $gettext('No matching items.') : $gettext('No items yet. Add one below.') }}
            </p>

            <ul
                v-else-if="activeItems.length > 0"
                class="list-group mb-3"
            >
                <li
                    v-for="item in activeItems"
                    :key="item.id"
                    class="list-group-item d-flex align-items-start gap-2"
                    :class="{'opacity-50': !item.is_enabled}"
                >
                    <input
                        type="checkbox"
                        class="form-check-input mt-1 flex-shrink-0"
                        :checked="selectedIds.has(item.id)"
                        @change="toggleSelect(item.id)"
                    >

                    <div class="flex-fill min-width-0">
                        <p class="mb-1">
                            {{ item.content }}
                        </p>
                        <span
                            v-if="item.reference"
                            class="badge text-bg-secondary"
                        >{{ item.reference }}</span>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                        <div
                            class="form-check form-switch mb-0"
                            :title="item.is_enabled ? $gettext('Enabled') : $gettext('Disabled')"
                        >
                            <input
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                :checked="item.is_enabled"
                                @change="toggleEnabled(item)"
                            >
                        </div>

                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            @click="openEdit(item)"
                        >
                            {{ $gettext('Edit') }}
                        </button>

                        <button
                            v-if="deleteTarget?.id !== item.id"
                            type="button"
                            class="btn btn-sm btn-outline-danger"
                            @click="confirmDelete(item)"
                        >
                            {{ $gettext('Delete') }}
                        </button>

                        <div
                            v-else
                            class="d-flex gap-1"
                        >
                            <button
                                type="button"
                                class="btn btn-sm btn-danger"
                                :disabled="isDeleting"
                                @click="doDelete"
                            >
                                {{ $gettext('Confirm') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-secondary"
                                @click="deleteTarget = null"
                            >
                                {{ $gettext('Cancel') }}
                            </button>
                        </div>
                    </div>
                </li>
            </ul>

            <nav
                v-if="totalPages > 1"
                class="d-flex align-items-center justify-content-center gap-3 mb-3"
                :aria-label="$gettext('Pagination')"
            >
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    :disabled="currentPage <= 1"
                    @click="currentPage--"
                >
                    <icon-bi-chevron-left />
                    {{ $gettext('Prev') }}
                </button>
                <span class="text-muted small">
                    {{ $gettext('%{current} / %{total}', {current: currentPage, total: totalPages}) }}
                </span>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    :disabled="currentPage >= totalPages"
                    @click="currentPage++"
                >
                    {{ $gettext('Next') }}
                    <icon-bi-chevron-right />
                </button>
            </nav>

            <div
                v-if="editorOpen"
                ref="editorEl"
                class="card card-body bg-body-tertiary mb-3"
            >
                <h4 class="h6">
                    {{ editingItem ? $gettext('Edit Item') : $gettext('Add Item') }}
                </h4>

                <form @submit.prevent="saveForm">
                    <div class="mb-3">
                        <label class="form-label">{{ $gettext('Content') }} <span class="text-danger">*</span></label>
                        <textarea
                            v-model="form.content"
                            class="form-control"
                            rows="4"
                            :placeholder="contentPlaceholder"
                            required
                        />
                    </div>

                    <div
                        v-if="needsReference"
                        class="mb-3"
                    >
                        <label class="form-label">{{ referenceLabel }}</label>
                        <input
                            v-model="form.reference"
                            type="text"
                            class="form-control"
                            :placeholder="referencePlaceholder"
                        >
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input
                            id="cl_item_enabled"
                            v-model="form.is_enabled"
                            type="checkbox"
                            class="form-check-input"
                            role="switch"
                        >
                        <label
                            class="form-check-label"
                            for="cl_item_enabled"
                        >{{ $gettext('Enabled') }}</label>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input
                            id="cl_item_global"
                            v-model="form.is_global"
                            type="checkbox"
                            class="form-check-input"
                            role="switch"
                        >
                        <label
                            class="form-check-label"
                            for="cl_item_global"
                        >{{ $gettext('Global (all DJs)') }}</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button
                            type="submit"
                            class="btn btn-primary btn-sm"
                            :disabled="isSaving || !form.content.trim()"
                        >
                            {{ isSaving ? $gettext('Saving…') : $gettext('Save') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            @click="closeEditor"
                        >
                            {{ $gettext('Cancel') }}
                        </button>
                    </div>
                </form>
            </div>

            <button
                v-if="!editorOpen"
                type="button"
                class="btn btn-primary btn-sm"
                @click="openCreate"
            >
                <icon-ic-baseline-add />
                {{ addLabel }}
            </button>
        </loading>
    </div>
</template>

<script setup lang="ts">
import {computed, nextTick, onMounted, ref} from 'vue';
import {useGettext} from 'vue3-gettext';
import Loading from '~/components/Common/Loading.vue';
import {useAxios} from '~/vendor/axios';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';

interface ContentItem {
    id: number;
    type: string;
    content: string;
    reference: string | null;
    is_enabled: boolean;
    is_global: boolean;
}

interface ContentForm {
    type: string;
    content: string;
    reference: string | null;
    is_enabled: boolean;
    is_global: boolean;
}

interface ContentTab {
    type: string;
    label: string;
    is_builtin: boolean;
    /** System categories essential to playback; their content can be cleared but the category cannot be removed. */
    is_required: boolean;
}

const {$gettext} = useGettext();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {getStationApiUrl} = useApiRouter();

const listUrl = getStationApiUrl('/ai-dj-content');
const typesUrl = getStationApiUrl('/ai-dj-content/types');
const deleteByTypeUrl = getStationApiUrl('/ai-dj-content/delete-by-type');
const restoreTypeUrl = getStationApiUrl('/ai-dj-content/restore-type');
const bulkDeleteUrl = getStationApiUrl('/ai-dj-content/bulk-delete');
const itemUrl = (id: number) => getStationApiUrl(`/ai-dj-content/${id}`);

/** Optional system categories that can be removed and later restored by name/slug. */
const BUILTIN_OPTIONAL_LABELS: Record<string, string> = {
    bible_verse: $gettext('Bible Verses'),
    joke: $gettext('Jokes'),
    encouragement: $gettext('Encouragements'),
    inspiration: $gettext('Inspiration'),
    testimony: $gettext('Testimonies'),
    story: $gettext('Stories'),
};

const REQUIRED_CATEGORY_TYPES = ['song_intro_template', 'post_song_template'];

const tabs = ref<ContentTab[]>([
    {type: 'song_intro_template', label: $gettext('Song Intros'), is_builtin: true, is_required: true},
    {type: 'post_song_template', label: $gettext('Post-Song'), is_builtin: true, is_required: true},
    {type: 'bible_verse', label: $gettext('Bible Verses'), is_builtin: true, is_required: false},
    {type: 'joke', label: $gettext('Jokes'), is_builtin: true, is_required: false},
    {type: 'encouragement', label: $gettext('Encouragements'), is_builtin: true, is_required: false},
    {type: 'inspiration', label: $gettext('Inspiration'), is_builtin: true, is_required: false},
    {type: 'testimony', label: $gettext('Testimonies'), is_builtin: true, is_required: false},
    {type: 'story', label: $gettext('Stories'), is_builtin: true, is_required: false},
]);

const loadTypes = async (): Promise<void> => {
    try {
        const resp = await axios.get<ContentTab[]>(typesUrl.value);
        if (Array.isArray(resp.data) && resp.data.length > 0) {
            tabs.value = resp.data.map((tab) => ({
                ...tab,
                is_required: tab.is_required ?? REQUIRED_CATEGORY_TYPES.includes(tab.type),
            }));
        }
    } catch {
        notifyError($gettext('Failed to load content categories; showing defaults.'));
    }
};

const showNewCategoryInput = ref(false);
const newCategoryName = ref('');
const manageCategoriesOpen = ref(false);
const categoryDeleteTarget = ref<ContentTab | null>(null);

const nameToSlug = (name: string): string => {
    return name.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
};

const createCategory = async (): Promise<void> => {
    const name = newCategoryName.value.trim();
    if (!name) return;

    let slug = nameToSlug(name);
    if (!slug || slug.length < 2) {
        notifyError($gettext('Category name must be at least 2 characters.'));
        return;
    }

    // A typed name matching a known built-in label/slug restores that category
    // (e.g. "Jokes" resolves to the `joke` slug) instead of creating a duplicate.
    const builtinMatch = Object.entries(BUILTIN_OPTIONAL_LABELS).find(
        ([type, label]) => type === slug || nameToSlug(label) === slug || label.toLowerCase() === name.toLowerCase()
    );
    if (builtinMatch) {
        slug = builtinMatch[0];
    }

    if (tabs.value.some((t) => t.type === slug)) {
        notifyError($gettext('A category with this name already exists.'));
        return;
    }

    const isBuiltinOptional = slug in BUILTIN_OPTIONAL_LABELS;

    try {
        await axios.post(restoreTypeUrl.value, {type: slug});
    } catch {
        notifyError($gettext('Failed to create category.'));
        return;
    }

    tabs.value.push(
        isBuiltinOptional
            ? {type: slug, label: BUILTIN_OPTIONAL_LABELS[slug], is_builtin: true, is_required: false}
            : {type: slug, label: name, is_builtin: false, is_required: false}
    );

    setTab(slug);
    cancelNewCategory();
    notifySuccess($gettext('Category created. Add content to start using it.'));
};

const cancelNewCategory = (): void => {
    showNewCategoryInput.value = false;
    newCategoryName.value = '';
};

const templateVars = ['{{dj_name}}', '{{artist}}', '{{song}}', '{{station_name}}'];
const postSongVars = ['{{dj_name}}', '{{prev_artist}}', '{{prev_song}}', '{{next_artist}}', '{{next_song}}', '{{station_name}}'];

const isLoading = ref(true);
const isSaving = ref(false);
const isDeleting = ref(false);
const isBulkDeleting = ref(false);
const isBulkImporting = ref(false);
const bulkImportOpen = ref(false);
const bulkText = ref('');
const selectedIds = ref<Set<number>>(new Set());
const activeTab = ref('song_intro_template');
const isActiveTabRequired = computed(() =>
    tabs.value.find((t) => t.type === activeTab.value)?.is_required === true
);
const items = ref<ContentItem[]>([]);
const editorOpen = ref(false);
const editorEl = ref<HTMLElement | null>(null);
const editingItem = ref<ContentItem | null>(null);
const deleteTarget = ref<ContentItem | null>(null);
const searchQuery = ref('');
const currentPage = ref(1);
const itemsPerPage = 25;

const defaultForm = (): ContentForm => ({
    type: activeTab.value,
    content: '',
    reference: null,
    is_enabled: true,
    is_global: false,
});

const form = ref<ContentForm>(defaultForm());

const filteredItems = computed(() => {
    let result = items.value.filter((i) => i.type === activeTab.value);
    if (searchQuery.value.trim()) {
        const q = searchQuery.value.toLowerCase();
        result = result.filter((i) =>
            i.content.toLowerCase().includes(q) ||
            (i.reference && i.reference.toLowerCase().includes(q))
        );
    }
    return result;
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredItems.value.length / itemsPerPage)));

const activeItems = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage;
    return filteredItems.value.slice(start, start + itemsPerPage);
});

const countByType = (type: string): number =>
    items.value.filter((i) => i.type === type).length;

const needsReference = computed(() => activeTab.value === 'bible_verse');

const referenceLabel = computed(() => $gettext('Reference (e.g. John 3:16)'));
const referencePlaceholder = computed(() => $gettext('John 3:16'));

const builtInPlaceholders: Record<string, string> = {
    song_intro_template: 'e.g. Coming up next: {{artist}} with {{song}} on {{station_name}}',
    post_song_template: 'e.g. That was {{prev_artist}} with {{prev_song}}. Coming up: {{next_artist}}',
    bible_verse: 'Paste the verse text here…',
    joke: 'Enter the joke…',
    encouragement: 'Enter an encouraging message…',
    inspiration: 'Enter an inspirational message…',
    testimony: 'Enter a testimony…',
    story: 'Enter a story…',
};

const contentPlaceholder = computed(() => {
    return $gettext(builtInPlaceholders[activeTab.value] ?? 'Enter content…');
});

const activeTabLabel = computed(() => {
    return tabs.value.find((t) => t.type === activeTab.value)?.label ?? 'Item';
});

const addLabel = computed(() => `${$gettext('Add')} ${activeTabLabel.value}`);

const setTab = (type: string): void => {
    activeTab.value = type;
    searchQuery.value = '';
    currentPage.value = 1;
    closeEditor();
    deleteTarget.value = null;
};

const copyVar = (v: string): void => {
    void navigator.clipboard.writeText(v);
    notifySuccess($gettext('Copied!'));
};

const loadItems = async (): Promise<void> => {
    isLoading.value = true;
    try {
        const resp = await axios.get<ContentItem[]>(listUrl.value);
        items.value = Array.isArray(resp.data) ? resp.data : [];
    } catch {
        notifyError($gettext('Failed to load content library.'));
    } finally {
        isLoading.value = false;
    }
};

const scrollToEditor = async (): Promise<void> => {
    await nextTick();
    editorEl.value?.scrollIntoView({behavior: 'smooth', block: 'center'});
};

const openCreate = (): void => {
    editingItem.value = null;
    form.value = defaultForm();
    form.value.type = activeTab.value;
    deleteTarget.value = null;
    editorOpen.value = true;
    void scrollToEditor();
};

const openEdit = (item: ContentItem): void => {
    editingItem.value = item;
    form.value = {
        type: item.type,
        content: item.content,
        reference: item.reference,
        is_enabled: item.is_enabled,
        is_global: item.is_global,
    };
    deleteTarget.value = null;
    editorOpen.value = true;
    void scrollToEditor();
};

const closeEditor = (): void => {
    editorOpen.value = false;
    editingItem.value = null;
    form.value = defaultForm();
};

const cancelBulkImport = (): void => {
    bulkImportOpen.value = false;
    bulkText.value = '';
};

const saveForm = async (): Promise<void> => {
    isSaving.value = true;
    try {
        if (editingItem.value) {
            await axios.put(itemUrl(editingItem.value.id).value, form.value);
            notifySuccess($gettext('Item updated.'));
        } else {
            await axios.post(listUrl.value, form.value);
            notifySuccess($gettext('Item added.'));
        }
        closeEditor();
        await loadItems();
    } catch {
        notifyError($gettext('Failed to save item.'));
    } finally {
        isSaving.value = false;
    }
};

const toggleEnabled = async (item: ContentItem): Promise<void> => {
    try {
        await axios.put(itemUrl(item.id).value, {
            ...item,
            is_enabled: !item.is_enabled,
        });
        item.is_enabled = !item.is_enabled;
    } catch {
        notifyError($gettext('Failed to update item.'));
    }
};

const confirmDelete = (item: ContentItem): void => {
    deleteTarget.value = item;
    editorOpen.value = false;
};

const doDelete = async (): Promise<void> => {
    if (!deleteTarget.value) return;
    isDeleting.value = true;
    try {
        await axios.delete(itemUrl(deleteTarget.value.id).value);
        notifySuccess($gettext('Item deleted.'));
        deleteTarget.value = null;
        await loadItems();
    } catch {
        notifyError($gettext('Failed to delete item.'));
    } finally {
        isDeleting.value = false;
    }
};

const toggleSelect = (id: number): void => {
    const s = new Set(selectedIds.value);
    if (s.has(id)) {
        s.delete(id);
    } else {
        s.add(id);
    }
    selectedIds.value = s;
};

const toggleSelectAll = (): void => {
    if (selectedIds.value.size === activeItems.value.length) {
        selectedIds.value = new Set();
    } else {
        selectedIds.value = new Set(activeItems.value.map((i) => i.id));
    }
};

const bulkDelete = async (): Promise<void> => {
    if (selectedIds.value.size === 0) return;
    if (!confirm($gettext('Delete %{count} selected items?', {count: selectedIds.value.size}))) return;

    isBulkDeleting.value = true;
    try {
        await axios.post(bulkDeleteUrl.value, {ids: Array.from(selectedIds.value)});
        notifySuccess($gettext('%{count} items deleted.', {count: selectedIds.value.size}));
        selectedIds.value = new Set();
        await loadItems();
    } catch {
        notifyError($gettext('Failed to delete items.'));
    } finally {
        isBulkDeleting.value = false;
    }
};

const deleteAllInCategory = async (): Promise<void> => {
    if (isActiveTabRequired.value) {
        return;
    }

    const label = activeTabLabel.value;
    const count = countByType(activeTab.value);
    if (count === 0) return;
    if (!confirm($gettext('Delete ALL %{count} items in "%{label}"? This cannot be undone.', {count, label}))) return;

    isBulkDeleting.value = true;
    try {
        await axios.post(deleteByTypeUrl.value, {type: activeTab.value, remove_category: false});
        notifySuccess($gettext('All items in %{label} were deleted.', {label}));
        selectedIds.value = new Set();
        currentPage.value = 1;
        await loadItems();
    } catch {
        notifyError($gettext('Failed to delete category items.'));
    } finally {
        isBulkDeleting.value = false;
    }
};

const deleteCategory = async (tab: ContentTab): Promise<void> => {
    if (tab.is_required) return;

    try {
        await axios.post(deleteByTypeUrl.value, {type: tab.type, remove_category: true});
        notifySuccess($gettext('Category deleted.'));
        tabs.value = tabs.value.filter((t) => t.type !== tab.type);
        if (activeTab.value === tab.type) {
            setTab(tabs.value[0]?.type ?? 'song_intro_template');
        }
        categoryDeleteTarget.value = null;
        await loadItems();
    } catch {
        notifyError($gettext('Failed to delete category.'));
    }
};

const doBulkImport = async (): Promise<void> => {
    const lines = bulkText.value.split('\n').map((l) => l.trim()).filter((l) => l.length > 0);
    if (lines.length === 0) return;

    isBulkImporting.value = true;
    let created = 0;
    let failed = 0;

    for (const line of lines) {
        try {
            await axios.post(listUrl.value, {
                type: activeTab.value,
                content: line,
                reference: null,
                is_enabled: true,
                is_global: false,
            });
            created++;
        } catch {
            failed++;
        }
    }

    notifySuccess(
        failed > 0
            ? $gettext('%{created} items imported, %{failed} failed.', {created, failed})
            : $gettext('%{created} items imported.', {created})
    );
    cancelBulkImport();
    await loadItems();
    isBulkImporting.value = false;
};

onMounted(async () => {
    await Promise.all([loadTypes(), loadItems()]);
});
</script>

<style scoped>
.list-group-item .btn-outline-danger:hover {
    color: var(--bs-white);
}
</style>
