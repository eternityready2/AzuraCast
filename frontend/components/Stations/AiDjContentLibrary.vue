<template>
    <div class="content-library">
        <div class="cl-header">
            <h3 class="cl-title">
                {{ $gettext('Content Library') }}
            </h3>
            <p class="cl-subtitle mb-0">
                {{ $gettext('Manage reusable content for your AI DJ personalities') }}
            </p>
        </div>

        <!-- Tab Nav -->
        <div class="cl-tabs">
            <button
                v-for="tab in tabs"
                :key="tab.type"
                type="button"
                class="cl-tab"
                :class="{ 'cl-tab--active': activeTab === tab.type }"
                @click="setTab(tab.type)"
            >
                {{ tab.label }}
                <span
                    v-if="countByType(tab.type) > 0"
                    class="cl-tab-count"
                >{{ countByType(tab.type) }}</span>
                <span
                    v-if="!tab.is_builtin"
                    class="cl-tab-del"
                    :title="$gettext('Delete category')"
                    @click.stop="deleteCategory(tab)"
                >&times;</span>
            </button>
            <button
                v-if="!showNewCategoryInput"
                type="button"
                class="cl-tab cl-tab--add"
                @click="showNewCategoryInput = true"
            >
                + {{ $gettext('New Category') }}
            </button>
        </div>

        <!-- New Category Input -->
        <div v-if="showNewCategoryInput" class="cl-new-category">
            <input
                v-model="newCategoryName"
                type="text"
                class="form-control form-control-sm"
                :placeholder="$gettext('Category name (e.g. Prayer Requests)')"
                @keyup.enter="createCategory"
                @keyup.escape="cancelNewCategory"
            >
            <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="!newCategoryName.trim()"
                @click="createCategory"
            >
                {{ $gettext('Create') }}
            </button>
            <button
                type="button"
                class="btn btn-secondary btn-sm"
                @click="cancelNewCategory"
            >
                {{ $gettext('Cancel') }}
            </button>
        </div>

        <loading
            :loading="isLoading"
            lazy
        >
            <div class="cl-body">
                <!-- Variable help for song intro templates -->
                <div
                    v-if="activeTab === 'song_intro_template'"
                    class="var-help"
                >
                    <span class="var-help-label">{{ $gettext('Available variables:') }}</span>
                    <code
                        v-for="v in templateVars"
                        :key="v"
                        class="var-chip"
                        :title="$gettext('Click to copy')"
                        @click="copyVar(v)"
                    >{{ v }}</code>
                </div>

                <!-- Variable help for post-song templates -->
                <div
                    v-if="activeTab === 'post_song_template'"
                    class="var-help"
                >
                    <span class="var-help-label">{{ $gettext('Available variables:') }}</span>
                    <code
                        v-for="v in postSongVars"
                        :key="v"
                        class="var-chip"
                        :title="$gettext('Click to copy')"
                        @click="copyVar(v)"
                    >{{ v }}</code>
                </div>

                <!-- Search and bulk actions -->
                <div class="cl-search-bar">
                    <input
                        v-model="searchQuery"
                        type="text"
                        class="form-control form-control-sm"
                        :placeholder="$gettext('Search content…')"
                        @input="currentPage = 1"
                    >
                    <span class="cl-count-label">
                        {{ filteredItems.length }} {{ $gettext('items') }}
                    </span>
                </div>
                <div class="cl-bulk-bar" v-if="filteredItems.length > 0 && !editorOpen && !bulkImportOpen">
                    <label class="form-check-label me-2">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            :checked="selectedIds.size === activeItems.length && activeItems.length > 0"
                            @change="toggleSelectAll"
                        >
                        {{ $gettext('Select All') }}
                    </label>
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

                <!-- Bulk Import -->
                <div v-if="bulkImportOpen" class="cl-editor">
                    <div class="cl-editor-title">
                        {{ $gettext('Bulk Import') }}
                    </div>
                    <p class="form-text mt-0 mb-2">
                        {{ $gettext('Paste multiple items, one per line. Each line becomes a separate content entry.') }}
                    </p>
                    <textarea
                        v-model="bulkText"
                        class="form-control mb-2"
                        rows="8"
                        :placeholder="$gettext('Line 1\nLine 2\nLine 3…')"
                    />
                    <div class="btn-row">
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
                            @click="bulkImportOpen = false; bulkText = ''"
                        >
                            {{ $gettext('Cancel') }}
                        </button>
                    </div>
                </div>

                <!-- Item list -->
                <div
                    v-if="activeItems.length === 0 && !editorOpen"
                    class="cl-empty"
                >
                    {{ searchQuery ? $gettext('No matching items.') : $gettext('No items yet. Add one below.') }}
                </div>

                <div
                    v-else-if="activeItems.length > 0"
                    class="cl-list"
                >
                    <div
                        v-for="item in activeItems"
                        :key="item.id"
                        class="cl-item"
                        :class="{ 'cl-item--disabled': !item.is_enabled, 'cl-item--selected': selectedIds.has(item.id) }"
                    >
                        <input
                            type="checkbox"
                            class="form-check-input me-2 mt-1 flex-shrink-0"
                            :checked="selectedIds.has(item.id)"
                            @change="toggleSelect(item.id)"
                        >
                        <div class="cl-item-main">
                            <p class="cl-item-content">
                                {{ item.content }}
                            </p>
                            <span
                                v-if="item.reference"
                                class="cl-item-ref"
                            >{{ item.reference }}</span>
                        </div>

                        <div class="cl-item-actions">
                            <label class="toggle-switch" :title="item.is_enabled ? $gettext('Enabled') : $gettext('Disabled')">
                                <input
                                    type="checkbox"
                                    :checked="item.is_enabled"
                                    @change="toggleEnabled(item)"
                                >
                                <span class="slider" />
                            </label>

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
                                class="cl-confirm-del"
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
                    </div>
                </div>

                <!-- Pagination -->
                <div
                    v-if="totalPages > 1"
                    class="cl-pagination"
                >
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        :disabled="currentPage <= 1"
                        @click="currentPage--"
                    >
                        &laquo; {{ $gettext('Prev') }}
                    </button>
                    <span class="cl-page-info">
                        {{ currentPage }} / {{ totalPages }}
                    </span>
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        :disabled="currentPage >= totalPages"
                        @click="currentPage++"
                    >
                        {{ $gettext('Next') }} &raquo;
                    </button>
                </div>

                <!-- Editor form -->
                <div
                    v-if="editorOpen"
                    class="cl-editor"
                >
                    <div class="cl-editor-title">
                        {{ editingItem ? $gettext('Edit Item') : $gettext('Add Item') }}
                    </div>

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

                        <div class="mb-3 form-check">
                            <input
                                id="cl-enabled"
                                v-model="form.is_enabled"
                                type="checkbox"
                                class="form-check-input"
                            >
                            <label
                                class="form-check-label"
                                for="cl-enabled"
                            >{{ $gettext('Enabled') }}</label>
                        </div>

                        <div class="mb-3 form-check">
                            <input
                                id="cl-global"
                                v-model="form.is_global"
                                type="checkbox"
                                class="form-check-input"
                            >
                            <label
                                class="form-check-label"
                                for="cl-global"
                            >{{ $gettext('Global (all DJs)') }}</label>
                        </div>

                        <div class="btn-row">
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

                <!-- Add button (only when editor is closed) -->
                <div
                    v-if="!editorOpen"
                    class="cl-add-row"
                >
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        @click="openCreate"
                    >
                        + {{ addLabel }}
                    </button>
                </div>
            </div>
        </loading>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from 'vue';
