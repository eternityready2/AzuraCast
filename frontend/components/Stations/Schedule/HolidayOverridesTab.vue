<template>
    <div>
        <div class="card-body buttons mb-2">
            <add-button
                :text="$gettext('Add Holiday Override')"
                @click="openCreate"
            />
        </div>

        <loading :loading="loading">
            <data-table
                id="station_holiday_overrides"
                :fields="fields"
                :items="rows"
            >
                <template #cell(actions)="{ item }">
                    <div class="btn-group btn-group-sm">
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            @click="openEdit(item)"
                        >
                            {{ $gettext('Edit') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-danger"
                            @click="doDeleteRow(item)"
                        >
                            {{ $gettext('Delete') }}
                        </button>
                    </div>
                </template>
            </data-table>
        </loading>

        <modal-form
            ref="$modal"
            :loading="saving"
            :title="modalTitle"
            @submit="save"
        >
            <form-group-field
                id="holiday_name"
                v-model="form.name"
                class="mb-3"
                :label="$gettext('Name')"
            />
            <form-group-field
                id="holiday_date"
                v-model="form.override_date"
                class="mb-3"
                type="date"
                :label="$gettext('Date')"
            />
            <form-group-select
                id="holiday_wheel"
                v-model="form.clock_wheel_id"
                class="mb-3"
                :label="$gettext('Clock wheel override')"
                :options="wheelOptions"
            />
            <form-group-select
                id="holiday_playlist"
                v-model="form.playlist_id"
                class="mb-3"
                :label="$gettext('Playlist override')"
                :options="playlistOptions"
            />
            <form-group-checkbox
                id="holiday_active"
                v-model="form.is_active"
                :label="$gettext('Active')"
            />
            <form-group-field
                id="holiday_notes"
                v-model="form.notes"
                class="mt-3"
                :label="$gettext('Notes')"
            />
        </modal-form>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, ref, useTemplateRef} from 'vue';
import Loading from '~/components/Common/Loading.vue';
import DataTable, {DataTableField} from '~/components/Common/DataTable.vue';
import AddButton from '~/components/Common/AddButton.vue';
import ModalForm from '~/components/Common/ModalForm.vue';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import FormGroupSelect from '~/components/Form/FormGroupSelect.vue';
import FormGroupCheckbox from '~/components/Form/FormGroupCheckbox.vue';
import {useAxios} from '~/vendor/axios.ts';
import {useTranslate} from '~/vendor/gettext';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import useConfirmAndDelete from '~/functions/useConfirmAndDelete.ts';

interface HolidayRow {
    id: number;
    name: string;
    override_date: string;
    clock_wheel_id: number | null;
    playlist_id: number | null;
    is_active: boolean;
    notes: string | null;
    links?: {self: string};
}

const props = defineProps<{
    listUrl: string;
    wheelsUrl: string;
    playlistsUrl: string;
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const $modal = useTemplateRef('$modal');

const loading = ref(false);
const saving = ref(false);
const rows = ref<HolidayRow[]>([]);
const editUrl = ref<string | null>(null);
const wheelOptions = ref<{value: number | null; text: string}[]>([]);
const playlistOptions = ref<{value: number | null; text: string}[]>([]);

const blankForm = () => ({
    name: '',
    override_date: '',
    clock_wheel_id: null as number | null,
    playlist_id: null as number | null,
    is_active: true,
    notes: '',
});

const form = ref(blankForm());

const fields: DataTableField[] = [
    {key: 'override_date', label: $gettext('Date'), sortable: true},
    {key: 'name', label: $gettext('Name'), sortable: true},
    {key: 'is_active', label: $gettext('Active'), sortable: true},
    {key: 'actions', label: '', sortable: false},
];

const modalTitle = computed(() =>
    editUrl.value ? $gettext('Edit Holiday Override') : $gettext('Add Holiday Override')
);

const load = async () => {
    loading.value = true;
    try {
        const {data} = await axios.get<{rows?: HolidayRow[]} | HolidayRow[]>(props.listUrl);
        rows.value = Array.isArray(data) ? data : (data.rows ?? []);
    } finally {
        loading.value = false;
    }
};

const loadOptions = async () => {
    const [{data: wheels}, {data: playlists}] = await Promise.all([
        axios.get<{rows: {id: number; name: string}[]}>(props.wheelsUrl, {params: {rowCount: 0}}),
        axios.get<{rows: {id: number; name: string}[]}>(props.playlistsUrl, {params: {rowCount: 0}}),
    ]);

    wheelOptions.value = [
        {value: null, text: $gettext('— None —')},
        ...(wheels.rows ?? []).map((w) => ({value: w.id, text: w.name})),
    ];
    playlistOptions.value = [
        {value: null, text: $gettext('— None —')},
        ...(playlists.rows ?? []).map((p) => ({value: p.id, text: p.name})),
    ];
};

const openCreate = () => {
    editUrl.value = null;
    form.value = blankForm();
    $modal.value?.show();
};

const openEdit = (item: HolidayRow) => {
    editUrl.value = item.links?.self ?? getStationApiUrl(`/holiday-override/${item.id}`).value;
    form.value = {
        name: item.name,
        override_date: item.override_date,
        clock_wheel_id: item.clock_wheel_id,
        playlist_id: item.playlist_id,
        is_active: item.is_active,
        notes: item.notes ?? '',
    };
    $modal.value?.show();
};

const save = async () => {
    saving.value = true;
    try {
        const payload = {...form.value};
        if (editUrl.value) {
            await axios.put(editUrl.value, payload);
        } else {
            await axios.post(props.listUrl, payload);
        }
        $modal.value?.hide();
        await load();
    } finally {
        saving.value = false;
    }
};

const {doDelete} = useConfirmAndDelete($gettext('Delete this holiday override?'), load);

const doDeleteRow = (item: HolidayRow) => {
    const url = item.links?.self ?? getStationApiUrl(`/holiday-override/${item.id}`).value;
    void doDelete(url);
};

onMounted(async () => {
    await Promise.all([load(), loadOptions()]);
});

defineExpose({doDelete: doDeleteRow});
</script>
