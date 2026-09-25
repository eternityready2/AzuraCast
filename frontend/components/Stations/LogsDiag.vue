<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_logs_diag"
    >
        <div class="card-header text-bg-primary">
            <h2
                id="hdr_logs_diag"
                class="card-title"
            >
                {{ $gettext('Logs & Diag') }}
            </h2>
        </div>

        <div class="card-body">
            <tabs
                v-model="activeTab"
                nav-tabs-class="logs-diag-tabs"
                destroy-on-hide
            >
                <tab
                    id="aircheck"
                    :label="$gettext('AirCheck')"
                >
                    <air-check-panel />
                </tab>

                <tab
                    id="diagnostics"
                    :label="$gettext('Diagnostics')"
                >
                    <diagnostics-dashboard />
                </tab>

                <tab
                    id="logs"
                    :label="$gettext('Logs')"
                >
                    <logs-panel />
                </tab>
            </tabs>
        </div>
    </section>
</template>

<script setup lang="ts">
import {ref} from "vue";
import {useTranslate} from "~/vendor/gettext";
import Tabs from "~/components/Common/Tabs.vue";
import Tab from "~/components/Common/Tab.vue";
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
    padding: 0.85rem 1.35rem;
    font-size: 1rem;
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
