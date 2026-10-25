import {MenuBase, MenuCategory, MenuRouteUrl} from "~/functions/filterMenu.ts";

export type MenuSearchSource = {
    section: string,
    menu: MenuCategory[],
}

// Already translated. tabId/tabLabel are '' when absent; formLabel is set for text inside an add/edit popup.
export type PageTextRow = {
    text: string,
    routeName: string,
    tabId: string,
    tabLabel: string,
    formLabel: string,
};

export type MenuSearchResult = {
    key: string,
    label: string,
    trail: string,
    url: MenuRouteUrl,
    external: boolean,
    isPageText: boolean,
}

// Live records (playlists, songs, ...). Only queried when `routeName` is a visible menu page.
export type DataSearchSource = {
    routeName: string,
    search: (query: string) => Promise<MenuSearchResult[]>,
}

export type SearchRow = Record<string, unknown>;

export const rowText = (value: unknown): string =>
    (typeof value === 'string' || typeof value === 'number') ? String(value) : '';

// List APIs return a bare array, or `{rows: [...]}` when paginated.
export const searchRowsOf = (data: unknown): SearchRow[] => {
    if (Array.isArray(data)) {
        return data as SearchRow[];
    }
    const rows = (data as {rows?: unknown} | null)?.rows;
    return Array.isArray(rows) ? rows as SearchRow[] : [];
};

type IndexEntry = MenuSearchResult & {
    labelText: string,
    fullText: string,
}

export const visibleRouteNames = (sources: MenuSearchSource[]): Set<string> => {
    const names = new Set<string>();
    for (const {menu} of sources) {
        for (const category of menu) {
            for (const item of [category, ...(category.items ?? [])]) {
                if (item.url !== undefined && typeof item.url !== 'string') {
                    names.add(item.url.name);
                }
            }
        }
    }
    return names;
};

type PageRef = {
    label: string,
    trail: string[],
    url: MenuRouteUrl,
}

const MAX_RESULTS = 60;

const toEntry = (
    key: string,
    label: string,
    trail: string[],
    url: MenuRouteUrl,
    external: boolean,
    isPageText: boolean
): IndexEntry => ({
    key,
    label,
    trail: trail.join(' › '),
    url,
    external,
    isPageText,
    labelText: label.toLowerCase(),
    // Page text matches on its own words only, so a query like "audio" doesn't list every field on that page.
    fullText: (isPageText ? label : [label, ...trail].join(' ')).toLowerCase(),
});

export const buildMenuSearchIndex = (sources: MenuSearchSource[], pageText: PageTextRow[]): IndexEntry[] => {
    const index: IndexEntry[] = [];
    const pages = new Map<string, PageRef>();

    const addItem = (section: string, item: MenuBase, trail: string[]) => {
        if (item.url === undefined) {
            return;
        }

        index.push(toEntry(`${section}:${item.key}`, item.label, trail, item.url, item.external ?? false, false));

        const url = item.url;
        if (typeof url !== 'string' && !url.params && !pages.has(url.name)) {
            pages.set(url.name, {label: item.label, trail, url});
        }
    };

    for (const {section, menu} of sources) {
        for (const category of menu) {
            addItem(section, category, [section]);

            for (const item of category.items ?? []) {
                addItem(section, item, [section, category.label]);
            }
        }
    }

    // Only pages reachable from the visible menus, so permissions carry over.
    pageText.forEach(({text, routeName, tabId, tabLabel, formLabel}, i) => {
        const page = pages.get(routeName);
        if (!page || typeof page.url === 'string') {
            return;
        }

        const trail = [...page.trail.slice(1), page.label, tabLabel, formLabel].filter((part) => part !== '');

        const url = tabId ? {...page.url, query: {tab: tabId}} : page.url;

        index.push(toEntry(`text:${i}`, text, trail, url, false, true));
    });

    return index;
};

// Every word must match. Pages come before page text; labels starting with the query rank first.
export const searchMenuIndex = (index: IndexEntry[], rawQuery: string): MenuSearchResult[] => {
    const query = rawQuery.trim().toLowerCase();
    const words = query.split(/\s+/).filter((word) => word !== '');
    if (words.length === 0) {
        return [];
    }

    const rank = (entry: IndexEntry): number => {
        const base = entry.isPageText ? 2 : 0;
        return base + (entry.labelText.startsWith(query) ? 0 : 1);
    };

    // A page heading repeats its menu label; keep only the first of each label + destination.
    const seen = new Set<string>();

    return index
        .filter((entry) => words.every((word) => entry.fullText.includes(word)))
        .map((entry) => ({entry, rank: rank(entry)}))
        .sort((a, b) => a.rank - b.rank)
        .filter(({entry}) => {
            const url = typeof entry.url === 'string' ? entry.url : entry.url.name + (entry.url.query?.tab ?? '');
            const key = `${entry.labelText}|${url}`;
            if (seen.has(key)) {
                return false;
            }
            seen.add(key);
            return true;
        })
        .slice(0, MAX_RESULTS)
        .map(({entry}) => ({
            key: entry.key,
            label: entry.label,
            trail: entry.trail,
            url: entry.url,
            external: entry.external,
            isPageText: entry.isPageText,
        }));
};
