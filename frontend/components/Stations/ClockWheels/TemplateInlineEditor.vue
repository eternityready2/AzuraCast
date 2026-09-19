<template>
    <div class="clock-workspace-editor">
        <header class="clock-workspace-editor__header">
            <button
                type="button"
                class="btn btn-link p-0 text-decoration-none clock-workspace-editor__back"
                @click="emit('cancel')"
            >
                <span aria-hidden="true">←</span>
                {{ $gettext('Back to all Templates') }}
            </button>

            <h2 class="h5 mb-1">
                {{ isEditMode ? $gettext('Edit Clock Template') : $gettext('Add Clock Template') }}
            </h2>
            <p class="mb-0 text-muted small">
                {{ $gettext('Define the reusable slot layout once, then use it across Dayparts and Clock Wheels.') }}
            </p>
        </header>

        <div
            v-if="error"
            class="alert alert-danger m-3 mb-0"
        >
            {{ error }}
        </div>

        <div class="clock-workspace-editor__body">
            <div class="alert alert-info py-2 mb-3">
                {{ $gettext('Templates are reusable layouts. Changing a template updates linked wheels that inherit template slots.') }}
            </div>

            <tabs>
                <ClockWheelsFormEntries
                    variant="template"
                    :form="form"
                    :r$="r$"
                    v-model:entries="entries"
                    :add-entry="addEntry"
                    :remove-entry="removeEntry"
                    :duplicate-entry="duplicateEntry"
                    :insert-entry-after="insertEntryAfter"
                    :on-entries-reordered="onEntriesReordered"
                    :on-entries-changed="onEntriesChanged"
                />
            </tabs>
        </div>

        <footer class="clock-workspace-editor__footer">
            <button
                v-if="isEditMode"
                type="button"
                class="btn btn-outline-danger me-auto"
                :disabled="loading"
                @click="doDeleteFromEditor"
            >
                {{ $gettext('Delete Template') }}
            </button>
            <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="loading"
                @click="emit('cancel')"
            >
                {{ $gettext('Cancel') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="loading || r$.$invalid"
                @click="doSubmit"
            >
                <span
                    v-if="loading"
                    class="spinner-border spinner-border-sm me-2"
                    role="status"
                    aria-hidden="true"
                />
                {{ loading ? $gettext('Saving…') : $gettext('Save Template') }}
            </button>
        </footer>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, reactive, ref} from 'vue';
import {useAxios} from '~/vendor/axios';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAppRegle} from '~/vendor/regle.ts';
import {required} from '@regle/rules';
import mergeExisting from '~/functions/mergeExisting.ts';
import useConfirmAndDelete from '~/functions/useConfirmAndDelete.ts';
import Tabs from '~/components/Common/Tabs.vue';
import ClockWheelsFormEntries from '~/components/Stations/ClockWheels/Form/Entries.vue';
import {
    applyDragOrderToPositions,
    sortClockWheelEntries,
} from '~/functions/clockWheelPosition.ts';
import {
    defaultClockWheelSlotEditorRow,
    mapApiSlotToEditorRow,
    mapEditorRowToApiSlot,
    type ClockWheelSlotEditorRow,
} from '~/functions/clockWheelSlotEditor.ts';

interface ClockWheelEntry extends ClockWheelSlotEditorRow {}

const props = defineProps<{
    createUrl: string;
    recordUrl?: string | null;
}>();

const emit = defineEmits<{
    (e: 'saved'): void;
    (e: 'cancel'): void;
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess} = useNotify();

const loading = ref(false);
const error = ref<string | null>(null);
const isEditMode = computed(() => Boolean(props.recordUrl));

const blankForm = {
    name: '',
    color: '#e87722',
    separation_enabled: false,
    separation_artist_minutes: 45,
    separation_title_minutes: 90,
    burn_rate_max_plays_24h: null as number | null,
};

const form = ref({...blankForm});
const entries = reactive<ClockWheelEntry[]>([]);

const {r$} = useAppRegle(form, {
    name: {required},
    color: {},
    separation_enabled: {},
    separation_artist_minutes: {},
    separation_title_minutes: {},
    burn_rate_max_plays_24h: {},
});

const resetForm = () => {
    form.value = {...blankForm};
    entries.splice(0, entries.length);
};

