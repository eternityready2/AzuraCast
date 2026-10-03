<template>
    <nav
        class="nav nav-default"
        :class="navTabsClass"
        role="tablist"
        aria-orientation="horizontal"
    >
        <div
            v-for="(childItem) in state.tabs"
            :key="childItem.computedId"
            class="nav-item"
        >
            <button
                :id="`${childItem.computedId}-tab`"
                class="nav-link"
                :class="[
                    (activeId === childItem.computedId) ? 'active' : '',
                    childItem.itemHeaderClass
                ]"
                role="tab"
                :aria-controls="`${childItem.computedId}-content`"
                type="button"
                :aria-selected="(activeId === childItem.computedId) ? 'true' : 'false'"
                @click="selectTab(childItem.computedId)"
            >
                {{ childItem.label }}
            </button>
        </div>
    </nav>
    <section
        class="nav-content"
        :class="contentClass"
    >
        <slot />
    </section>
</template>

<script setup lang="ts">
import {inject, onMounted, ref, watch} from "vue";
import {routeLocationKey} from "vue-router";
import {TabParentProps, useTabParent} from "~/functions/tabs.ts";

const props = withDefaults(
    defineProps<TabParentProps>(),
    {
        navTabsClass: 'nav-tabs',
        contentClass: 'mt-3',
        destroyOnHide: false,
    }
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void
}>();

const activeId = ref(props.modelValue);

const state = useTabParent(props);

const selectTab = (computedId: string): void => {
    state.active = computedId;
    activeId.value = computedId;
    emit('update:modelValue', computedId);
}

// Optional: Tabs also render on pages without a router.
const route = inject(routeLocationKey, null);

const findTab = (id: unknown) => state.tabs.find((tab) => tab.computedId === id);

onMounted(() => {
    if (!state.tabs.length) {
        return;
    }

    const initialTab = findTab(route?.query.tab) ?? findTab(activeId.value);

    selectTab(initialTab?.computedId ?? state.tabs[0].computedId);
});

watch(() => route?.query.tab, (tabId) => {
    const tab = findTab(tabId);
    if (tab) {
        selectTab(tab.computedId);
    }
});
</script>
