<template>
    <section
        class="card clock-wheels-page"
        role="region"
        aria-labelledby="hdr_clock_wheels"
    >
        <div class="card-header text-bg-primary clock-wheels-page-header">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h2
                        id="hdr_clock_wheels"
                        class="card-title mb-1"
                    >
                        {{ $gettext('Manage Clock Wheels') }}
                    </h2>
                    <p class="mb-0 opacity-75 small">
                        {{ $gettext('Build reusable Templates, turn them into Dayparts, then manage the hourly Clock Wheels that actually air.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="card-body clock-wheels-page-body">
            <div class="clock-workflow-guide mb-3">
                <div class="clock-workflow-guide__step">
                    <span class="clock-workflow-guide__number">1</span>
                    <div>
                        <strong>{{ $gettext('Templates') }}</strong>
                        <span>{{ $gettext('Build a reusable slot layout.') }}</span>
                    </div>
                </div>
                <span class="clock-workflow-guide__arrow" aria-hidden="true">→</span>
                <div class="clock-workflow-guide__step">
                    <span class="clock-workflow-guide__number">2</span>
                    <div>
                        <strong>{{ $gettext('Dayparts') }}</strong>
                        <span>{{ $gettext('Apply a Template to a block of hours.') }}</span>
                    </div>
                </div>
                <span class="clock-workflow-guide__arrow" aria-hidden="true">→</span>
                <div class="clock-workflow-guide__step">
                    <span class="clock-workflow-guide__number">3</span>
                    <div>
                        <strong>{{ $gettext('Clock Wheels') }}</strong>
                        <span>{{ $gettext('Fine-tune and schedule the actual hourly clocks.') }}</span>
                    </div>
                </div>
            </div>

            <tabs
                nav-tabs-class="nav-tabs clock-wheels-primary-tabs"
                content-class="mt-3"
                destroy-on-hide
            >
                <tab :label="$gettext('Wheels')">
                    <clock-wheel-inline-editor
                        v-if="wheelEditorOpen"
                        :key="wheelEditorKey"
                        :create-url="listUrl"
                        :templates-url="templatesUrl"
                        :record-url="wheelEditorUrl"
                        @saved="onWheelEditorSaved"
                        @cancel="closeWheelEditor"
                    />

                    <div
                        v-else
                        class="card-body-flush"
                    >
                        <div class="clock-workspace-list-header">
                            <div>
                                <h3 class="h5 mb-1">{{ $gettext('Clock Wheels') }}</h3>
                                <p class="mb-0 text-muted small">
                                    {{ $gettext('These are the final hourly clocks. Open one to edit its slots or schedule, preview it, review analytics, or export it.') }}
                                </p>
                            </div>
                            <div class="clock-wheels-toolbar d-flex flex-wrap align-items-center gap-2">
                                <add-button
                                    :text="$gettext('Add Clock Wheel')"
                                    @click="openNewWheelEditor"
                                />
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="$generateModal?.open()"
                                >
                                    {{ $gettext('Auto-Generate') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="triggerImport"
                                >
                                    {{ $gettext('Import JSON') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-danger"
                                    :disabled="!hasSelectedWheels"
                                    @click="doDeleteSelected"
                                >
                                    {{ $gettext('Delete Selected') }}
                                </button>
                                <input
                                    ref="$importInput"
                                    type="file"
                                    accept="application/json,.json"
                                    class="d-none"
                                    @change="onImportFile"
                                >
                            </div>
                        </div>

                        <data-table
                            id="station_clock_wheels"
                            selectable
                            paginated
                            :fields="wheelFields"
                            :provider="listItemProvider"
                            @row-selected="onWheelRowSelected"
                        >
                            <template #cell(actions)="{ item }">
                                <div
                                    class="btn-group btn-group-sm clock-wheel-list-actions"
                                    @click.stop
                                >
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        :title="$gettext('Next hour preview')"
                                        @click="openPreview(item)"
                                    >
                                        {{ $gettext('Preview') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        :title="$gettext('Audit analytics')"
                                        @click="openAnalytics(item)"
                                    >
                                        {{ $gettext('Analytics') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        :title="$gettext('Export JSON')"
                                        @click="doExportJson(item)"
                                    >
                                        {{ $gettext('Export') }}
                                    </button>
                                </div>
                            </template>
                            <template #cell(name)="{ item }">
                                <div
                                    class="d-flex align-items-center gap-3 clock-wheel-row"
                                    role="button"
                                    style="cursor: pointer;"
                                    @click="openWheelEditor(item.links.self)"
                                >
                                    <div class="flex-grow-1 min-width-0">
                                        <h5 class="m-0">
                                            {{ item.name }}
                                            <span
                                                v-if="item.inherits_template_slots"
                                                class="badge text-bg-secondary ms-1"
                                            >
                                                {{ $gettext('Template') }}
                                            </span>
                                        </h5>
                                        <small class="text-muted">{{ $gettext('Open editor') }}</small>
                                    </div>
                                    <span
                                        class="d-inline-block rounded flex-shrink-0"
                                        style="width: 1.5rem; height: 1.5rem;"
                                        :style="{backgroundColor: item.color ?? '#cccccc'}"
                                    />
                                    <icon-bi-chevron-right class="clock-wheel-chevron text-muted flex-shrink-0" />
                                </div>
                            </template>
                        </data-table>
                    </div>
                </tab>

                <tab :label="$gettext('Templates')">
                    <template-inline-editor
                        v-if="templateEditorOpen"
                        :key="templateEditorKey"
                        :create-url="templatesUrl"
                        :record-url="templateEditorUrl"
                        @saved="onTemplateEditorSaved"
                        @cancel="closeTemplateEditor"
                    />

                    <div
                        v-else
                        class="card-body-flush"
                    >
                        <div class="clock-workspace-list-header">
                            <div>
                                <h3 class="h5 mb-1">{{ $gettext('Templates') }}</h3>
                                <p class="mb-0 text-muted small">
                                    {{ $gettext('Build a slot layout once and reuse it in multiple Dayparts or Clock Wheels. Templates do not air by themselves.') }}
                                </p>
                            </div>
                            <add-button
                                :text="$gettext('Add Template')"
                                @click="openNewTemplateEditor"
                            />
                        </div>

                        <data-table
                            id="station_clock_wheel_templates"
                            paginated
                            :fields="templateFields"
                            :provider="templateListProvider"
                        >
                            <template #cell(name)="{ item }">
                                <div
                                    class="d-flex align-items-center gap-3 clock-wheel-row"
                                    role="button"
                                    style="cursor: pointer;"
                                    @click="openTemplateEditor(item.links.self)"
                                >
                                    <div class="flex-grow-1 min-width-0">
                                        <h5 class="m-0">{{ item.name }}</h5>
                                        <small class="text-muted">{{ $gettext('Reusable layout · Open editor') }}</small>
                                    </div>
                                    <span
                                        class="d-inline-block rounded flex-shrink-0"
                                        style="width: 1.5rem; height: 1.5rem;"
                                        :style="{backgroundColor: item.color ?? '#cccccc'}"
                                    />
                                    <icon-bi-chevron-right class="clock-wheel-chevron text-muted flex-shrink-0" />
                                </div>
                            </template>
                        </data-table>
                    </div>
                </tab>

                <tab :label="$gettext('Dayparts')">
                    <daypart-inline-editor
                        v-if="daypartEditorOpen"
                        :key="daypartEditorKey"
                        :create-url="daypartsUrl"
                        :templates-url="templatesUrl"
                        :record-url="daypartEditorUrl"
                        @saved="onDaypartEditorSaved"
                        @changed="relistDayparts"
                        @cancel="closeDaypartEditor"
                    />

                    <div
                        v-else
                        class="card-body-flush"
                    >
                        <div class="clock-workspace-list-header">
                            <div>
                                <h3 class="h5 mb-1">{{ $gettext('Dayparts') }}</h3>
                                <p class="mb-0 text-muted small">
                                    {{ $gettext('A Daypart combines a Template with an hour range and keeps the generated hourly Clock Wheels in sync.') }}
                                </p>
                            </div>
                            <add-button
                                :text="$gettext('Add Daypart')"
                                @click="openNewDaypartEditor"
                            />
                        </div>

                        <data-table
                            id="station_clock_dayparts"
                            paginated
                            :fields="daypartFields"
                            :provider="daypartListProvider"
                        >
                            <template #cell(actions)="{ item }">
                                <div
                                    class="btn-group btn-group-sm"
                                    @click.stop
                                >
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        :disabled="syncingDaypartId === item.id"
                                        :title="$gettext('Regenerate hourly wheels from this daypart')"
                                        @click="doSyncDaypart(item)"
                                    >
                                        {{ syncingDaypartId === item.id
                                            ? $gettext('Syncing…')
                                            : $gettext('Re-sync') }}
                                    </button>
                                </div>
                            </template>
                            <template #cell(name)="{ item }">
                                <div
                                    class="d-flex align-items-center gap-3 clock-wheel-row"
                                    role="button"
                                    style="cursor: pointer;"
                                    @click="openDaypartEditor(item.links.self)"
                                >
                                    <div class="flex-grow-1 min-width-0">
                                        <h5 class="m-0">
                                            {{ item.name }}
                                            <span
                                                v-if="item.separation_override_enabled"
                                                class="badge text-bg-info ms-1"
                                            >
                                                {{ $gettext('Separation') }}
                                            </span>
                                        </h5>
                                        <small class="text-muted">{{ $gettext('Hour block · Open editor') }}</small>
                                    </div>
                                    <icon-bi-chevron-right class="clock-wheel-chevron text-muted flex-shrink-0" />
                                </div>
                            </template>
                            <template #cell(hours)="{ item }">
                                {{ formatHour(item.start_hour) }} – {{ formatHour(item.end_hour) }}
                            </template>
                            <template #cell(separation)="{ item }">
                                <span v-if="item.separation_override_enabled && item.separation_enabled">
                                    {{ item.separation_artist_minutes }}/{{ item.separation_title_minutes }} min
                                </span>
                                <span
                                    v-else-if="item.separation_override_enabled"
                                    class="text-muted"
                                >
                                    {{ $gettext('Off') }}
                                </span>
                                <span
                                    v-else
                                    class="text-muted"
                                >—</span>
                            </template>
                        </data-table>
                    </div>
                </tab>

                <tab :label="$gettext('Program Grid')">
                    <div class="clock-workspace-list-header mb-3">
                        <div>
                            <h3 class="h5 mb-1">{{ $gettext('Program Grid') }}</h3>
                            <p class="mb-0 text-muted small">
                                {{ $gettext('See which Clock Wheels are expected to run across the schedule and spot gaps quickly.') }}
                            </p>
                        </div>
                    </div>
                    <program-grid-tab :grid-url="programGridUrl" />
                </tab>

                <tab :label="$gettext('Reconciliation')">
                    <div class="clock-workspace-list-header mb-3">
                        <div>
                            <h3 class="h5 mb-1">{{ $gettext('Reconciliation') }}</h3>
                            <p class="mb-0 text-muted small">
                                {{ $gettext('Review what was scheduled versus what actually played without leaving the Clock Wheels workspace.') }}
                            </p>
                        </div>
                    </div>
                    <reconciliation-log-tab :log-url="reconciliationLogUrl" />
                </tab>
            </tabs>
        </div>
    </section>

    <preview-modal ref="$previewModal" />
    <analytics-modal ref="$analyticsModal" />
    <generate-modal
        ref="$generateModal"
        :generate-url="generateUrl"
        @generated="relistWheels"
    />
</template>

<script setup lang="ts">
import DataTable, {DataTableField} from '~/components/Common/DataTable.vue';
import AddButton from '~/components/Common/AddButton.vue';
import Tabs from '~/components/Common/Tabs.vue';
import Tab from '~/components/Common/Tab.vue';
import {useTranslate} from '~/vendor/gettext';
import {computed, ref, shallowRef, useTemplateRef} from 'vue';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAxios} from '~/vendor/axios';
import {useDialog} from '~/components/Common/Dialogs/useDialog.ts';
import {useApiItemProvider} from '~/functions/dataTable/useApiItemProvider.ts';
import {QueryKeys, queryKeyWithStation} from '~/entities/Queries.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import ClockWheelInlineEditor from '~/components/Stations/ClockWheels/InlineEditor.vue';
import TemplateInlineEditor from '~/components/Stations/ClockWheels/TemplateInlineEditor.vue';
import DaypartInlineEditor from '~/components/Stations/ClockWheels/DaypartInlineEditor.vue';
import PreviewModal from '~/components/Stations/ClockWheels/PreviewModal.vue';
import AnalyticsModal from '~/components/Stations/ClockWheels/AnalyticsModal.vue';
import GenerateModal from '~/components/Stations/ClockWheels/GenerateModal.vue';
import ProgramGridTab from '~/components/Stations/ClockWheels/ProgramGridTab.vue';
import ReconciliationLogTab from '~/components/Stations/ClockWheels/ReconciliationLogTab.vue';
import IconBiChevronRight from '~icons/bi/chevron-right';
import {formatHourOfDayToAmPm} from '~/functions/amPmTime.ts';

const {getStationApiUrl} = useApiRouter();
const listUrl = getStationApiUrl('/clock-wheels');
const templatesUrl = getStationApiUrl('/clock-wheel-templates');
const daypartsUrl = getStationApiUrl('/clock-dayparts');
const programGridUrl = getStationApiUrl('/clock-wheels/program-grid');
const reconciliationLogUrl = getStationApiUrl('/clock-wheels/reconciliation-log');
const generateUrl = getStationApiUrl('/clock-wheels/generate');
const importUrl = getStationApiUrl('/clock-wheels/import');

const $importInput = useTemplateRef('$importInput');

const {$gettext, $ngettext} = useTranslate();
const {notifySuccess, notifyError} = useNotify();
const {axios} = useAxios();
const {confirmDelete} = useDialog();
const syncingDaypartId = ref<number | null>(null);

const wheelEditorOpen = ref(false);
const wheelEditorUrl = ref<string | null>(null);
const wheelEditorKey = ref(0);

const openNewWheelEditor = () => {
    wheelEditorUrl.value = null;
    wheelEditorKey.value += 1;
    wheelEditorOpen.value = true;
};

const openWheelEditor = (recordUrl: string) => {
    wheelEditorUrl.value = recordUrl;
    wheelEditorKey.value += 1;
    wheelEditorOpen.value = true;
};

const closeWheelEditor = () => {
    wheelEditorOpen.value = false;
    wheelEditorUrl.value = null;
};

const templateEditorOpen = ref(false);
const templateEditorUrl = ref<string | null>(null);
const templateEditorKey = ref(0);

const openNewTemplateEditor = () => {
    templateEditorUrl.value = null;
    templateEditorKey.value += 1;
    templateEditorOpen.value = true;
};

const openTemplateEditor = (recordUrl: string) => {
    templateEditorUrl.value = recordUrl;
    templateEditorKey.value += 1;
    templateEditorOpen.value = true;
};

const closeTemplateEditor = () => {
    templateEditorOpen.value = false;
    templateEditorUrl.value = null;
};

const daypartEditorOpen = ref(false);
const daypartEditorUrl = ref<string | null>(null);
const daypartEditorKey = ref(0);

const openNewDaypartEditor = () => {
    daypartEditorUrl.value = null;
    daypartEditorKey.value += 1;
    daypartEditorOpen.value = true;
};

const openDaypartEditor = (recordUrl: string) => {
    daypartEditorUrl.value = recordUrl;
    daypartEditorKey.value += 1;
    daypartEditorOpen.value = true;
};

const closeDaypartEditor = () => {
    daypartEditorOpen.value = false;
    daypartEditorUrl.value = null;
};

type ClockWheelRow = {
    id: number;
    name: string;
    color?: string;
    inherits_template_slots?: boolean;
    links: {self: string};
};

type TemplateRow = {
    id: number;
    name: string;
    color?: string;
    links: {self: string};
};

type DaypartRow = {
    id: number;
    name: string;
    start_hour: number;
    end_hour: number;
    separation_override_enabled?: boolean;
    separation_enabled?: boolean;
    separation_artist_minutes?: number;
    separation_title_minutes?: number;
    links: {self: string};
};

const selectedWheels = shallowRef<ClockWheelRow[]>([]);
const hasSelectedWheels = computed(() => selectedWheels.value.length > 0);

const onWheelRowSelected = (rows: ClockWheelRow[]) => {
    selectedWheels.value = rows;
};

const wheelFields: DataTableField<ClockWheelRow>[] = [
    {key: 'actions', label: $gettext('Actions'), sortable: false, class: 'shrink'},
    {key: 'name', isRowHeader: true, label: $gettext('Name'), sortable: true},
];

const templateFields: DataTableField<TemplateRow>[] = [
    {key: 'name', isRowHeader: true, label: $gettext('Name'), sortable: true},
];

const daypartFields: DataTableField<DaypartRow>[] = [
    {key: 'actions', label: $gettext('Actions'), sortable: false},
    {key: 'name', isRowHeader: true, label: $gettext('Name'), sortable: true},
    {key: 'hours', label: $gettext('Hours'), sortable: false},
    {key: 'separation', label: $gettext('Separation'), sortable: false},
];

const listItemProvider = useApiItemProvider(
    listUrl,
    queryKeyWithStation([QueryKeys.StationPlaylists, 'clock_wheels'])
);

const templateListProvider = useApiItemProvider(
    templatesUrl,
    queryKeyWithStation([QueryKeys.StationPlaylists, 'clock_wheel_templates'])
);

const daypartListProvider = useApiItemProvider(
    daypartsUrl,
    queryKeyWithStation([QueryKeys.StationPlaylists, 'clock_dayparts'])
);

const relistWheels = () => {
    void listItemProvider.refresh();
};

const onWheelEditorSaved = () => {
    relistWheels();
    closeWheelEditor();
};

const relistTemplates = () => {
    void templateListProvider.refresh();
};

const onTemplateEditorSaved = () => {
    relistTemplates();
    relistWheels();
    void daypartListProvider.refresh();
    closeTemplateEditor();
};

const relistDayparts = () => {
    void daypartListProvider.refresh();
    void listItemProvider.refresh();
};

const onDaypartEditorSaved = () => {
    relistDayparts();
    closeDaypartEditor();
};

const $previewModal = useTemplateRef('$previewModal');
const $analyticsModal = useTemplateRef('$analyticsModal');
const $generateModal = useTemplateRef('$generateModal');

const formatHour = (hour: number) => formatHourOfDayToAmPm(hour);

const openPreview = (item: ClockWheelRow) => {
    const url = getStationApiUrl(`/clock-wheel/${item.id}/preview`).value;
    void $previewModal.value?.open(item.name, url);
};

const openAnalytics = (item: ClockWheelRow) => {
    const url = getStationApiUrl(`/clock-wheel/${item.id}/analytics`).value;
    void $analyticsModal.value?.open(item.name, url);
};

const doDeleteSelected = async () => {
    const count = selectedWheels.value.length;
    if (count === 0) {
        return;
    }

    const {value} = await confirmDelete({
        title: $ngettext(
            'Delete %{num} clock wheel?',
            'Delete %{num} clock wheels?',
            count,
            {num: String(count)}
        ),
    });

    if (!value) {
        return;
    }

    try {
        await Promise.all(
            selectedWheels.value.map((item) => axios.delete(item.links.self))
        );
        notifySuccess(
            $ngettext(
                'Clock wheel deleted.',
                'Clock wheels deleted.',
                count
            )
        );
        selectedWheels.value = [];
        relistWheels();
    } catch {
        notifyError($gettext('Could not delete selected clock wheels.'));
        relistWheels();
    }
};

const doExportJson = async (item: ClockWheelRow) => {
    try {
        const exportUrl = item.links.self.replace(/\/?$/, '') + '/export';
        const {data} = await axios.get<Record<string, unknown>>(exportUrl);
        const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'});
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `${item.name || 'clock-wheel'}.json`;
        anchor.click();
        URL.revokeObjectURL(url);
    } catch {
        notifyError($gettext('Could not export clock wheel.'));
    }
};

const doSyncDaypart = async (item: DaypartRow) => {
    const syncUrl = getStationApiUrl(`/clock-daypart/${item.id}/sync`).value;
    syncingDaypartId.value = item.id;

    try {
        await axios.post(syncUrl);
        notifySuccess($gettext('Daypart hourly wheels re-synced from template.'));
        relistDayparts();
    } catch {
        notifyError($gettext('Could not re-sync daypart wheels.'));
    } finally {
        syncingDaypartId.value = null;
    }
};

const triggerImport = () => {
    $importInput.value?.click();
};

const onImportFile = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!file) {
        return;
    }

    try {
        const text = await file.text();
        const payload = JSON.parse(text) as Record<string, unknown>;
        await axios.post(importUrl.value, payload);
        notifySuccess($gettext('Clock wheel imported.'));
        relistWheels();
    } catch {
        notifyError($gettext('Could not import clock wheel JSON.'));
    }
};
</script>

<style scoped>
.clock-wheels-page {
    overflow: visible;
}

.clock-wheels-page-header {
    padding: 1rem 1.25rem;
}

.clock-wheels-page-body {
    min-width: 0;
}

.clock-workflow-guide {
    display: grid;
    grid-template-columns: 1fr auto 1fr auto 1fr;
    align-items: stretch;
    gap: .7rem;
    padding: .8rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .65rem;
    background: color-mix(in srgb, var(--bs-tertiary-bg) 65%, transparent);
}

.clock-workflow-guide__step {
    display: flex;
    align-items: center;
    gap: .65rem;
    min-width: 0;
    padding: .45rem .55rem;
}

.clock-workflow-guide__step div {
    display: grid;
    min-width: 0;
}

.clock-workflow-guide__step span:not(.clock-workflow-guide__number) {
    color: var(--bs-secondary-color);
    font-size: .78rem;
}

.clock-workflow-guide__number {
    display: inline-grid;
    place-items: center;
    width: 1.8rem;
    height: 1.8rem;
    border-radius: 50%;
    background: var(--bs-primary);
    color: var(--bs-white);
    font-size: .78rem;
    font-weight: 800;
    flex: 0 0 auto;
}

.clock-workflow-guide__arrow {
    align-self: center;
    color: var(--bs-secondary-color);
    font-weight: 800;
}

.clock-workspace-list-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
    padding: .9rem 1rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .65rem;
    background: var(--bs-tertiary-bg);
}

.clock-workspace-list-header > div:first-child {
    min-width: 0;
}

.min-width-0 {
    min-width: 0;
}

.clock-wheel-chevron {
    opacity: .35;
    transition: opacity 0.15s, transform 0.15s;
}

tr:hover .clock-wheel-chevron {
    opacity: 1;
    transform: translateX(.15rem);
}

@media (max-width: 991.98px) {
    .clock-workflow-guide {
        grid-template-columns: 1fr;
        gap: .2rem;
    }

    .clock-workflow-guide__arrow {
        display: none;
    }

    .clock-workflow-guide__step {
        padding: .35rem .25rem;
    }
}

@media (max-width: 767.98px) {
    .clock-wheels-page-body {
        padding: .75rem;
    }

    .clock-wheels-primary-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: thin;
    }

    .clock-wheels-primary-tabs :deep(.nav-link) {
        white-space: nowrap;
    }

    .clock-workspace-list-header {
        flex-direction: column;
        align-items: stretch;
        padding: .8rem;
    }

    .clock-workspace-list-header > .btn {
        width: 100%;
    }

    .clock-wheels-toolbar {
        width: 100%;
    }

    .clock-wheels-toolbar > :not(input) {
        flex: 1 1 calc(50% - .5rem);
        min-width: 8.5rem;
    }

    .clock-wheel-list-actions {
        display: grid;
        grid-template-columns: 1fr;
        gap: .3rem;
        width: 100%;
    }
}
</style>
