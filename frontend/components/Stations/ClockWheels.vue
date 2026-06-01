<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_clock_wheels"
    >
        <div class="card-header text-bg-primary">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2
                        id="hdr_clock_wheels"
                        class="card-title"
                    >
                        {{ $gettext('Manage Clock Wheels') }}
                    </h2>
                </div>
            </div>
        </div>

        <div class="card-body">
            <tabs
                nav-tabs-class="nav-tabs"
                content-class="mt-3"
                destroy-on-hide
            >
                <tab :label="$gettext('Wheels')">
                    <div class="card-body-flush">
                        <div class="card-body buttons">
                            <add-button
                                :text="$gettext('Add Clock Wheel')"
                                @click="doCreate"
                            />
                        </div>

                        <data-table
                            id="station_clock_wheels"
                            paginated
                            :fields="wheelFields"
                            :provider="listItemProvider"
                        >
                            <template #cell(actions)="{ item }">
                                <div
                                    class="btn-group btn-group-sm"
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
                                </div>
                            </template>
                            <template #cell(name)="{ item }">
                                <div
                                    class="d-flex align-items-center gap-3 clock-wheel-row"
                                    role="button"
                                    style="cursor: pointer;"
                                    @click="doEdit(item.links.self)"
                                >
                                    <h5 class="m-0 flex-grow-1">
                                        {{ item.name }}
                                        <span
                                            v-if="item.inherits_template_slots"
                                            class="badge text-bg-secondary ms-1"
                                        >
                                            {{ $gettext('Template') }}
                                        </span>
                                    </h5>
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
                    <div class="card-body-flush">
                        <div class="card-body buttons">
                            <add-button
                                :text="$gettext('Add Template')"
                                @click="doCreateTemplate"
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
                                    @click="doEditTemplate(item.links.self)"
                                >
                                    <h5 class="m-0 flex-grow-1">{{ item.name }}</h5>
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
                    <div class="card-body-flush">
                        <div class="card-body buttons">
                            <add-button
                                :text="$gettext('Add Daypart')"
                                @click="doCreateDaypart"
                            />
                        </div>

                        <data-table
                            id="station_clock_dayparts"
                            paginated
                            :fields="daypartFields"
                            :provider="daypartListProvider"
                        >
                            <template #cell(name)="{ item }">
                                <div
                                    class="d-flex align-items-center gap-3 clock-wheel-row"
                                    role="button"
                                    style="cursor: pointer;"
                                    @click="doEditDaypart(item.links.self)"
                                >
                                    <h5 class="m-0 flex-grow-1">
                                        {{ item.name }}
                                        <span
                                            v-if="item.separation_override_enabled"
                                            class="badge text-bg-info ms-1"
                                        >
                                            {{ $gettext('Separation') }}
                                        </span>
                                    </h5>
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
            </tabs>
        </div>
    </section>

    <edit-modal
        ref="$editModal"
        :create-url="listUrl"
        @relist="relistWheels"
    />

    <template-edit-modal
        ref="$templateEditModal"
        :create-url="templatesUrl"
        @relist="relistTemplates"
    />

    <daypart-edit-modal
        ref="$daypartEditModal"
        :create-url="daypartsUrl"
        :templates-url="templatesUrl"
        @relist="relistDayparts"
    />

    <preview-modal ref="$previewModal" />
    <analytics-modal ref="$analyticsModal" />
</template>

<script setup lang="ts">
import DataTable, {DataTableField} from '~/components/Common/DataTable.vue';
import AddButton from '~/components/Common/AddButton.vue';
import Tabs from '~/components/Common/Tabs.vue';
import Tab from '~/components/Common/Tab.vue';
import {useTranslate} from '~/vendor/gettext';
import {useTemplateRef} from 'vue';
import useHasEditModal from '~/functions/useHasEditModal';
import {useApiItemProvider} from '~/functions/dataTable/useApiItemProvider.ts';
import {QueryKeys, queryKeyWithStation} from '~/entities/Queries.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import EditModal from '~/components/Stations/ClockWheels/EditModal.vue';
import TemplateEditModal from '~/components/Stations/ClockWheels/TemplateEditModal.vue';
import DaypartEditModal from '~/components/Stations/ClockWheels/DaypartEditModal.vue';
import PreviewModal from '~/components/Stations/ClockWheels/PreviewModal.vue';
import AnalyticsModal from '~/components/Stations/ClockWheels/AnalyticsModal.vue';
import IconBiChevronRight from '~icons/bi/chevron-right';

const {getStationApiUrl} = useApiRouter();
const listUrl = getStationApiUrl('/clock-wheels');
const templatesUrl = getStationApiUrl('/clock-wheel-templates');
const daypartsUrl = getStationApiUrl('/clock-dayparts');

const {$gettext} = useTranslate();

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

const wheelFields: DataTableField<ClockWheelRow>[] = [
    {key: 'actions', label: $gettext('Actions'), sortable: false},
    {key: 'name', isRowHeader: true, label: $gettext('Name'), sortable: true},
];

const templateFields: DataTableField<TemplateRow>[] = [
    {key: 'name', isRowHeader: true, label: $gettext('Name'), sortable: true},
];

const daypartFields: DataTableField<DaypartRow>[] = [
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

const relistTemplates = () => {
    void templateListProvider.refresh();
};

const relistDayparts = () => {
    void daypartListProvider.refresh();
    void listItemProvider.refresh();
};

const $editModal = useTemplateRef('$editModal');
const $templateEditModal = useTemplateRef('$templateEditModal');
const $daypartEditModal = useTemplateRef('$daypartEditModal');
const $previewModal = useTemplateRef('$previewModal');
const $analyticsModal = useTemplateRef('$analyticsModal');

const {doCreate, doEdit} = useHasEditModal($editModal);
const {doCreate: doCreateTemplate, doEdit: doEditTemplate} = useHasEditModal($templateEditModal);
const {doCreate: doCreateDaypart, doEdit: doEditDaypart} = useHasEditModal($daypartEditModal);

const formatHour = (hour: number) => `${String(hour).padStart(2, '0')}:00`;

const openPreview = (item: ClockWheelRow) => {
    const url = getStationApiUrl(`/clock-wheel/${item.id}/preview`).value;
    void $previewModal.value?.open(item.name, url);
};

const openAnalytics = (item: ClockWheelRow) => {
    const url = getStationApiUrl(`/clock-wheel/${item.id}/analytics`).value;
    void $analyticsModal.value?.open(item.name, url);
};
</script>

<style scoped>
.clock-wheel-chevron {
    opacity: 0;
    transition: opacity 0.15s;
}

tr:hover .clock-wheel-chevron {
    opacity: 1;
}
</style>
