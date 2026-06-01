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
                    :fields="fields"
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
        </div>
    </section>

    <edit-modal
        ref="$editModal"
        :create-url="listUrl"
        @relist="relist"
    />

    <preview-modal ref="$previewModal" />
    <analytics-modal ref="$analyticsModal" />
</template>

<script setup lang="ts">
import DataTable, {DataTableField} from '~/components/Common/DataTable.vue';
import AddButton from '~/components/Common/AddButton.vue';
import {useTranslate} from '~/vendor/gettext';
import {useTemplateRef} from 'vue';
import useHasEditModal from '~/functions/useHasEditModal';
import {useApiItemProvider} from '~/functions/dataTable/useApiItemProvider.ts';
import {QueryKeys, queryKeyWithStation} from '~/entities/Queries.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import EditModal from '~/components/Stations/ClockWheels/EditModal.vue';
import PreviewModal from '~/components/Stations/ClockWheels/PreviewModal.vue';
import AnalyticsModal from '~/components/Stations/ClockWheels/AnalyticsModal.vue';
import IconBiChevronRight from '~icons/bi/chevron-right';

const {getStationApiUrl} = useApiRouter();
const listUrl = getStationApiUrl('/clock-wheels');

const {$gettext} = useTranslate();

type ClockWheelRow = {
    id: number;
    name: string;
    links: {self: string};
};

const fields: DataTableField<ClockWheelRow>[] = [
    {key: 'actions', label: $gettext('Actions'), sortable: false},
    {key: 'name', isRowHeader: true, label: $gettext('Name'), sortable: true},
];

const listItemProvider = useApiItemProvider(
    listUrl,
    queryKeyWithStation([QueryKeys.StationPlaylists, 'clock_wheels'])
);

const relist = () => {
    void listItemProvider.refresh();
};

const $editModal = useTemplateRef('$editModal');
const $previewModal = useTemplateRef('$previewModal');
const $analyticsModal = useTemplateRef('$analyticsModal');

const {doCreate, doEdit} = useHasEditModal($editModal);

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
