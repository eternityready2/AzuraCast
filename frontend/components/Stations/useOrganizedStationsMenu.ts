import {computed} from "vue";
import {MenuCategory, MenuSubCategory} from "~/functions/filterMenu.ts";
import {useTranslate} from "~/vendor/gettext.ts";
import {useStationsMenu} from "~/components/Stations/menu";
import IconIcSchedule from "~icons/ic/baseline-schedule";

const automationKeys = [
    'schedule',
    'playlists',
    'shows',
    'smart-blocks',
    'web_streams',
    'clock_wheels',
    'media_categories',
];

const broadcastingActionKeys = [
    'top_of_hour',
    'playout_controls',
    'crossfade_profiles',
    'aircheck',
];

const toSubCategory = (category: MenuCategory): MenuSubCategory => ({
    key: category.key,
    label: category.label,
    icon: category.icon,
    url: category.url,
    external: category.external,
    title: category.title,
    class: category.class,
});

/**
 * Keep the underlying station menu definitions and permission filtering in one place,
 * then present them in workflow-oriented groups so the sidebar is shorter and easier
 * for an operator to scan.
 */
export function useOrganizedStationsMenu() {
    const {$gettext} = useTranslate();
    const sourceMenu = useStationsMenu();

    return computed<MenuCategory[]>(() => {
        const source = sourceMenu.value;
        const byKey = new Map(source.map((item) => [item.key, item]));
        const movedKeys = new Set([...automationKeys, ...broadcastingActionKeys]);

        const menu = source
            .filter((item) => !movedKeys.has(item.key))
            .map((item): MenuCategory => ({
                ...item,
                items: item.items ? [...item.items] : undefined,
                label: item.key === 'edit_profile'
                    ? $gettext('Station Settings')
                    : item.label,
            }));

        const automationItems = automationKeys
            .map((key) => byKey.get(key))
            .filter((item): item is MenuCategory => item !== undefined)
            .map(toSubCategory);

        if (automationItems.length > 0) {
            const automation: MenuCategory = {
                key: 'automation',
                label: $gettext('Automation & Programming'),
                icon: () => IconIcSchedule,
                items: automationItems,
            };

            const mediaIndex = menu.findIndex((item) => item.key === 'media');
            menu.splice(mediaIndex >= 0 ? mediaIndex + 1 : 2, 0, automation);
        }

        const broadcasting = menu.find((item) => item.key === 'broadcasting');
        if (broadcasting) {
            const operatorActions = broadcastingActionKeys
                .map((key) => byKey.get(key))
                .filter((item): item is MenuCategory => item !== undefined)
                .map(toSubCategory);

            broadcasting.items = [
                ...operatorActions,
                ...(broadcasting.items ?? []),
            ];
        }

        return menu;
    });
}