import {useGettext} from 'vue3-gettext';
import Loading from '~/components/Common/Loading.vue';
import {useAxios} from '~/vendor/axios';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';

// --- Types ---

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

// --- Setup ---

const {$gettext} = useGettext();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {getStationApiUrl} = useApiRouter();

const listUrl = getStationApiUrl('/ai-dj-content');
const typesUrl = getStationApiUrl('/ai-dj-content/types');
const itemUrl = (id: number) => getStationApiUrl(`/ai-dj-content/${id}`);

// --- Tabs (loaded dynamically from API) ---

interface ContentTab {
    type: string;
    label: string;
    is_builtin: boolean;
}

const tabs = ref<ContentTab[]>([
    {type: 'song_intro_template',  label: $gettext('Song Intros'), is_builtin: true},
    {type: 'post_song_template',   label: $gettext('Post-Song'), is_builtin: true},
    {type: 'bible_verse',          label: $gettext('Bible Verses'), is_builtin: true},
    {type: 'joke',                 label: $gettext('Jokes'), is_builtin: true},
    {type: 'encouragement',        label: $gettext('Encouragements'), is_builtin: true},
    {type: 'inspiration',          label: $gettext('Inspiration'), is_builtin: true},
    {type: 'testimony',            label: $gettext('Testimonies'), is_builtin: true},
    {type: 'story',                label: $gettext('Stories'), is_builtin: true},
]);

