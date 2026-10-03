<template>
    <div class="px-3 pt-3">
        <div class="input-group input-group-sm">
            <span class="input-group-text">
                <icon-ic-search />
            </span>
            <input
                v-model="query"
                type="search"
                class="form-control"
                :placeholder="$gettext('Search')"
                :aria-label="$gettext('Search pages, settings and content')"
                @focus="loadPageText"
                @keydown.esc="query = ''"
                @keydown.enter.prevent="openFirstResult"
            >
        </div>
    </div>

    <template v-if="isSearching">
        <p
            v-if="results.length === 0"
            class="px-3 pt-3 mb-0 text-body-secondary small"
        >
            {{ isSearchingData ? $gettext('Searching...') : $gettext('Nothing matches your search.') }}
        </p>

        <ul
            v-else
            class="navdrawer-nav"
        >
            <li
                v-for="result in results"
                :key="result.key"
                class="nav-item"
            >
                <router-link
                    v-if="isRouteLink(result.url)"
                    :to="result.url"
                    class="nav-link sidebar-search-result"
                >
                    <span class="might-overflow">{{ result.label }}</span>
                    <small class="might-overflow text-body-secondary">{{ result.trail }}</small>
                </router-link>
                <a
                    v-else
                    :href="result.url"
                    :target="result.external ? '_blank' : undefined"
                    class="nav-link sidebar-search-result"
                >
                    <span class="might-overflow">{{ result.label }}</span>
                    <small class="might-overflow text-body-secondary">{{ result.trail }}</small>
                </a>
            </li>
        </ul>
    </template>

    <ul
        v-else
        class="navdrawer-nav"
    >
        <li
            v-for="category in menu"
            :key="category.key"
            class="nav-item"
        >
            <router-link
                v-if="isRouteLink(category.url)"
                :class="getLinkClass(category)"
                :to="category.url"
                class="nav-link"
            >
                <component
                    v-if="category.icon"
                    :is="category.icon()"
                    class="navdrawer-nav-icon"
                />
                <span class="might-overflow">{{ category.label }}</span>
            </router-link>
            <a
                v-else
                v-bind="getCategoryLink(category)"
                class="nav-link"
                :class="getLinkClass(category)"
            >
                <component
                    v-if="category.icon"
                    :is="category.icon()"
                    class="navdrawer-nav-icon"
                />
                <span class="might-overflow">{{ category.label }}</span>

                <icon-ic-open-in-new
                    v-if="category.external"
                    class="sm ms-2"
                    :aria-label="$gettext('External')"
                />
            </a>

            <div
                v-if="category.items"
                :id="'sidebar-submenu-'+category.key"
                class="collapse pb-2"
                :class="(isActiveItem(category)) ? 'show' : ''"
            >
                <ul class="navdrawer-nav">
                    <li
                        v-for="item in category.items"
                        :key="item.key"
                        class="nav-item"
                    >
                        <router-link
                            v-if="isRouteLink(item.url)"
                            :to="item.url"
                            class="nav-link ps-4 py-2"
                            :class="getLinkClass(item)"
                            :title="item.title"
                        >
                            <span class="might-overflow">{{ item.label }}</span>
                        </router-link>
                        <a
                            v-else
                            class="nav-link ps-4 py-2"
                            :class="item.class"
                            :href="item.url"
                            :target="(item.external) ? '_blank' : ''"
                            :title="item.title"
                        >
                            <span class="might-overflow">{{ item.label }}</span>

                            <icon-ic-open-in-new
                                v-if="item.external"
                                class="sm ms-2"
                                :aria-label="$gettext('External')"
                            />
                        </a>
                    </li>
                </ul>
            </div>
        </li>
    </ul>
</template>

<script setup lang="ts">
import {useRoute, useRouter} from "vue-router";
import {some} from "es-toolkit/compat";
import {computed, ref, shallowRef, watch} from "vue";
import {watchDebounced} from "@vueuse/core";
import {MenuCategory, MenuRouteBasedUrl, MenuRouteUrl, MenuSubCategory} from "~/functions/filterMenu.ts";
import {
    buildMenuSearchIndex,
    DataSearchSource,
    MenuSearchResult,
    MenuSearchSource,
    PageTextRow,
    searchMenuIndex,
    visibleRouteNames
} from "~/functions/menuSearch.ts";
import {useTranslate} from "~/vendor/gettext.ts";
import IconIcOpenInNew from "~icons/ic/baseline-open-in-new";
import IconIcSearch from "~icons/ic/baseline-search";

