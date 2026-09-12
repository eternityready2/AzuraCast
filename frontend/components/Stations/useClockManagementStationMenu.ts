import {computed, type Ref} from 'vue';
import type {MenuCategory, MenuSubCategory} from '~/functions/filterMenu.ts';

/**
 * Re-groups the scheduling-related station tools to match the Clock Management
 * workspace without changing the underlying routes or permissions.
 */
export const useClockManagementStationMenu = (source: Ref<MenuCategory[]>) => computed<MenuCategory[]>(() => {
    const original = source.value.map((category) => ({
        ...category,
        items: category.items?.map((item) => ({...item})),
    }));

    const schedule = original.find((item) => item.key === 'schedule');
    const clockManagement = original.find((item) => item.key === 'clock_wheels');
    const aiCategory = original.find((item) => item.key === 'ai');
    const broadcasting = original.find((item) => item.key === 'broadcasting');
    const aiDj = aiCategory?.items?.find((item) => item.key === 'ai_dj');
    const liquidsoap = broadcasting?.items?.find((item) => item.key === 'ls_config');

    const schedulingChildren: MenuSubCategory[] = [];

    // Preserve the station schedule route instead of hiding functionality while
    // grouping the three Clock Management companions beneath Scheduling.
    if (schedule?.url) {
        schedulingChildren.push({
            ...schedule,
            key: 'schedule_calendar',
            label: 'Schedule',
            icon: undefined,
        });
    }

    if (clockManagement) {
        schedulingChildren.push({
            ...clockManagement,
            key: 'clock_management',
            label: 'Clock Management',
            icon: undefined,
        });
    }

    if (liquidsoap) {
        schedulingChildren.push({
            ...liquidsoap,
            key: 'clock_management_liquidsoap',
            label: 'Liquidsoap Configuration',
        });
    }

    if (aiDj) {
        schedulingChildren.push({
            ...aiDj,
            key: 'clock_management_autodj',
            label: 'AutoDJ',
        });
    }

    return original
        .filter((category) => category.key !== 'clock_wheels')
        .map((category): MenuCategory | null => {
            if (category.key === 'schedule') {
                return {
                    key: 'scheduling',
                    label: 'Scheduling',
                    icon: category.icon,
                    items: schedulingChildren,
                };
            }

            if (category.key === 'ai') {
                const items = category.items?.filter((item) => item.key !== 'ai_dj') ?? [];
                return items.length > 0 ? {...category, items} : null;
            }

            if (category.key === 'broadcasting') {
                return {
                    ...category,
                    items: category.items?.filter((item) => item.key !== 'ls_config'),
                };
            }

            return category;
        })
        .filter((category): category is MenuCategory => category !== null);
});