const loadTypes = async (): Promise<void> => {
    try {
        const resp = await axios.get<ContentTab[]>(typesUrl.value);
        if (Array.isArray(resp.data) && resp.data.length > 0) {
            tabs.value = resp.data;
        }
    } catch {
        // Fall back to default tabs on error
    }
};

// --- New Category ---

const showNewCategoryInput = ref(false);
const newCategoryName = ref('');

const nameToSlug = (name: string): string => {
    return name.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
};

const createCategory = (): void => {
    const name = newCategoryName.value.trim();
    if (!name) return;

    const slug = nameToSlug(name);
    if (!slug || slug.length < 2) {
        notifyError($gettext('Category name must be at least 2 characters.'));
        return;
    }

    // Check if already exists
    if (tabs.value.some(t => t.type === slug)) {
        notifyError($gettext('A category with this name already exists.'));
        return;
    }

    // Add to tabs and switch to it
    tabs.value.push({type: slug, label: name, is_builtin: false});
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

// --- State ---

const isLoading = ref(true);
const isSaving = ref(false);
const isDeleting = ref(false);
const isBulkDeleting = ref(false);
const isBulkImporting = ref(false);
const bulkImportOpen = ref(false);
const bulkText = ref('');
const selectedIds = ref<Set<number>>(new Set());
const activeTab = ref('song_intro_template');
const items = ref<ContentItem[]>([]);
const editorOpen = ref(false);
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

// --- Computed ---

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

const needsReference = computed(() =>
    activeTab.value === 'bible_verse'
);

const referenceLabel = computed(() => $gettext('Reference (e.g. John 3:16)'));
const referencePlaceholder = computed(() => $gettext('John 3:16'));

const builtInPlaceholders: Record<string, string> = {
    'song_intro_template': 'e.g. Coming up next: {{artist}} with {{song}} on {{station_name}}',
    'post_song_template': 'e.g. That was {{prev_artist}} with {{prev_song}}. Coming up: {{next_artist}}',
    'bible_verse': 'Paste the verse text here…',
    'joke': 'Enter the joke…',
    'encouragement': 'Enter an encouraging message…',
    'inspiration': 'Enter an inspirational message…',
    'testimony': 'Enter a testimony…',
    'story': 'Enter a story…',
};

const contentPlaceholder = computed(() => {
    return $gettext(builtInPlaceholders[activeTab.value] ?? 'Enter content…');
});

const activeTabLabel = computed(() => {
    const tab = tabs.value.find(t => t.type === activeTab.value);
    return tab?.label ?? 'Item';
});

const addLabel = computed(() => {
    return $gettext('Add') + ' ' + activeTabLabel.value;
});

// --- Tab ---

const setTab = (type: string): void => {
    activeTab.value = type;
    searchQuery.value = '';
    currentPage.value = 1;
    closeEditor();
    deleteTarget.value = null;
};

// --- Helpers ---

const copyVar = (v: string): void => {
    void navigator.clipboard.writeText(v);
    notifySuccess($gettext('Copied!'));
};

// --- API ---

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

// --- Editor ---

const openCreate = (): void => {
    editingItem.value = null;
    form.value = defaultForm();
    form.value.type = activeTab.value;
    deleteTarget.value = null;
    editorOpen.value = true;
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
};

const closeEditor = (): void => {
    editorOpen.value = false;
    editingItem.value = null;
    form.value = defaultForm();
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

// --- Toggle enabled ---

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

// --- Delete ---

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

// --- Bulk Selection ---

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
        selectedIds.value = new Set(activeItems.value.map(i => i.id));
    }
};

// --- Bulk Delete ---

const bulkDeleteUrl = getStationApiUrl('/ai-dj-content/bulk-delete');

const bulkDelete = async (): Promise<void> => {
    if (selectedIds.value.size === 0) return;
    if (!confirm($gettext('Delete ' + selectedIds.value.size + ' selected items?'))) return;
    isBulkDeleting.value = true;
    try {
        await axios.post(bulkDeleteUrl.value, {ids: Array.from(selectedIds.value)});
        notifySuccess($gettext(selectedIds.value.size + ' items deleted.'));
        selectedIds.value = new Set();
        await loadItems();
    } catch {
        notifyError($gettext('Failed to delete items.'));
    } finally {
        isBulkDeleting.value = false;
    }
};