const populateForm = (data: Record<string, unknown>) => {
    form.value = mergeExisting(form.value, {
        ...data,
        separation_enabled: Boolean(data.separation_enabled),
        separation_artist_minutes: Number(data.separation_artist_minutes ?? 45),
        separation_title_minutes: Number(data.separation_title_minutes ?? 90),
        burn_rate_max_plays_24h: data.burn_rate_max_plays_24h != null
            ? Number(data.burn_rate_max_plays_24h)
            : null,
    });

    if (Array.isArray(data.slots)) {
        const converted = (data.slots as Record<string, unknown>[]).map((slot) =>
            mapApiSlotToEditorRow(slot)
        );
        entries.splice(0, entries.length, ...converted);
        sortClockWheelEntries(entries);
    }
};

const loadEditor = async () => {
    loading.value = true;
    error.value = null;
    resetForm();

    try {
        if (props.recordUrl) {
            const {data} = await axios.get(props.recordUrl);
            populateForm(data as Record<string, unknown>);
        }
    } catch (err: any) {
        error.value = err?.response?.data?.message ?? $gettext('Could not load this clock template.');
    } finally {
        loading.value = false;
    }
};

onMounted(loadEditor);

const defaultEntry = (positionSeconds: number): ClockWheelEntry =>
    defaultClockWheelSlotEditorRow(positionSeconds);

const addEntry = () => {
    sortClockWheelEntries(entries);
    const lastPosition = entries.length > 0
        ? entries[entries.length - 1].position_seconds + 300
        : 0;
    entries.push(defaultEntry(Math.min(3599, lastPosition)));
    sortClockWheelEntries(entries);
};

const removeEntry = (index: number) => {
    entries.splice(index, 1);
};

const duplicateEntry = (index: number) => {
    const source = entries[index];
    if (!source) {
        return;
    }

    entries.push({...source, position_seconds: Math.min(3599, source.position_seconds + 60)});
    sortClockWheelEntries(entries);
};

const insertEntryAfter = (index: number) => {
    const source = entries[index];
    if (!source) {
        return;
    }

    entries.splice(index + 1, 0, defaultEntry(Math.min(3599, source.position_seconds + 300)));
    sortClockWheelEntries(entries);
};

const onEntriesReordered = () => {
    applyDragOrderToPositions(entries);
    sortClockWheelEntries(entries);
};

const onEntriesChanged = () => {
    sortClockWheelEntries(entries);
};

const doSubmit = async () => {
    const {valid} = await r$.$validate();
    if (!valid) {
        return;
    }

    loading.value = true;
    error.value = null;

    const payload = {
        ...form.value,
        slots: entries.map((entry) => mapEditorRowToApiSlot(entry)),
    };

    try {
        if (props.recordUrl) {
            await axios.put(props.recordUrl, payload);
        } else {
            await axios.post(props.createUrl, payload);
        }
        notifySuccess($gettext('Clock template saved. Linked wheels with inheritance enabled were updated.'));
        emit('saved');
    } catch (err: any) {
        error.value = err?.response?.data?.message ?? $gettext('Could not save this clock template.');
    } finally {
        loading.value = false;
    }
};

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete this clock template? Dayparts using it must be removed first.'),
    () => emit('saved')
);

const doDeleteFromEditor = () => {
    if (props.recordUrl) {
        void doDelete(props.recordUrl);
    }
};
</script>

<style scoped>
.clock-workspace-editor {
    overflow: hidden;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: var(--bs-body-bg);
    box-shadow: 0 .25rem .85rem rgba(0, 0, 0, .08);
}

.clock-workspace-editor__header,
.clock-workspace-editor__body,
.clock-workspace-editor__footer {
    padding: 1rem 1.1rem;
}

.clock-workspace-editor__header {
    border-bottom: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
}

.clock-workspace-editor__back {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    margin-bottom: .6rem;
    font-weight: 700;
}

.clock-workspace-editor__footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: .65rem;
    border-top: 1px solid var(--bs-border-color);
}

@media (max-width: 575.98px) {
    .clock-workspace-editor__header,
    .clock-workspace-editor__body,
    .clock-workspace-editor__footer {
        padding: .85rem;
    }

    .clock-workspace-editor__footer {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .clock-workspace-editor__footer .me-auto {
        grid-column: 1 / -1;
        margin-right: 0 !important;
    }

    .clock-workspace-editor__footer .btn {
        width: 100%;
    }
}
</style>
