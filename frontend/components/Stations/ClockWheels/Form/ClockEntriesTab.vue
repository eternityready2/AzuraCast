<template>
    <tab
        id="clock-entries"
        :label="$gettext('Clock Entries')"
    >
        <section class="clock-wheel-editor-section clock-wheel-entries-workspace">
            <div class="clock-wheel-editor-section__heading clock-wheel-entries-heading">
                <div>
                    <h3>{{ $gettext('Clock Entries') }}</h3>
                    <p>{{ $gettext('Define what plays and when during this hour. Drag rows to reorder them.') }}</p>
                </div>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="slotsReadOnly"
                    @click="props.addEntry()"
                >
                    + {{ $gettext('Add Entry') }}
                </button>
            </div>

            <div
                v-if="slotsReadOnly"
                class="alert alert-warning py-2 mb-3"
            >
                {{ readOnlyMessage }}
            </div>

            <div
                v-if="timelineWarnings.length > 0"
                class="alert alert-warning py-2 small mb-3"
            >
                <ul class="mb-0 ps-3">
                    <li
                        v-for="(warning, index) in timelineWarnings"
                        :key="index"
                    >
                        {{ formatPosition(entries[warning.index]?.position_seconds ?? 0) }}: {{ warning.message }}
                    </li>
                </ul>
            </div>

            <div class="table-responsive clock-wheel-entries-table-wrap">
                <table class="table table-bordered align-middle mb-0 clock-wheel-entries-table">
                    <thead>
                        <tr>
                            <th class="clock-wheel-drag-column" />
                            <th>{{ $gettext('Position') }}</th>
                            <th>{{ $gettext('Type') }}</th>
                            <th>{{ $gettext('Category') }}</th>
                            <th>{{ $gettext('Algorithm') }}</th>
                            <th>{{ $gettext('Max Sec') }}</th>
                            <th class="text-center">{{ $gettext('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody ref="$tbody">
                        <tr v-if="entries.length === 0">
                            <td
                                colspan="7"
                                class="text-center text-muted py-5"
                            >
                                <div class="clock-wheel-empty-entries">
                                    <strong>{{ $gettext('No clock entries yet') }}</strong>
                                    <span>{{ $gettext('Add the first entry to start building this hour.') }}</span>
                                    <button
                                        type="button"
                                        class="btn btn-primary mt-2"
                                        :disabled="slotsReadOnly"
                                        @click="props.addEntry()"
                                    >
                                        + {{ $gettext('Add Entry') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="(entry, index) in entries"
                            :key="rowKey(entry, index)"
                            :class="{'table-warning': rowHasWarning(index)}"
                            :data-entry-index="index"
                        >
                            <td class="text-center drag-handle text-muted clock-wheel-drag-column">
                                ⋮⋮
                            </td>
                            <td :data-label="$gettext('Position')">
                                <input
                                    :value="formatPosition(entry.position_seconds)"
                                    type="text"
                                    class="form-control form-control-sm"
                                    placeholder="0:00"
                                    :disabled="slotsReadOnly"
                                    @change="onPositionChange(entry, $event)"
                                >
                            </td>
                            <td :data-label="$gettext('Type')">
                                <select
                                    v-model="entry.type"
                                    class="form-select form-select-sm"
                                    :disabled="slotsReadOnly"
                                    required
                                    @change="props.onEntriesChanged()"
                                >
                                    <option
                                        v-for="option in mediaTypeOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                            </td>
                            <td :data-label="$gettext('Category')">
                                <select
                                    v-model="entry.category_id"
                                    class="form-select form-select-sm"
                                    :disabled="slotsReadOnly"
                                    @change="props.onEntriesChanged()"
                                >
                                    <option
                                        v-for="option in categoryOptions"
                                        :key="String(option.value)"
                                        :value="option.value"
                                    >
                                        {{ option.text }}
                                    </option>
                                </select>
                            </td>
                            <td :data-label="$gettext('Algorithm')">
                                <select
                                    v-model="entry.algorithm"
                                    class="form-select form-select-sm"
                                    :disabled="slotsReadOnly"
                                    @change="props.onEntriesChanged()"
                                >
                                    <option value="random">{{ $gettext('Random') }}</option>
                                    <option value="sequential">{{ $gettext('Sequential (oldest first)') }}</option>
                                    <option value="oldest_album">{{ $gettext('Oldest Album') }}</option>
                                    <option value="oldest_artist">{{ $gettext('Oldest Artist') }}</option>
                                    <option value="oldest_track">{{ $gettext('Oldest Track') }}</option>
                                    <option value="most_recent_album">{{ $gettext('Most Recent Album') }}</option>
                                    <option value="most_recent_artist">{{ $gettext('Most Recent Artist') }}</option>
                                </select>
                            </td>
                            <td :data-label="$gettext('Max Sec')">
                                <input
                                    v-model.number="entry.duration_seconds"
                                    type="number"
                                    min="0"
                                    max="3600"
                                    class="form-control form-control-sm"
                                    :placeholder="$gettext('Auto')"
                                    :disabled="slotsReadOnly"
                                    @change="props.onEntriesChanged()"
                                >
                                <div
                                    v-if="showSeparationOverrides"
                                    class="mt-1"
                                >
                                    <label class="form-check form-check-inline small mb-0">
                                        <input
                                            v-model="entry.separation_override_enabled"
                                            type="checkbox"
                                            class="form-check-input"
                                            :disabled="slotsReadOnly"
                                            @change="props.onEntriesChanged()"
                                        >
                                        {{ $gettext('Sep.') }}
                                    </label>
                                    <input
                                        v-if="entry.separation_override_enabled"
                                        v-model.number="entry.separation_artist_minutes"
                                        type="number"
                                        min="1"
                                        class="form-control form-control-sm mt-1"
                                        :placeholder="$gettext('Artist min')"
                                        :disabled="slotsReadOnly"
                                        @change="props.onEntriesChanged()"
                                    >
                                </div>
                            </td>
                            <td
                                class="text-center"
                                :data-label="$gettext('Actions')"
                            >
                                <div class="btn-group btn-group-sm clock-wheel-row-actions">
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary"
                                        :title="$gettext('Insert after')"
                                        :disabled="slotsReadOnly"
                                        @click="props.insertEntryAfter(index)"
                                    >
                                        <icon-ic-add />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        :title="$gettext('Duplicate')"
                                        :disabled="slotsReadOnly"
                                        @click="props.duplicateEntry(index)"
                                    >
                                        <icon-ic-copy />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger"
                                        :title="$gettext('Delete')"
                                        :disabled="slotsReadOnly"
                                        @click="props.removeEntry(index)"
                                    >
                                        <icon-ic-delete />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="clock-wheel-entry-footer-actions">
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm"
                    @click="showSeparationOverrides = !showSeparationOverrides"
                >
                    {{ showSeparationOverrides ? $gettext('Hide Per-Slot Separation') : $gettext('Show Per-Slot Separation') }}
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="slotsReadOnly"
                    @click="props.addEntry()"
                >
                    + {{ $gettext('Add Entry') }}
                </button>
            </div>
        </section>
    </tab>
</template>

<script setup lang="ts">
import {computed, onMounted, ref, useTemplateRef} from 'vue';
import {useDraggable} from 'vue-draggable-plus';
import Tab from '~/components/Common/Tab.vue';
import {useTranslate} from '~/vendor/gettext';
import {useClockWheelSlotOptions} from '~/functions/useClockWheelSlotOptions.ts';
import IconIcDelete from '~icons/ic/baseline-delete';
import IconIcAdd from '~icons/ic/baseline-add';
import IconIcCopy from '~icons/ic/baseline-content-copy';
import {
    formatClockWheelPosition,
    getClockWheelTimelineWarnings,
    parseClockWheelPosition,
} from '~/functions/clockWheelPosition.ts';
import {getMediaTypeOptions} from '~/functions/mediaTypes.ts';
import type {ClockWheelSlotEditorRow} from '~/functions/clockWheelSlotEditor.ts';

export type ClockWheelEntryRow = ClockWheelSlotEditorRow;

type ClockWheelForm = {
    template_id?: number | null;
    inherits_template_slots?: boolean;
    daypart_id?: number | null;
};

const props = defineProps<{
    form: ClockWheelForm;
    addEntry: () => void;
    removeEntry: (index: number) => void;
    duplicateEntry: (index: number) => void;
    insertEntryAfter: (index: number) => void;
    onEntriesReordered: () => void;
    onEntriesChanged: () => void;
}>();

const entries = defineModel<ClockWheelEntryRow[]>('entries', {required: true});
const {$gettext} = useTranslate();
const {categoryOptions, load: loadSlotOptions} = useClockWheelSlotOptions();
const showSeparationOverrides = ref(false);
const $tbody = useTemplateRef('$tbody');

const isDaypartManaged = computed(() =>
    props.form.daypart_id != null && props.form.daypart_id > 0
);

const slotsReadOnly = computed(() =>
    isDaypartManaged.value
    || (Boolean(props.form.inherits_template_slots) && props.form.template_id != null)
);

const readOnlyMessage = computed(() =>
    isDaypartManaged.value
        ? $gettext('These entries are managed by the Daypart that generated this wheel. Re-sync the Daypart to update them.')
        : $gettext('These entries are managed by the linked template. Disable “Inherit Template Slots” in Basic Info to edit them locally.')
);

const mediaTypeOptions = computed(() => getMediaTypeOptions($gettext));
const timelineWarnings = computed(() => getClockWheelTimelineWarnings(entries.value, $gettext));

const formatPosition = formatClockWheelPosition;
const rowKey = (entry: ClockWheelEntryRow, index: number) =>
    `${index}-${entry.position_seconds}-${entry.type}`;
const rowHasWarning = (index: number) =>
    timelineWarnings.value.some((warning) => warning.index === index);

const onPositionChange = (entry: ClockWheelEntryRow, event: Event) => {
    const target = event.target as HTMLInputElement;
    entry.position_seconds = parseClockWheelPosition(target.value);
    target.value = formatPosition(entry.position_seconds);
    props.onEntriesChanged();
};

onMounted(() => {
    void loadSlotOptions();

    if ($tbody.value === null) {
        return;
    }

    useDraggable($tbody.value, entries, {
        handle: '.drag-handle',
        animation: 150,
        onEnd() {
            props.onEntriesReordered();
        },
    });
});
</script>