// --- Delete all in a category / delete a category ---

const deleteByTypeUrl = getStationApiUrl('/ai-dj-content/delete-by-type');

const deleteAllInCategory = async (): Promise<void> => {
    const label = activeTabLabel.value;
    const count = countByType(activeTab.value);
    if (count === 0) return;
    if (!confirm($gettext('Delete ALL ' + count + ' items in "' + label + '"? This cannot be undone.'))) return;
    isBulkDeleting.value = true;
    try {
        await axios.post(deleteByTypeUrl.value, {type: activeTab.value});
        notifySuccess($gettext('All items in ' + label + ' were deleted.'));
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
    if (tab.is_builtin) return;
    if (!confirm($gettext('Delete the "' + tab.label + '" category and all its content?'))) return;
    try {
        await axios.post(deleteByTypeUrl.value, {type: tab.type});
        notifySuccess($gettext('Category deleted.'));
        tabs.value = tabs.value.filter(t => t.type !== tab.type);
        if (activeTab.value === tab.type) {
            setTab(tabs.value[0]?.type ?? 'song_intro_template');
        }
        await loadItems();
    } catch {
        notifyError($gettext('Failed to delete category.'));
    }
};

// --- Bulk Import ---

const doBulkImport = async (): Promise<void> => {
    const lines = bulkText.value.split('\n').map(l => l.trim()).filter(l => l.length > 0);
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
    notifySuccess($gettext(created + ' items imported' + (failed > 0 ? ', ' + failed + ' failed' : '') + '.'));
    bulkImportOpen.value = false;
    bulkText.value = '';
    await loadItems();
    isBulkImporting.value = false;
};

// --- Init ---

onMounted(async () => {
    await Promise.all([loadTypes(), loadItems()]);
});
</script>

<style scoped lang="scss">
.content-library {
    padding: 0;
}

.cl-search-bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;

    input {
        max-width: 280px;
    }
}

.cl-count-label {
    font-size: 0.82rem;
    color: #888;
    white-space: nowrap;
}

.cl-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    margin: 0.75rem 0;
}

.cl-page-info {
    font-size: 0.85rem;
    color: #666;
}

