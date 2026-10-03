// Vite plugin: builds `virtual:page-search-index`, the list of translatable UI text on each
// routed page (with the tab it sits in), so the sidebar search can find any visible setting.
import {readFileSync, existsSync} from "fs";
import {dirname, resolve} from "path";

const VIRTUAL_ID = 'virtual:page-search-index';
const RESOLVED_ID = '\0' + VIRTUAL_ID;

const ROUTE_FILES = ['components/Stations/routes.ts', 'components/Admin/routes.ts'];

// Shared widgets carry generic text ("Save", "Actions") that would match every page.
const SKIP_DIRS = ['/components/Common/'];

const GENERIC = new Set([
    'save', 'save changes', 'cancel', 'close', 'delete', 'edit', 'add', 'actions', 'name', 'yes', 'no',
    'enabled', 'disabled', 'loading...', 'search', 'refresh rows', 'are you sure?', 'submit', 'back',
    'next', 'previous', 'description', 'type', 'status', 'title', 'none', 'clear', 'reset', 'ok',
    'default', 'custom', 'other', 'settings', 'unknown', 'all', 'remove', 'copy', 'download', 'upload',
]);

const kebab = (name) => name.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();

const unescape = (str) => str.replace(/\\(['"\\])/g, '$1');

const GETTEXT_RE = /\$gettext\(\s*(['"])((?:\\.|(?!\1).)*)\1/g;
const TAB_TOKEN_RE = /<tab\b([^>]*)>|<\/tab>/g;
const MODAL_TOKEN_RE = /<modal(?:-form)?\b[^>]*>|<\/modal(?:-form)?>/g;

// True for offsets inside a <modal>/<modal-form> block.
const modalRanges = (template) => {
    const ranges = [];
    const stack = [];
    for (const match of template.matchAll(MODAL_TOKEN_RE)) {
        if (match[0].startsWith('</')) {
            const start = stack.pop();
            if (start !== undefined) {
                ranges.push([start, match.index]);
            }
        } else {
            stack.push(match.index);
        }
    }
    for (const start of stack) {
        ranges.push([start, template.length]);
    }
    return (offset) => ranges.some(([start, end]) => offset >= start && offset <= end);
};

const readTemplate = (source) => {
    const match = source.match(/<template>([\s\S]*)<\/template>/);
    return match ? match[1] : '';
};

const tabFromAttrs = (attrs) => {
    const label = attrs.match(/:label="\$gettext\(\s*'((?:\\.|[^'])*)'/)?.[1];
    const id = attrs.match(/\bid="([^"]+)"/)?.[1];
    if (!label && !id) {
        return null;
    }
    const labelText = label ? unescape(label) : null;
    return {
        id: id ?? labelText.toLowerCase().replace(/ /g, '-'),
        label: labelText,
    };
};

// Returns, for each character offset in the template, the tab that encloses it.
const tabRanges = (template) => {
    const ranges = [];
    const stack = [];
    for (const match of template.matchAll(TAB_TOKEN_RE)) {
        if (match[0].startsWith('</')) {
            const open = stack.pop();
            if (open && open.tab) {
                ranges.push({start: open.start, end: match.index, tab: open.tab});
            }
        } else {
            stack.push({start: match.index, tab: tabFromAttrs(match[1])});
        }
    }
    for (const open of stack) {
        if (open.tab) {
            ranges.push({start: open.start, end: template.length, tab: open.tab});
        }
    }
    return (offset) => {
        let best = null;
        for (const range of ranges) {
            if (offset >= range.start && offset <= range.end && (!best || range.start > best.start)) {
                best = range;
            }
        }
        return best?.tab ?? null;
    };
};

const resolveImport = (spec, fromFile, frontendDir) => {
    if (spec.startsWith('~/')) {
        return resolve(frontendDir, spec.slice(2));
    }
    if (spec.startsWith('.')) {
        return resolve(dirname(fromFile), spec);
    }
    return null;
};

const childComponents = (source, file, frontendDir) => {
    const children = [];
    for (const match of source.matchAll(/import\s+(\w+)\s+from\s+["']([^"']+\.vue)["']/g)) {
        const path = resolveImport(match[2], file, frontendDir);
        if (path && existsSync(path)) {
            children.push({name: match[1], path});
        }
    }
    return children;
};

const collectPage = (rootFile, frontendDir) => {
    const entries = [];
    const visited = new Set();

    // Tabs inside a popup aren't page tabs, so popup text keeps the page-level tab and is flagged as a form.
    const walk = (file, inheritedTab, inheritedForm, depth) => {
        if (depth > 6 || visited.has(file) || SKIP_DIRS.some((dir) => file.includes(dir))) {
            return;
        }
        visited.add(file);

        const source = readFileSync(file, 'utf8');
        const template = readTemplate(source);
        const tabAt = tabRanges(template);
        const inModal = modalRanges(template);

        const contextAt = (offset) => {
            const form = inheritedForm || inModal(offset);
            return {
                tab: form ? inheritedTab : (tabAt(offset) ?? inheritedTab),
                form,
            };
        };

        for (const match of template.matchAll(GETTEXT_RE)) {
            const text = unescape(match[2]).replace(/%\{\s*\w+\s*\}/g, '…').trim();
            if (text.length < 3 || GENERIC.has(text.toLowerCase())) {
                continue;
            }
            entries.push({text, ...contextAt(match.index)});
        }

        for (const child of childComponents(source, file, frontendDir)) {
            const tagIndex = template.search(new RegExp(`<(${kebab(child.name)}|${child.name})\\b`));
            const context = tagIndex >= 0 ? contextAt(tagIndex) : {tab: inheritedTab, form: inheritedForm};
            walk(child.path, context.tab, context.form, depth + 1);
        }
    };

    walk(rootFile, null, false, 0);
    return entries;
};

const buildIndex = (frontendDir) => {
    const seen = new Set();
    const rows = [];

    for (const routeFile of ROUTE_FILES) {
        const source = readFileSync(resolve(frontendDir, routeFile), 'utf8');
        const routeRe = /import\(\s*'~\/([^']+?\.vue)'\s*\)((?:(?!import\()[\s\S])*?)name:\s*'([^']+)'/g;

        for (const match of source.matchAll(routeRe)) {
            const [, componentPath, , routeName] = match;
            for (const {text, tab, form} of collectPage(resolve(frontendDir, componentPath), frontendDir)) {
                const key = `${routeName}|${tab?.id ?? ''}|${form}|${text}`;
                if (seen.has(key)) {
                    continue;
                }
                seen.add(key);

                const row = [text, routeName];
                if (tab || form) {
                    row.push(tab?.id ?? '', tab?.label ?? '');
                }
                if (form) {
                    row.push(1);
                }
                rows.push(row);
            }
        }
    }

    return rows;
};

export default function pageSearchIndex(frontendDir) {
    return {
        name: 'azuracast-page-search-index',
        resolveId(id) {
            return id === VIRTUAL_ID ? RESOLVED_ID : null;
        },
        load(id) {
            if (id !== RESOLVED_ID) {
                return null;
            }
            return `export default ${JSON.stringify(buildIndex(frontendDir))};`;
        },
    };
}

export {buildIndex};
