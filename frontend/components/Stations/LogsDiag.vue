<template>
    <section
        class="card"
        role="region"
        aria-label="Logs & Diagnostics"
    >
        <div class="card-body pb-0">
            <nav
                class="nav nav-tabs logs-diag-tabs"
                role="tablist"
            >
                <div
                    class="nav-item"
                    role="presentation"
                >
                    <button
                        type="button"
                        class="nav-link"
                        :class="{active: activeTab === 'aircheck'}"
                        role="tab"
                        :aria-selected="activeTab === 'aircheck'"
                        @click="activeTab = 'aircheck'"
                    >
                        {{ $gettext('AirCheck') }}
                    </button>
                </div>
                <div
                    class="nav-item"
                    role="presentation"
                >
                    <button
                        type="button"
                        class="nav-link"
                        :class="{active: activeTab === 'diagnostics'}"
                        role="tab"
                        :aria-selected="activeTab === 'diagnostics'"
                        @click="activeTab = 'diagnostics'"
                    >
                        {{ $gettext('Diagnostics') }}
                    </button>
                </div>
                <div
                    class="nav-item"
                    role="presentation"
                >
                    <button
                        type="button"
                        class="nav-link"
                        :class="{active: activeTab === 'logs'}"
                        role="tab"
                        :aria-selected="activeTab === 'logs'"
                        @click="activeTab = 'logs'"
                    >
                        {{ $gettext('Logs') }}
                    </button>
                </div>
            </nav>
        </div>

        <div class="card-body">
            <air-check-panel v-if="activeTab === 'aircheck'" />
            <diagnostics-dashboard v-show="activeTab === 'diagnostics'" />
            <logs-panel v-if="activeTab === 'logs'" />
        </div>
    </section>
</template>

<script setup lang="ts">
import {ref} from "vue";
import {useTranslate} from "~/vendor/gettext";
import AirCheckPanel from "~/components/Stations/AirCheck.vue";
import DiagnosticsDashboard from "~/components/Stations/Logs/DiagnosticsDashboard.vue";
import LogsPanel from "~/components/Stations/LogsDiag/LogsPanel.vue";

const {$gettext} = useTranslate();

const props = defineProps<{
    initialTab?: string,
}>();

const activeTab = ref(props.initialTab ?? 'aircheck');
</script>

<style lang="scss">
.logs-diag-tabs.nav-tabs .nav-link {
    padding: 0.75rem 1.25rem;
    font-size: 0.95rem;
    font-weight: 600;
}

@media (max-width: 575.98px) {
    .logs-diag-tabs.nav-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: thin;
    }

    .logs-diag-tabs.nav-tabs .nav-link {
        white-space: nowrap;
    }
}
</style>