.cl-tab--add {
    border-style: dashed;
    color: var(--bs-primary, #5a7fd4);
    font-size: 0.78rem;

    &:hover {
        background: var(--bs-primary, #5a7fd4);
        border-style: solid;
        color: #fff;
    }
}

.cl-new-category {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
    padding: 0.75rem;
    background: var(--bs-tertiary-bg, #16181f);
    border: 1px solid var(--bs-border-color, #2d3140);
    border-radius: 6px;

    input {
        max-width: 280px;
    }
}

.cl-header {
    margin-bottom: 1.25rem;
}

.cl-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0 0 0.25rem;
    color: var(--bs-body-color, #e0e0e0);
}

.cl-subtitle {
    font-size: 0.85rem;
    color: var(--bs-secondary-color, #888);
}

// Tab nav
.cl-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1.25rem;
    border-bottom: 1px solid var(--bs-border-color, #2d3140);
    padding-bottom: 0.75rem;
}

.cl-tab {
    background: none;
    border: 1px solid var(--bs-border-color, #2d3140);
    border-radius: 20px;
    padding: 0.3rem 0.8rem;
    font-size: 0.82rem;
    color: var(--bs-secondary-color, #888);
    cursor: pointer;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
    display: flex;
    align-items: center;
    gap: 0.35rem;

    &:hover {
        border-color: var(--bs-primary, #5a7fd4);
        color: var(--bs-primary, #5a7fd4);
    }

    &--active {
        background: var(--bs-primary, #5a7fd4);
        border-color: var(--bs-primary, #5a7fd4);
        color: #fff;

        .cl-tab-count {
            background: rgba(255, 255, 255, 0.25);
            color: #fff;
        }
    }
}

.cl-tab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    font-size: 0.7rem;
    font-weight: 600;
    background: var(--bs-border-color, #2d3140);
    color: var(--bs-secondary-color, #888);
}

.cl-tab-del {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    font-size: 0.95rem;
    line-height: 1;
    color: #e06a6a;
    cursor: pointer;

    &:hover {
        background: #e06a6a;
        color: #fff;
    }
}

// Variable help
.var-help {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.4rem;
    padding: 0.6rem 0.75rem;
    background: var(--bs-tertiary-bg, #16181f);
    border: 1px solid var(--bs-border-color, #2d3140);
    border-radius: 6px;
    margin-bottom: 1rem;
    font-size: 0.82rem;
}

.var-help-label {
    color: var(--bs-secondary-color, #888);
    margin-right: 0.25rem;
}

.var-chip {
    background: var(--bs-body-bg, #1a1d23);
    border: 1px solid var(--bs-border-color, #2d3140);
    border-radius: 4px;
    padding: 0.1rem 0.45rem;
    font-size: 0.78rem;
    color: var(--bs-primary, #5a7fd4);
    cursor: pointer;
    user-select: none;
    transition: background 0.15s;

    &:hover {
        background: var(--bs-primary, #5a7fd4);
        color: #fff;
        border-color: var(--bs-primary, #5a7fd4);
    }
}

// Item list
.cl-empty {
    color: var(--bs-secondary-color, #888);
    font-size: 0.9rem;
    padding: 1rem 0;
}

.cl-list {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    margin-bottom: 1rem;
}

.cl-item {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.75rem 1rem;
    background: var(--bs-tertiary-bg, #16181f);
    border: 1px solid var(--bs-border-color, #2d3140);
    border-radius: 6px;
    transition: opacity 0.2s;

    &--disabled {
        opacity: 0.5;
    }
}

.cl-item-main {
    flex: 1;
    min-width: 0;
}

.cl-item-content {
    margin: 0;
    font-size: 0.88rem;
    line-height: 1.5;
    color: var(--bs-body-color, #e0e0e0);
    word-break: break-word;
}

.cl-item-ref {
    font-size: 0.78rem;
    color: var(--bs-primary, #5a7fd4);
    margin-top: 0.2rem;
    display: block;
}

.cl-item-actions {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-shrink: 0;
}

.cl-confirm-del {
    display: flex;
    gap: 0.3rem;
}

// Toggle switch (matches AiDj.vue pattern)
.toggle-switch {
    position: relative;
    display: inline-block;
    width: 40px;
    height: 22px;
    cursor: pointer;

    input {
        opacity: 0;
        width: 0;
        height: 0;
        position: absolute;
    }

    .slider {
        position: absolute;
        inset: 0;
        background: var(--bs-secondary, #6c757d);
        border-radius: 24px;
        transition: background 0.2s;

        &::before {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            left: 3px;
            top: 3px;
            border-radius: 50%;
            background: #fff;
            transition: transform 0.2s;
        }
    }

    input:checked + .slider {
        background: var(--bs-primary, #5a7fd4);
    }

    input:checked + .slider::before {
        transform: translateX(18px);
    }
}

// Editor
.cl-editor {
    border: 1px solid var(--bs-border-color, #2d3140);
    border-radius: 8px;
    padding: 1rem 1.25rem;
    background: var(--bs-tertiary-bg, #16181f);
    margin-bottom: 1rem;
}

.cl-editor-title {
    font-size: 0.95rem;
    font-weight: 600;
    margin-bottom: 1rem;
    color: var(--bs-body-color, #e0e0e0);
}

.cl-add-row {
    margin-top: 0.5rem;
}

.btn-row {
    display: flex;
    gap: 0.5rem;
}

@media (max-width: 768px) {
    .cl-header {
        margin-bottom: 0.75rem;
    }

    .cl-tabs {
        gap: 0.35rem;
        margin-bottom: 0.75rem;
        padding-bottom: 0.5rem;
        overflow-x: auto;
        flex-wrap: nowrap;
        -webkit-overflow-scrolling: touch;
    }

    .cl-tab {
        white-space: nowrap;
        flex-shrink: 0;
        font-size: 0.75rem;
        padding: 0.25rem 0.6rem;
    }

    .cl-search-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 0.4rem;

        input {
            max-width: 100%;
        }
    }

    .cl-item {
        flex-direction: column;
        padding: 0.6rem 0.75rem;
        gap: 0.5rem;
    }

    .cl-item-actions {
        flex-wrap: wrap;
        gap: 0.3rem;
    }

    .var-help {
        padding: 0.5rem;
        font-size: 0.75rem;
    }

    .var-chip {
        font-size: 0.7rem;
        padding: 0.1rem 0.35rem;
    }

    .cl-editor {
        padding: 0.75rem;
    }
}
</style>
