<template>
    <section
        class="card clock-wheels-page clock-management-page"
        role="region"
        aria-labelledby="hdr_clock_management"
    >
        <div class="clock-management-header">
            <div>
                <h2 id="hdr_clock_management">
                    {{ $gettext('Clock Management') }}
                </h2>
                <p>
                    {{ $gettext('Create and manage your Templates, Dayparts, and Clock Wheels from one workspace.') }}
                </p>
            </div>
            <button
                type="button"
                class="btn btn-outline-primary"
                @click="activeWorkspaceTab = 'overview'"
            >
                {{ $gettext('Help & Overview') }}
            </button>
        </div>

        <div class="clock-management-summary-grid">
            <button
                type="button"
                class="clock-management-summary-card clock-management-summary-card--wheels"
                @click="activeWorkspaceTab = 'wheels'"
            >
                <span class="clock-management-summary-icon" aria-hidden="true">◷</span>
                <span>
                    <span class="clock-management-summary-title">{{ $gettext('Clock Wheels') }}</span>
                    <span class="clock-management-summary-copy">
                        {{ $gettext('Individual hourly clocks that play your content.') }}
                    </span>
                </span>
                <span class="clock-management-summary-count">
                    <strong>{{ displayCount(overviewCounts.wheels) }}</strong>
                    <small>{{ $gettext('Total Wheels') }}</small>
                </span>
            </button>

            <button
                type="button"
                class="clock-management-summary-card clock-management-summary-card--dayparts"
                @click="activeWorkspaceTab = 'dayparts'"
            >
                <span class="clock-management-summary-icon" aria-hidden="true">▦</span>
                <span>
                    <span class="clock-management-summary-title">{{ $gettext('Dayparts') }}</span>
                    <span class="clock-management-summary-copy">
                        {{ $gettext('Time blocks that apply Templates to groups of hours.') }}
                    </span>
                </span>
                <span class="clock-management-summary-count">
                    <strong>{{ displayCount(overviewCounts.dayparts) }}</strong>
                    <small>{{ $gettext('Total Dayparts') }}</small>
                </span>
            </button>

            <button
                type="button"
                class="clock-management-summary-card clock-management-summary-card--templates"
                @click="activeWorkspaceTab = 'templates'"
            >
                <span class="clock-management-summary-icon" aria-hidden="true">▤</span>
                <span>
                    <span class="clock-management-summary-title">{{ $gettext('Templates') }}</span>
                    <span class="clock-management-summary-copy">
                        {{ $gettext('Reusable slot layouts for Dayparts and Clock Wheels.') }}
                    </span>
                </span>
                <span class="clock-management-summary-count">
                    <strong>{{ displayCount(overviewCounts.templates) }}</strong>
                    <small>{{ $gettext('Total Templates') }}</small>
                </span>
            </button>
        </div>

        <div class="clock-management-nav-wrap">
            <nav
                class="nav clock-management-tabs"
                role="tablist"
                aria-label="Clock Management"
            >
                <button
                    v-for="tab in workspaceTabs"
                    :key="tab.id"
                    type="button"
                    class="nav-link"
                    :class="{active: activeWorkspaceTab === tab.id}"
                    :aria-selected="activeWorkspaceTab === tab.id"
                    role="tab"
                    @click="activeWorkspaceTab = tab.id"
                >
                    {{ tab.label }}
                </button>
            </nav>

            <div class="dropdown">
                <button
                    class="btn btn-primary dropdown-toggle"
                    type="button"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                >
                    + {{ $gettext('Create New') }}
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <button
                            type="button"
                            class="dropdown-item"
                            @click="createWheelFromAnywhere"
                        >
                            {{ $gettext('Clock Wheel') }}
                        </button>
                    </li>
                    <li>
                        <button
                            type="button"
                            class="dropdown-item"
                            @click="createDaypartFromAnywhere"
                        >
                            {{ $gettext('Daypart') }}
                        </button>
                    </li>
                    <li>
                        <button
                            type="button"
                            class="dropdown-item"
                            @click="createTemplateFromAnywhere"
                        >
                            {{ $gettext('Template') }}
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <div class="clock-management-content">
            <div
                v-if="activeWorkspaceTab === 'overview'"
                class="clock-management-overview-grid"
            >
                <section class="clock-management-panel">
                    <h3>{{ $gettext('How Clock Management fits together') }}</h3>
                    <div class="clock-management-flow">
                        <div class="clock-management-flow-step">
                            <strong>{{ $gettext('1. Template') }}</strong>
                            <span>{{ $gettext('Build a reusable layout of music, IDs, talk, promos, and other slots.') }}</span>
                        </div>
                        <span class="clock-management-flow-arrow" aria-hidden="true">→</span>
                        <div class="clock-management-flow-step">
                            <strong>{{ $gettext('2. Daypart') }}</strong>
                            <span>{{ $gettext('Apply that Template to a named block of hours such as Morning or Overnight.') }}</span>
                        </div>
                        <span class="clock-management-flow-arrow" aria-hidden="true">→</span>
                        <div class="clock-management-flow-step">
                            <strong>{{ $gettext('3. Clock Wheel') }}</strong>
                            <span>{{ $gettext('Fine-tune the actual hourly clock and manage how it runs on-air.') }}</span>
                        </div>
                    </div>
                </section>

                <section class="clock-management-panel">
                    <h3>{{ $gettext('Quick Actions') }}</h3>
                    <div class="clock-management-actions-grid">
                        <button
                            type="button"
                            class="btn btn-primary"
                            @click="createWheelFromAnywhere"
                        >
                            + {{ $gettext('New Clock Wheel') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-primary"
                            @click="createDaypartFromAnywhere"
                        >
                            + {{ $gettext('New Daypart') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-primary"
                            @click="createTemplateFromAnywhere"
                        >
                            + {{ $gettext('New Template') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            @click="activeWorkspaceTab = 'program-grid'"
                        >
                            {{ $gettext('Open Program Grid') }}
                        </button>
                    </div>
                    <div class="alert alert-info py-2 mt-3 mb-0 small">
                        {{ $gettext('Templates do not air by themselves. Dayparts can generate and maintain groups of hourly Clock Wheels from a Template.') }}
                    </div>
                </section>
            </div>

            <div
                v-else-if="activeWorkspaceTab === 'wheels'"
                class="clock-management-split"
            >
                <aside class="clock-management-list-pane">
                    <div class="clock-management-pane-header">
                        <div>
                            <h3>{{ $gettext('Clock Wheels') }}</h3>
                            <p>{{ $gettext('Choose a wheel to edit it without leaving this page.') }}</p>
                        </div>
                        <div class="clock-management-pane-actions">
                            <add-button
                                :text="$gettext('Add Clock Wheel')"
                                @click="openNewWheelEditor"
                            />
                            <div class="dropdown">
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary clock-management-more-actions"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false"
                                    :aria-label="$gettext('More Clock Wheel actions')"
                                >
                                    ⋮
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <button
                                            type="button"
                                            class="dropdown-item"
                                            @click="$generateModal?.open()"
                                        >
                                            {{ $gettext('Auto-Generate') }}
                                        </button>
                                    </li>
                                    <li>
                                        <button
                                            type="button"
                                            class="dropdown-item"
                                            @click="triggerImport"
                                        >
                                            {{ $gettext('Import') }}
                                        </button>
                                    </li>
                                    <li v-if="activeWheel">
                                        <button
                                            type="button"
                                            class="dropdown-item"
                                            @click="openAnalytics(activeWheel)"
                                        >
                                            {{ $gettext('Analytics') }}
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <button
                                            type="button"
                                            class="dropdown-item text-danger"
                                            :disabled="!hasSelectedWheels"
                                            @click="doDeleteSelected"
                                        >
                                            {{ $gettext('Delete Selected') }}
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <input
                        ref="$importInput"
                        type="file"
                        accept="application/json,.json"
                        class="d-none"
                        @change="onImportFile"
                    >

                    <data-table
                        id="station_clock_wheels"
                        selectable
                        paginated
                        :fields="wheelFields"
                        :provider="listItemProvider"
                        @row-selected="onWheelRowSelected"
                    >
                        <template #cell(name)="{item}">
                            <div
                                class="clock-wheel-browser-row"
                                :class="{'clock-wheel-browser-row--active': activeWheel?.id === item.id}"
                                role="button"
                                tabindex="0"
                                @click="openWheelEditor(item)"
                                @keydown.enter="openWheelEditor(item)"
                            >
                                <div
                                    class="clock-wheel-browser-row__icon"
                                    :style="{borderColor: item.color ?? 'var(--bs-primary)'}"
                                    aria-hidden="true"
                                >
                                    ◷
                                </div>

                                <div class="clock-wheel-browser-row__body">
                                    <div class="clock-wheel-browser-row__title-line">
                                        <h4>{{ item.name }}</h4>
                                        <span
                                            class="badge"
                                            :class="item.is_active === false ? 'text-bg-secondary' : 'text-bg-success'"
                                        >
                                            {{ item.is_active === false ? $gettext('Inactive') : $gettext('Active') }}
                                        </span>
                                    </div>

                                    <div class="clock-wheel-browser-row__meta">
                                        <span class="clock-wheel-browser-row__meta-icon" aria-hidden="true">◷</span>
                                        <span>{{ wheelScheduleSummary(item) }}</span>
                                    </div>
                                    <div class="clock-wheel-browser-row__meta">
                                        <span class="clock-wheel-browser-row__meta-icon" aria-hidden="true">♪</span>
                                        <span>{{ wheelSlotSummary(item) }}</span>
                                    </div>
                                    <div class="clock-wheel-browser-row__meta">
                                        <span class="clock-wheel-browser-row__meta-icon" aria-hidden="true">▣</span>
                                        <span>{{ wheelTemplateSummary(item) }}</span>
                                    </div>
                                </div>

                                <icon-bi-chevron-right class="clock-wheel-browser-row__chevron" />
                            </div>
                        </template>
                    </data-table>

                    <div
                        v-if="activeWheel"
                        class="clock-management-side-cards"
                    >
                        <section class="clock-management-side-card">
                            <h4>{{ $gettext('Template / Day Part Info') }}</h4>
                            <dl class="clock-management-side-card__details">
                                <div>
                                    <dt>{{ $gettext('Source') }}</dt>
                                    <dd>{{ wheelTemplateSummary(activeWheel) }}</dd>
                                </div>
                                <div>
                                    <dt>{{ $gettext('Schedule') }}</dt>
                                    <dd>{{ wheelScheduleSummary(activeWheel) }}</dd>
                                </div>
                                <div>
                                    <dt>{{ $gettext('Status') }}</dt>
                                    <dd>{{ activeWheel.is_active === false ? $gettext('Inactive') : $gettext('Active') }}</dd>
                                </div>
                            </dl>
                        </section>

                        <section class="clock-management-side-card">
                            <h4>{{ $gettext('Quick Actions') }}</h4>
                            <div class="clock-management-side-card__actions">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="openPreview(activeWheel)"
                                >
                                    {{ $gettext('Preview') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="activeWorkspaceTab = 'program-grid'"
                                >
                                    {{ $gettext('Open in Program Grid') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="doExportJson(activeWheel)"
                                >
                                    {{ $gettext('Export Clock') }}
                                </button>
                            </div>
                        </section>
                    </div>
                </aside>

                <section class="clock-management-editor-pane">
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
                        class="clock-management-empty-state"
                    >
                        <div class="clock-management-empty-state__inner">
                            <div class="clock-management-empty-state__icon" aria-hidden="true">◷</div>
                            <h3 class="h5">{{ $gettext('Select a Clock Wheel') }}</h3>
                            <p class="text-muted">
                                {{ $gettext('Choose a wheel from the list to edit it inline, or create a new wheel.') }}
                            </p>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="openNewWheelEditor"
                            >
                                + {{ $gettext('Add Clock Wheel') }}
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            <div
                v-else-if="activeWorkspaceTab === 'dayparts'"
                class="clock-management-split"
            >
                <aside class="clock-management-list-pane">
                    <div class="clock-management-pane-header">
                        <div>
                            <h3>{{ $gettext('Dayparts') }}</h3>
                            <p>{{ $gettext('Apply a Template to a block of station hours.') }}</p>
                        </div>
                        <add-button
                            :text="$gettext('Add')"
                            @click="openNewDaypartEditor"
                        />
                    </div>

                    <data-table
                        id="station_clock_dayparts"
                        paginated
                        :fields="daypartFields"
                        :provider="daypartListProvider"
                    >
                        <template #cell(name)="{item}">
                            <div
                                class="d-flex align-items-center gap-2 clock-wheel-row"
                                role="button"
                                tabindex="0"
                                @click="openDaypartEditor(item)"
                                @keydown.enter="openDaypartEditor(item)"
                            >
                                <span class="clock-management-summary-icon flex-shrink-0 clock-management-mini-icon">▦</span>
                                <div class="flex-grow-1 min-width-0">
                                    <h5 class="m-0 text-truncate">{{ item.name }}</h5>
                                    <small class="text-muted">
                                        {{ formatHour(item.start_hour) }} – {{ formatHour(item.end_hour) }}
                                    </small>
                                </div>
                                <icon-bi-chevron-right class="clock-wheel-chevron text-muted flex-shrink-0" />
                            </div>
                        </template>
                        <template #cell(hours)="{item}">
                            {{ formatHour(item.start_hour) }} – {{ formatHour(item.end_hour) }}
                        </template>
                        <template #cell(separation)="{item}">
                            <span v-if="item.separation_override_enabled && item.separation_enabled">
                                {{ item.separation_artist_minutes }}/{{ item.separation_title_minutes }} min
                            </span>
                            <span
                                v-else-if="item.separation_override_enabled"
                                class="text-muted"
                            >
                                {{ $gettext('Off') }}
                            </span>
                            <span v-else class="text-muted">—</span>
                        </template>
                    </data-table>

                    <div
                        v-if="activeDaypart"
                        class="clock-management-quick-actions"
                    >
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            :disabled="syncingDaypartId === activeDaypart.id"
                            @click="doSyncDaypart(activeDaypart)"
                        >
                            {{ syncingDaypartId === activeDaypart.id ? $gettext('Syncing…') : $gettext('Re-sync Wheels') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            @click="activeWorkspaceTab = 'wheels'"
                        >
                            {{ $gettext('View Wheels') }}
                        </button>
                    </div>
                </aside>

                <section class="clock-management-editor-pane">
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
                        class="clock-management-empty-state"
                    >
                        <div class="clock-management-empty-state__inner">
                            <div class="clock-management-empty-state__icon" aria-hidden="true">▦</div>
                            <h3 class="h5">{{ $gettext('Select a Daypart') }}</h3>
                            <p class="text-muted">
                                {{ $gettext('Choose a Daypart to edit its Template, hour range, and optional separation overrides.') }}
                            </p>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="openNewDaypartEditor"
                            >
                                + {{ $gettext('Add Daypart') }}
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            <div
                v-else-if="activeWorkspaceTab === 'templates'"
                class="clock-management-split"
            >
                <aside class="clock-management-list-pane">
                    <div class="clock-management-pane-header">
                        <div>
                            <h3>{{ $gettext('Templates') }}</h3>
                            <p>{{ $gettext('Reusable slot layouts that can feed many Dayparts and Wheels.') }}</p>
                        </div>
                        <add-button
                            :text="$gettext('Add')"
                            @click="openNewTemplateEditor"
                        />
                    </div>

                    <data-table
                        id="station_clock_wheel_templates"
                        paginated
                        :fields="templateFields"
                        :provider="templateListProvider"
                    >
                        <template #cell(name)="{item}">
                            <div
                                class="d-flex align-items-center gap-2 clock-wheel-row"
                                role="button"
                                tabindex="0"
                                @click="openTemplateEditor(item)"
                                @keydown.enter="openTemplateEditor(item)"
                            >
                                <span
                                    class="d-inline-block rounded flex-shrink-0"
                                    :style="{backgroundColor: item.color ?? '#7c3aed'}"
                                    style="width: 2rem; height: 2rem;"
                                />
                                <div class="flex-grow-1 min-width-0">
                                    <h5 class="m-0 text-truncate">{{ item.name }}</h5>
                                    <small class="text-muted">{{ $gettext('Reusable clock layout') }}</small>
                                </div>
                                <icon-bi-chevron-right class="clock-wheel-chevron text-muted flex-shrink-0" />
                            </div>
                        </template>
                    </data-table>

                    <div
                        v-if="activeTemplate"
                        class="clock-management-quick-actions"
                    >
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            @click="activeWorkspaceTab = 'dayparts'"
                        >
                            {{ $gettext('View Dayparts') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            @click="activeWorkspaceTab = 'wheels'"
                        >
                            {{ $gettext('View Wheels') }}
                        </button>
                    </div>
                </aside>

                <section class="clock-management-editor-pane">
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
                        class="clock-management-empty-state"
                    >
                        <div class="clock-management-empty-state__inner">
                            <div class="clock-management-empty-state__icon" aria-hidden="true">▤</div>
                            <h3 class="h5">{{ $gettext('Select a Template') }}</h3>
                            <p class="text-muted">
                                {{ $gettext('Choose a Template to edit its reusable slot layout, or create a new one.') }}
                            </p>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="openNewTemplateEditor"
                            >
                                + {{ $gettext('Add Template') }}
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            <section
                v-else-if="activeWorkspaceTab === 'program-grid'"
                class="clock-management-panel"
            >
                <div class="clock-management-pane-header mb-3">
                    <div>
                        <h3>{{ $gettext('Program Grid') }}</h3>
                        <p>{{ $gettext('See which Clock Wheels are expected to run across the schedule and spot gaps quickly.') }}</p>
                    </div>
                </div>
                <program-grid-tab :grid-url="programGridUrl" />
            </section>

            <section
                v-else
                class="clock-management-panel"
            >
                <div class="clock-management-pane-header mb-3">
                    <div>
                        <h3>{{ $gettext('Reconciliation') }}</h3>
                        <p>{{ $gettext('Review what was scheduled versus what actually played.') }}</p>
                    </div>
                </div>
                <reconciliation-log-tab :log-url="reconciliationLogUrl" />
            </section>
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
import {computed, onMounted, ref, shallowRef, useTemplateRef} from 'vue';
import DataTable, {type DataTableField} from '~/components/Common/DataTable.vue';
import AddButton from '~/components/Common/AddButton.vue';
import {useTranslate} from '~/vendor/gettext';
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
import normalizeStationScheduleDays from '~/functions/normalizeStationScheduleDays.ts';

type WorkspaceTab = 'overview' | 'wheels' | 'dayparts' | 'templates' | 'program-grid' | 'reconciliation';

type ClockWheelScheduleSummary = {
    start_time?: number | null;
    end_time?: number | null;
    days?: unknown;
    recurrence_type?: string | null;
};

type ClockWheelRow = {
    id: number;
    name: string;
    color?: string;
    is_active?: boolean;
    inherits_template_slots?: boolean;
    template_id?: number | null;
    template?: {id?: number; name?: string} | null;
    daypart_id?: number | null;
    slots?: unknown[];
    schedule_items?: ClockWheelScheduleSummary[];
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

const {getStationApiUrl} = useApiRouter();
const listUrl = getStationApiUrl('/clock-wheels');
const templatesUrl = getStationApiUrl('/clock-wheel-templates');
const daypartsUrl = getStationApiUrl('/clock-dayparts');
const programGridUrl = getStationApiUrl('/clock-wheels/program-grid');
const reconciliationLogUrl = getStationApiUrl('/clock-wheels/reconciliation-log');
const generateUrl = getStationApiUrl('/clock-wheels/generate');
const importUrl = getStationApiUrl('/clock-wheels/import');

const {$gettext, $ngettext} = useTranslate();
const {notifySuccess, notifyError} = useNotify();
const {axios} = useAxios();
const {confirmDelete} = useDialog();

const activeWorkspaceTab = ref<WorkspaceTab>('wheels');
const workspaceTabs = computed<Array<{id: WorkspaceTab; label: string}>>(() => [
    {id: 'overview', label: $gettext('Overview')},
    {id: 'wheels', label: $gettext('Clock Wheels')},
    {id: 'dayparts', label: $gettext('Dayparts')},
    {id: 'templates', label: $gettext('Templates')},
    {id: 'program-grid', label: $gettext('Program Grid')},
    {id: 'reconciliation', label: $gettext('Reconciliation')},
]);

const overviewCounts = ref<{wheels: number | null; dayparts: number | null; templates: number | null}>({
    wheels: null,
    dayparts: null,
    templates: null,
});

const collectionCount = (data: unknown): number => {
    if (Array.isArray(data)) {
        return data.length;
    }
    if (data && typeof data === 'object') {
        const record = data as Record<string, unknown>;
        for (const key of ['total', 'total_count', 'totalCount']) {
            if (typeof record[key] === 'number') {
                return record[key] as number;
            }
        }
        for (const key of ['rows', 'items', 'results']) {
            if (Array.isArray(record[key])) {
                return (record[key] as unknown[]).length;
            }
        }
    }
    return 0;
};

const displayCount = (count: number | null) => count === null ? '—' : String(count);

const refreshOverviewCounts = async () => {
    const [wheels, dayparts, templates] = await Promise.allSettled([
        axios.get(listUrl.value),
        axios.get(daypartsUrl.value),
        axios.get(templatesUrl.value),
    ]);

    overviewCounts.value = {
        wheels: wheels.status === 'fulfilled' ? collectionCount(wheels.value.data) : overviewCounts.value.wheels,
        dayparts: dayparts.status === 'fulfilled' ? collectionCount(dayparts.value.data) : overviewCounts.value.dayparts,
        templates: templates.status === 'fulfilled' ? collectionCount(templates.value.data) : overviewCounts.value.templates,
    };
};

onMounted(() => {
    void refreshOverviewCounts();
});

const $importInput = useTemplateRef('$importInput');
const $previewModal = useTemplateRef('$previewModal');
const $analyticsModal = useTemplateRef('$analyticsModal');
const $generateModal = useTemplateRef('$generateModal');

const syncingDaypartId = ref<number | null>(null);

const wheelEditorOpen = ref(false);
const wheelEditorUrl = ref<string | null>(null);
const wheelEditorKey = ref(0);
const activeWheel = ref<ClockWheelRow | null>(null);

const openNewWheelEditor = () => {
    activeWorkspaceTab.value = 'wheels';
    activeWheel.value = null;
    wheelEditorUrl.value = null;
    wheelEditorKey.value += 1;
    wheelEditorOpen.value = true;
};

const openWheelEditor = (item: ClockWheelRow) => {
    activeWorkspaceTab.value = 'wheels';
    activeWheel.value = item;
    wheelEditorUrl.value = item.links.self;
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
const activeTemplate = ref<TemplateRow | null>(null);

const openNewTemplateEditor = () => {
    activeWorkspaceTab.value = 'templates';
    activeTemplate.value = null;
    templateEditorUrl.value = null;
    templateEditorKey.value += 1;
    templateEditorOpen.value = true;
};

const openTemplateEditor = (item: TemplateRow) => {
    activeWorkspaceTab.value = 'templates';
    activeTemplate.value = item;
    templateEditorUrl.value = item.links.self;
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
const activeDaypart = ref<DaypartRow | null>(null);

const openNewDaypartEditor = () => {
    activeWorkspaceTab.value = 'dayparts';
    activeDaypart.value = null;
    daypartEditorUrl.value = null;
    daypartEditorKey.value += 1;
    daypartEditorOpen.value = true;
};

const openDaypartEditor = (item: DaypartRow) => {
    activeWorkspaceTab.value = 'dayparts';
    activeDaypart.value = item;
    daypartEditorUrl.value = item.links.self;
    daypartEditorKey.value += 1;
    daypartEditorOpen.value = true;
};

const closeDaypartEditor = () => {
    daypartEditorOpen.value = false;
    daypartEditorUrl.value = null;
};

const createWheelFromAnywhere = () => openNewWheelEditor();
const createTemplateFromAnywhere = () => openNewTemplateEditor();
const createDaypartFromAnywhere = () => openNewDaypartEditor();

const selectedWheels = shallowRef<ClockWheelRow[]>([]);
const hasSelectedWheels = computed(() => selectedWheels.value.length > 0);

const onWheelRowSelected = (rows: ClockWheelRow[]) => {
    selectedWheels.value = rows;
};

const wheelFields: DataTableField<ClockWheelRow>[] = [
    {key: 'name', isRowHeader: true, label: $gettext('Clock Wheel'), sortable: true},
];

const templateFields: DataTableField<TemplateRow>[] = [
    {key: 'name', isRowHeader: true, label: $gettext('Template'), sortable: true},
];

const daypartFields: DataTableField<DaypartRow>[] = [
    {key: 'name', isRowHeader: true, label: $gettext('Daypart'), sortable: true},
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

const formatClockTime = (value: unknown): string => {
    const numericValue = Number(value);
    if (!Number.isFinite(numericValue) || numericValue < 0) {
        return '';
    }

    const hours24 = Math.floor(numericValue / 100) % 24;
    const minutes = Math.floor(numericValue % 100);
    const suffix = hours24 >= 12 ? 'PM' : 'AM';
    const hours12 = hours24 % 12 || 12;
    return `${hours12}:${String(minutes).padStart(2, '0')} ${suffix}`;
};

const formatScheduleDays = (days: unknown): string => {
    const normalized = normalizeStationScheduleDays(days);
    const key = normalized.join(',');
    if (key === '1,2,3,4,5,6,7') {
        return $gettext('Daily');
    }
    if (key === '1,2,3,4,5') {
        return $gettext('Mon–Fri');
    }
    if (key === '6,7') {
        return $gettext('Sat–Sun');
    }

    const labels = [
        $gettext('Mon'),
        $gettext('Tue'),
        $gettext('Wed'),
        $gettext('Thu'),
        $gettext('Fri'),
        $gettext('Sat'),
        $gettext('Sun'),
    ];
    return normalized.map((day) => labels[day - 1]).join(', ');
};

const wheelScheduleSummary = (item: ClockWheelRow): string => {
    const schedules = item.schedule_items ?? [];
    const schedule = schedules[0];
    if (!schedule) {
        return $gettext('Not scheduled');
    }

    const days = formatScheduleDays(schedule.days);
    const start = formatClockTime(schedule.start_time);
    const end = formatClockTime(schedule.end_time);
    const time = start && end ? `${start}–${end}` : start || end;
    const base = [days, time].filter(Boolean).join(' · ') || $gettext('Scheduled');

    return schedules.length > 1
        ? $gettext('%{summary} · +%{count} more', {
            summary: base,
            count: schedules.length - 1,
        })
        : base;
};

const wheelSlotSummary = (item: ClockWheelRow): string => {
    if (!Array.isArray(item.slots)) {
        return $gettext('Slots available in editor');
    }

    return $ngettext(
        '%{count} slot',
        '%{count} slots',
        item.slots.length,
        {count: String(item.slots.length)}
    );
};

const wheelTemplateSummary = (item: ClockWheelRow): string => {
    if (item.template?.name) {
        return $gettext('Template: %{name}', {name: item.template.name});
    }
    if (item.inherits_template_slots && item.template_id) {
        return $gettext('Linked template #%{id}', {id: item.template_id});
    }
    if (item.daypart_id) {
        return $gettext('Managed by Daypart');
    }
    return $gettext('Custom configuration');
};

const relistWheels = () => {
    void listItemProvider.refresh();
    void refreshOverviewCounts();
};

const onWheelEditorSaved = () => {
    relistWheels();
    closeWheelEditor();
};

const relistTemplates = () => {
    void templateListProvider.refresh();
    void refreshOverviewCounts();
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
    void refreshOverviewCounts();
};

const onDaypartEditorSaved = () => {
    relistDayparts();
    closeDaypartEditor();
};

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
        activeWheel.value = null;
        closeWheelEditor();
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
