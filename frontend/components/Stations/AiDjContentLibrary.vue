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

                <!-- Item list -->
                <div
                    v-if="activeItems.length === 0 && !editorOpen"
                    class="cl-empty"
                >
                    {{ $gettext('No items yet. Add one below.') }}
                </div>

                <div
                    v-else-if="activeItems.length > 0"
                    class="cl-list"
                >
                    <div
                        v-for="item in activeItems"
                        :key="item.id"
                        class="cl-item"
                        :class="{ 'cl-item--disabled': !item.is_enabled }"
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
const itemUrl = (id: number) => getStationApiUrl(`/ai-dj-content/${id}`);

// --- Tabs ---

const tabs = computed(() => [
    {type: 'song_intro_template', label: $gettext('Song Intros')},
    {type: 'bible_verse',         label: $gettext('Bible Verses')},
    {type: 'joke',                label: $gettext('Jokes')},
    {type: 'encouragement',       label: $gettext('Encouragements')},
    {type: 'testimony',           label: $gettext('Testimonies')},
    {type: 'story',               label: $gettext('Stories')},
]);

const templateVars = ['{{dj_name}}', '{{artist}}', '{{song}}', '{{station_name}}'];

// --- State ---

const isLoading = ref(true);
const isSaving = ref(false);
const isDeleting = ref(false);
const activeTab = ref('song_intro_template');
const items = ref<ContentItem[]>([]);
const editorOpen = ref(false);
const editingItem = ref<ContentItem | null>(null);
const deleteTarget = ref<ContentItem | null>(null);

const defaultForm = (): ContentForm => ({
    type: activeTab.value,
    content: '',
    reference: null,
    is_enabled: true,
    is_global: false,
});

const form = ref<ContentForm>(defaultForm());

// --- Computed ---

const activeItems = computed(() =>
    items.value.filter((i) => i.type === activeTab.value)
);

const countByType = (type: string): number =>
    items.value.filter((i) => i.type === type).length;

const needsReference = computed(() =>
    activeTab.value === 'bible_verse'
);

const referenceLabel = computed(() => $gettext('Reference (e.g. John 3:16)'));
const referencePlaceholder = computed(() => $gettext('John 3:16'));

const contentPlaceholder = computed(() => {
    switch (activeTab.value) {
        case 'song_intro_template':
            return $gettext('e.g. Coming up next: {{artist}} with {{song}} on {{station_name}}');
        case 'bible_verse':
            return $gettext('Paste the verse text here…');
        case 'joke':
            return $gettext('Enter the joke…');
        case 'encouragement':
            return $gettext('Enter an encouraging message…');
        case 'testimony':
            return $gettext('Enter a testimony…');
        case 'story':
            return $gettext('Enter a story…');
        default:
            return '';
    }
});

const addLabel = computed(() => {
    switch (activeTab.value) {
        case 'song_intro_template': return $gettext('Add Song Intro');
        case 'bible_verse':         return $gettext('Add Bible Verse');
        case 'joke':                return $gettext('Add Joke');
        case 'encouragement':       return $gettext('Add Encouragement');
        case 'testimony':           return $gettext('Add Testimony');
        case 'story':               return $gettext('Add Story');
        default:                    return $gettext('Add Item');
    }
});

// --- Tab ---

const setTab = (type: string): void => {
    activeTab.value = type;
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

// --- Init ---

onMounted(async () => {
    await loadItems();
});
</script>

<style scoped lang="scss">
.content-library {
    padding: 0;
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

@media (max-width: 600px) {
    .cl-item {
        flex-direction: column;
    }

    .cl-item-actions {
        flex-wrap: wrap;
    }
}
</style>
