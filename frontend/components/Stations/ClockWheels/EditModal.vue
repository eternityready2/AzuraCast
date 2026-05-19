<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="langTitle"
        :error="error"
        :disable-save-button="r$.$invalid"
        @submit="doSubmit"
        @hidden="clearContents"
    >
        <!-- Title -->
        <div class="mb-3">
            <form-group-field
                id="name"
                :field="r$.name"
                :label="$gettext('Title')"
            />
        </div>

        <!-- Color swatch -->
        <div class="mb-4">
            <label class="form-label fw-semibold">{{ $gettext('Color') }} *</label>
            <div>
                <input
                    id="color"
                    v-model="form.color"
                    type="color"
                    class="color-swatch-input"
                    style="width: 3rem; height: 3rem; padding: 0.15rem; border: 2px solid #555; border-radius: 6px; cursor: pointer; background: none;"
                />
            </div>
        </div>

        <!-- Entries section -->
        <div class="mb-1">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-semibold">
                    {{ $gettext('Clockwheel entries') }} ({{ entries.length }})
                </span>
            </div>

            <table class="table table-sm table-bordered mb-0">
                <thead>
                    <tr>
                        <th
                            class="text-uppercase small text-center"
                            style="width: 70px;"
                        >
                            {{ $gettext('Order') }}
                        </th>
                        <th class="text-uppercase small">{{ $gettext('Type or Category') }}</th>
                        <th class="text-uppercase small">{{ $gettext('Algorithm') }}</th>
                        <th class="text-uppercase small">{{ $gettext('Pin to Playlist') }}</th>
                        <th
                            class="text-uppercase small text-center"
                            style="width: 110px;"
                        >
                            {{ $gettext('Duration (s)') }}
                        </th>
                        <th
                            class="text-uppercase small text-center"
                            style="width: 60px;"
                        >
                            {{ $gettext('Del') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="entries.length === 0">
                        <td
                            colspan="6"
                            class="text-center text-muted py-3"
                        >
                            {{ $gettext('No Clockwheel Entries found.') }}
                        </td>
                    </tr>
                    <tr
                        v-for="(entry, index) in entries"
                        :key="index"
                    >
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    :disabled="index === 0"
                                    :title="$gettext('Move up')"
                                    @click="moveEntry(index, -1)"
                                >
                                    &#x25B2;
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    :disabled="index === entries.length - 1"
                                    :title="$gettext('Move down')"
                                    @click="moveEntry(index, 1)"
                                >
                                    &#x25BC;
                                </button>
                            </div>
                        </td>
                        <td>
                            <select
                                v-model="entry.slot_value"
                                class="form-select form-select-sm"
                            >
                                <optgroup :label="$gettext('Types')">
                                    <option value="type:music">{{ $gettext('Music (music and copyrighted material)') }}</option>
                                    <option value="type:talk">{{ $gettext('Talk (sermons, speeches, and live recordings)') }}</option>
                                    <option value="type:id">{{ $gettext('ID (station identification such as sweepers and jingles)') }}</option>
                                    <option value="type:promo">{{ $gettext('Promo (station promotion that is not considered an ID)') }}</option>
                                    <option value="type:ad">{{ $gettext('Ad (advert replacement files)') }}</option>
                                </optgroup>
                                <optgroup
                                    v-if="categories.length > 0"
                                    :label="$gettext('Categories')"
                                >
                                    <option
                                        v-for="cat in categories"
                                        :key="cat.id"
                                        :value="'cat:' + cat.id"
                                    >
                                        {{ cat.name }}
                                    </option>
                                </optgroup>
                            </select>
                        </td>
                        <td>
                            <select
                                v-model="entry.algorithm"
                                class="form-select form-select-sm"
                            >
                                <option value="random">{{ $gettext('Random') }}</option>
                                <option value="oldest_album">{{ $gettext('Oldest Album') }}</option>
                                <option value="oldest_artist">{{ $gettext('Oldest Artist') }}</option>
                                <option value="oldest_track">{{ $gettext('Oldest Track') }}</option>
                                <option value="most_recent_album">{{ $gettext('Most Recent Album') }}</option>
                                <option value="most_recent_artist">{{ $gettext('Most Recent Artist') }}</option>
                            </select>
                        </td>
                        <td>
                            <select
                                v-model="entry.playlist_id"
                                class="form-select form-select-sm"
                            >
                                <option :value="null">{{ $gettext('(none)') }}</option>
                                <option
                                    v-for="pl in playlists"
                                    :key="pl.id"
                                    :value="pl.id"
                                >
                                    {{ pl.name }}
                                </option>
                            </select>
                        </td>
                        <td>
                            <input
                                v-model="entry.duration_seconds"
                                type="number"
                                min="0"
                                step="1"
                                class="form-control form-control-sm text-center"
                                :placeholder="$gettext('auto')"
                            />
                        </td>
                        <td class="text-center">
                            <button
                                type="button"
                                class="btn btn-sm btn-danger"
                                @click="removeEntry(index)"
                            >
                                &times;
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <button
                type="button"
                class="btn btn-secondary w-100 mt-2"
                @click="addEntry"
            >
                {{ $gettext('Add Clockwheel Entry') }}
            </button>
        </div>

        <template
            v-if="isEditMode"
            #modal-footer
        >
            <button
                type="button"
                class="btn btn-danger me-auto"
                @click="doDeleteFromModal"
            >
                {{ $gettext('Delete') }}
            </button>
            <button
                type="button"
                class="btn btn-secondary"
                @click="close"
            >
                {{ $gettext('Close') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="r$.$invalid"
                @click="doSubmit"
            >
                {{ $gettext('Save Changes') }}
            </button>
        </template>
    </modal-form>
</template>

<script setup lang="ts">
import ModalForm from '~/components/Common/ModalForm.vue';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import {BaseEditModalEmits, BaseEditModalProps, useBaseEditModal} from '~/functions/useBaseEditModal';
import {computed, onMounted, reactive, ref, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAppRegle} from '~/vendor/regle.ts';
import {required} from '@regle/rules';
import mergeExisting from '~/functions/mergeExisting.ts';
import useConfirmAndDelete from '~/functions/useConfirmAndDelete.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios.ts';

interface ClockWheelEntry {
    slot_value: string;
    algorithm: string;
    playlist_id: number | null;
    duration_seconds: number | string | null;
}

function slotToValue(slot: {type?: string | null; category_id?: number | null}): string {
    if (slot.category_id != null) {
        return 'cat:' + slot.category_id;
    }
    return 'type:' + (slot.type ?? 'music');
}

function valueToSlot(slot_value: string): {type: string | null; category_id: number | null} {
    if (slot_value.startsWith('cat:')) {
        return {type: null, category_id: parseInt(slot_value.slice(4), 10)};
    }
    return {type: slot_value.replace('type:', ''), category_id: null};
}

function normaliseDuration(value: number | string | null | undefined): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const num = Number(value);
    if (!Number.isFinite(num) || num <= 0) {
        return null;
    }
    return Math.round(num);
}

const props = defineProps<BaseEditModalProps>();
const emit = defineEmits<BaseEditModalEmits>();

const $modal = useTemplateRef('$modal');
const {notifySuccess} = useNotify();
const {$gettext} = useTranslate();

const {getStationApiUrl} = useApiRouter();
const {axios} = useAxios();

const categories = ref<{id: number; name: string}[]>([]);
const playlists = ref<{id: number; name: string}[]>([]);

onMounted(async () => {
    try {
        const [catsResp, plResp] = await Promise.all([
            axios.get(getStationApiUrl('/media-categories').value),
            axios.get(getStationApiUrl('/playlists').value),
        ]);

        const catsRaw = catsResp.data?.rows ?? catsResp.data ?? [];
        categories.value = Array.isArray(catsRaw)
            ? catsRaw.map((c: Record<string, unknown>) => ({
                id: c.id as number,
                name: c.name as string,
            }))
            : [];

        const plRaw = plResp.data?.rows ?? plResp.data ?? [];
        playlists.value = Array.isArray(plRaw)
            ? plRaw.map((p: Record<string, unknown>) => ({
                id: p.id as number,
                name: p.name as string,
            }))
            : [];
    } catch {
        categories.value = [];
        playlists.value = [];
    }
});

const blankForm = {
    name: '',
    color: '#e87722',
    is_active: true,
};

const form = ref({...blankForm});
const entries = reactive<ClockWheelEntry[]>([]);

const {r$} = useAppRegle(form, {
    name: {required},
    color: {},
    is_active: {},
});

const addEntry = () => {
    entries.push({
        slot_value: 'type:music',
        algorithm: 'random',
        playlist_id: null,
        duration_seconds: null,
    });
};

const removeEntry = (index: number) => {
    entries.splice(index, 1);
};

const moveEntry = (index: number, delta: number) => {
    const target = index + delta;
    if (target < 0 || target >= entries.length) {
        return;
    }
    const [moved] = entries.splice(index, 1);
    entries.splice(target, 0, moved);
};

const resetForm = () => {
    form.value = {...blankForm};
    entries.splice(0, entries.length);
};

const populateForm = (data: Record<string, unknown>) => {
    form.value = mergeExisting(form.value, data);
    if (Array.isArray(data.slots)) {
        const converted = (data.slots as {
            type?: string | null;
            category_id?: number | null;
            algorithm?: string;
            playlist_id?: number | null;
            duration_seconds?: number | null;
        }[]).map((s) => ({
            slot_value: slotToValue(s),
            algorithm: s.algorithm ?? 'random',
            playlist_id: s.playlist_id ?? null,
            duration_seconds: s.duration_seconds ?? null,
        }));
        entries.splice(0, entries.length, ...converted);
    }
};

const validateForm = async () => {
    const {valid} = await r$.$validate();
    const slots = entries.map((e) => ({
        ...valueToSlot(e.slot_value),
        algorithm: e.algorithm,
        playlist_id: e.playlist_id ?? null,
        duration_seconds: normaliseDuration(e.duration_seconds),
    }));
    return {valid, data: {...form.value, slots}};
};

const langTitle = computed(() =>
    isEditMode.value ? $gettext('Edit Clock Wheel') : $gettext('Add Clock Wheel')
);

const {
    loading,
    error,
    isEditMode,
    editUrl,
    clearContents,
    create,
    edit,
    close,
    doSubmit,
} = useBaseEditModal(
    computed(() => props.createUrl),
    emit,
    $modal,
    resetForm,
    populateForm,
    validateForm,
    {
        onSubmitSuccess: () => {
            notifySuccess($gettext('Clock Wheel saved.'));
        },
    }
);

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete Clock Wheel?'),
    () => {
        emit('relist');
    }
);

const doDeleteFromModal = () => {
    if (editUrl.value) {
        $modal.value?.hide();
        doDelete(editUrl.value);
    }
};

defineExpose({create, edit});
</script>