const props = withDefaults(
    defineProps<{
        menu: MenuCategory[],
        searchSources: MenuSearchSource[],
        dataSources?: DataSearchSource[],
    }>(),
    {
        dataSources: () => [],
    }
);

const currentRoute = useRoute();
const router = useRouter();

const query = ref('');
const isSearching = computed(() => query.value.trim() !== '');

const {$gettext} = useTranslate();

const pageText = shallowRef<PageTextRow[]>([]);
let pageTextRequested = false;

// Loaded on first focus so normal page loads don't pay for it.
const loadPageText = async () => {
    if (pageTextRequested) {
        return;
    }
    pageTextRequested = true;

    const {default: rows} = await import('virtual:page-search-index');
    const formLabel = $gettext('Add/Edit form');

    pageText.value = rows.map(
        ([text, routeName, tabId = '', tabLabel = '', inForm]): PageTextRow => ({
            text: $gettext(text),
            routeName,
            tabId,
            tabLabel: tabLabel ? $gettext(tabLabel) : '',
            formLabel: inForm ? formLabel : '',
        })
    );
};

const searchIndex = computed(() => buildMenuSearchIndex(props.searchSources, pageText.value));

const menuResults = computed(() => searchMenuIndex(searchIndex.value, query.value));

const visibleRoutes = computed(() => visibleRouteNames(props.searchSources));

const dataResults = shallowRef<MenuSearchResult[]>([]);
const isSearchingData = ref(false);
let dataSearchId = 0;

watchDebounced(query, async (newQuery) => {
    const searchId = ++dataSearchId;
    const trimmed = newQuery.trim();
    const sources = props.dataSources.filter((source) => visibleRoutes.value.has(source.routeName));

    if (trimmed.length < 2 || sources.length === 0) {
        dataResults.value = [];
        isSearchingData.value = false;
        return;
    }

    isSearchingData.value = true;
    const settled = await Promise.allSettled(sources.map((source) => source.search(trimmed)));

    if (searchId !== dataSearchId) {
        return;
    }

    dataResults.value = settled.flatMap((outcome) => outcome.status === 'fulfilled' ? outcome.value : []);
    isSearchingData.value = false;
}, {debounce: 300});

// Pages first, then matching records, then individual settings.
const results = computed(() => [
    ...menuResults.value.filter((result) => !result.isPageText),
    ...(isSearching.value ? dataResults.value : []),
    ...menuResults.value.filter((result) => result.isPageText),
]);

const openFirstResult = () => {
    const first = results.value[0];
    if (!first) {
        return;
    }

    if (isRouteLink(first.url)) {
        void router.push(first.url);
    } else if (first.external) {
        window.open(first.url, '_blank');
    } else {
        window.location.href = first.url;
    }
};

watch(() => currentRoute.fullPath, () => {
    query.value = '';
});

const isRouteLink = (url?: MenuRouteUrl): url is MenuRouteBasedUrl => {
    return (url !== undefined)
        && (typeof (url) !== 'string');
};

const isCategory = (item: MenuCategory | MenuSubCategory): item is MenuCategory => {
    return 'items' in item;
}

const isActiveItem = (item: MenuCategory | MenuSubCategory) => {
    if (isCategory(item) && some(item.items ?? [], isActiveItem)) {
        return true;
    }

    return isRouteLink(item.url) && !('params' in item.url) && item.url.name === currentRoute.name;
};

const getLinkClass = (item: MenuSubCategory) => {
    return [
        item.class ?? null,
        isActiveItem(item) ? 'active' : ''
    ];
}

const getCategoryLink = (item: MenuSubCategory) => {
    const linkAttrs: {
        [key: string]: any
    } = {};

    if ('items' in item) {
        linkAttrs['data-bs-toggle'] = 'collapse';
        linkAttrs.href = '#sidebar-submenu-' + item.key;
    } else {
        linkAttrs.href = item.url;
    }

    if (item.external) {
        linkAttrs.target = '_blank';
    }
    if (item.title) {
        linkAttrs.title = item.title;
    }

    return linkAttrs;
}
</script>

<style lang="scss">
@import "~/scss/_mixins.scss";

.might-overflow {
    @include might-overflow();
}

.navdrawer-nav .nav-link.sidebar-search-result {
    flex-direction: column;
    align-items: flex-start;
    gap: .1rem;
    padding-top: .4rem;
    padding-bottom: .4rem;
    line-height: 1.25;

    small {
        max-width: 100%;
        font-weight: 400;
        font-size: .75rem;
    }
}
</style>
