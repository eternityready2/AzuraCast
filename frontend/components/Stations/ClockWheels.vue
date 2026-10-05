<template>
    <wheel-editor
        v-if="editor?.kind === 'wheel' || editor?.kind === 'template'"
        :key="editorKey"
        :mode="editor.kind"
        :create-url="editor.kind === 'template' ? templatesUrl : listUrl"
        :record-url="editor.url"
        @saved="onSaved"
        @cancel="editor = null"
    />

    <daypart-inline-editor
        v-else-if="editor?.kind === 'daypart'"
        :key="editorKey"
        :create-url="daypartsUrl"
        :templates-url="templatesUrl"
        :record-url="editor.url"
        :preset-template-id="editor.presetTemplateId ?? null"
        @saved="onSaved"
        @changed="load"
        @cancel="editor = null"
    />

    <section
        v-else
        class="card"
        role="region"
        aria-labelledby="hdr_clock_wheels"
    >
        <div class="card-header text-bg-primary d-flex align-items-center gap-2 flex-wrap">
            <h2
                id="hdr_clock_wheels"
                class="card-title my-0 flex-fill"
            >
                {{ $gettext('Clock Wheels') }}
            </h2>
            <button
                type="button"
                class="btn btn-sm btn-light"
                @click="openNew"
            >
                <icon-ic-add />
                {{ newLabel }}
            </button>
        </div>

        <div class="card-body">
            <tabs
                v-model="activeTab"
                nav-tabs-class="nav-tabs mb-3"
            >
                <tab
                    id="wheels"
                    :label="$gettext('Wheels')"
                >
                    <p class="text-muted">
                        {{ $gettext('A clock wheel is the recipe for one hour: which kind of content plays at each point in the hour. Build a wheel here, then place it on the') }}
                        <router-link :to="{name: 'stations:schedule:index'}">
                            {{ $gettext('Schedule') }}
                        </router-link>
                        {{ $gettext('to choose when it airs.') }}
                    </p>

                    <loading :loading="isLoading">
                        <div
                            v-if="wheels.length === 0"
                            class="text-center py-5"
                        >
                            <p class="text-muted mb-3">
                                {{ $gettext('No clock wheels yet.') }}
                            </p>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="openEditor('wheel', null)"
                            >
                                {{ $gettext('Create your first Clock Wheel') }}
                            </button>
                        </div>

                        <div
                            v-else
                            class="row g-3"
                        >
                            <div
                                v-for="wheel in wheels"
                                :key="wheel.id"
                                class="col-sm-6 col-lg-4 col-xl-3"
                            >
                                <div
                                    class="card h-100 wheel-card"
                                    :style="{borderTopColor: wheel.color || 'var(--bs-primary)'}"
                                >
                                    <button
                                        type="button"
                                        class="btn p-3 d-flex justify-content-center"
                                        :aria-label="$gettext('Edit %{name}', {name: wheel.name})"
                                        @click="openEditor('wheel', wheel.links.self)"
                                    >
                                        <wheel-dial
                                            :slots="dialSlots(wheel.slots)"
                                            :size="150"
                                            :show-ticks="false"
                                            :center-label="String(wheel.slots?.length ?? 0)"
                                            :center-sub="$gettext('slots')"
                                        />
                                    </button>
                                    <div class="card-body pt-0">
                                        <div class="d-flex align-items-start justify-content-between gap-2">
                                            <h3 class="h6 mb-1 text-break">
                                                {{ wheel.name }}
                                            </h3>
                                            <span
                                                class="badge"
                                                :class="wheel.is_active ? 'text-bg-success' : 'text-bg-secondary'"
                                            >
                                                {{ wheel.is_active ? $gettext('Active') : $gettext('Inactive') }}
                                            </span>
                                        </div>
                                        <div class="small text-muted">
                                            {{ musicSummary(wheel.slots) }}
                                        </div>
                                        <div
                                            v-if="wheel.daypart_id"
                                            class="small mt-1"
                                        >
                                            <span class="badge text-bg-light border">
                                                {{ $gettext('Daypart: %{name}', {name: daypartName(wheel.daypart_id)}) }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-footer d-flex gap-2">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-primary flex-fill"
                                            @click="openEditor('wheel', wheel.links.self)"
                                        >
                                            {{ $gettext('Edit') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </loading>
                </tab>

                <tab
                    id="templates"
                    :label="$gettext('Hour Templates')"
                >
                    <p class="text-muted">
                        {{ $gettext('An hour template is a master clock wheel. It never airs by itself: a daypart puts it on air for a range of hours, and editing the template updates every wheel made from it.') }}
                    </p>

                    <loading :loading="isLoading">
                        <div
                            v-if="templates.length === 0"
                            class="text-center py-5"
                        >
                            <p class="text-muted mb-3">
                                {{ $gettext('No hour templates yet.') }}
                            </p>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="openEditor('template', null)"
                            >
                                {{ $gettext('Create your first Hour Template') }}
                            </button>
                        </div>

                        <div
                            v-else
                            class="row g-3"
                        >
                            <div
                                v-for="template in templates"
                                :key="template.id"
                                class="col-sm-6 col-lg-4 col-xl-3"
                            >
                                <div
                                    class="card h-100 wheel-card"
                                    :style="{borderTopColor: template.color || 'var(--bs-primary)'}"
                                >
                                    <button
                                        type="button"
                                        class="btn p-3 d-flex justify-content-center"
                                        :aria-label="$gettext('Edit %{name}', {name: template.name})"
                                        @click="openEditor('template', template.links.self)"
                                    >
                                        <wheel-dial
                                            :slots="dialSlots(template.slots)"
                                            :size="150"
                                            :show-ticks="false"
                                            :center-label="String(template.slots?.length ?? 0)"
                                            :center-sub="$gettext('slots')"
                                        />
                                    </button>
                                    <div class="card-body pt-0">
                                        <h3 class="h6 mb-1 text-break">
                                            {{ template.name }}
                                        </h3>
                                        <div class="small text-muted">
                                            {{ musicSummary(template.slots) }}
                                        </div>
                                        <div class="small text-muted mt-1">
                                            {{ templateUsage(template.id) }}
                                        </div>
                                    </div>
                                    <div class="card-footer d-flex gap-2">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-primary flex-fill"
                                            @click="openEditor('template', template.links.self)"
                                        >
                                            {{ $gettext('Edit') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            :title="$gettext('Put this template on air with a new daypart')"
                                            @click="openEditor('daypart', null, template.id)"
                                        >
                                            {{ $gettext('Use') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </loading>
                </tab>

                <tab
                    id="dayparts"
                    :label="$gettext('Dayparts')"
                >
                    <p class="text-muted">
                        {{ $gettext('A daypart puts an hour template on air for a block of hours on the days you choose, such as Morning Drive 6 to 10 AM on weekdays. It creates and schedules one clock wheel per hour; scheduled shows still keep their own hours.') }}
                    </p>

                    <loading :loading="isLoading">
                        <div
                            v-if="dayparts.length === 0"
                            class="text-center py-5"
                        >
                            <p class="text-muted mb-3">
                                {{ templates.length ? $gettext('No dayparts yet.') : $gettext('Create an hour template first, then put it on air with a daypart.') }}
                            </p>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="templates.length ? openEditor('daypart', null) : openEditor('template', null)"
                            >
                                {{ templates.length ? $gettext('Create your first Daypart') : $gettext('Create an Hour Template') }}
                            </button>
                        </div>

                        <template v-else>
                            <div class="card bg-body-tertiary mb-3">
                                <div class="card-body">
                                    <h3 class="h6 mb-3">
                                        {{ $gettext('Your Day') }}
                                    </h3>
                                    <div
                                        v-for="daypart in dayparts"
                                        :key="daypart.id"
                                        class="day-row mb-2"
                                    >
                                        <div class="day-row__label small text-truncate">
                                            {{ daypart.name }}
                                        </div>
                                        <div class="hour-strip">
                                            <span
                                                v-for="hour in 24"
                                                :key="hour"
                                                class="hour-strip__cell"
                                                :style="coversHour(daypart, hour - 1) ? {background: daypartColor(daypart), opacity: daypart.is_active ? 1 : .4} : {}"
                                                :title="formatHourOfDayToAmPm(hour - 1)"
                                            />
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <div class="day-row__label" />
                                        <div class="d-flex justify-content-between small text-muted">
                                            <span>12 AM</span>
                                            <span>6 AM</span>
                                            <span>12 PM</span>
                                            <span>6 PM</span>
                                            <span>12 AM</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div
                                    v-for="daypart in dayparts"
                                    :key="daypart.id"
                                    class="col-sm-6 col-lg-4"
                                >
                                    <div
                                        class="card h-100 wheel-card"
                                        :style="{borderTopColor: daypartColor(daypart)}"
                                    >
                                        <div class="card-body">
                                            <div class="d-flex align-items-start justify-content-between gap-2">
                                                <h3 class="h6 mb-1 text-break">
                                                    {{ daypart.name }}
                                                </h3>
                                                <span
                                                    class="badge"
                                                    :class="daypart.is_active ? 'text-bg-success' : 'text-bg-secondary'"
                                                >
                                                    {{ daypart.is_active ? $gettext('Active') : $gettext('Inactive') }}
                                                </span>
                                            </div>
                                            <div class="fw-semibold">
                                                {{ daypartHours(daypart) }}
                                            </div>
                                            <div class="small text-muted mb-2">
                                                {{ daypartDays(daypart) }}
                                            </div>
                                            <div class="small">
                                                {{ $gettext('Template:') }}
                                                <strong>{{ templateName(daypart.template_id) }}</strong>
                                            </div>
                                        </div>
                                        <div class="card-footer d-flex gap-2">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary flex-fill"
                                                @click="openEditor('daypart', daypart.links.self)"
                                            >
                                                {{ $gettext('Edit') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </loading>
                </tab>
            </tabs>
        </div>
    </section>
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from 'vue';
import {useAxios} from '~/vendor/axios';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import Loading from '~/components/Common/Loading.vue';
import Tabs from '~/components/Common/Tabs.vue';
import Tab from '~/components/Common/Tab.vue';
import WheelDial from '~/components/Stations/ClockWheels/WheelDial.vue';
import WheelEditor from '~/components/Stations/ClockWheels/WheelEditor.vue';
import DaypartInlineEditor from '~/components/Stations/ClockWheels/DaypartInlineEditor.vue';
import IconIcAdd from '~icons/ic/baseline-add';
import {getClockWheelContentDensity} from '~/functions/clockWheelPosition.ts';
import {mapApiSlotToEditorRow} from '~/functions/clockWheelSlotEditor.ts';
import {formatHourOfDayToAmPm} from '~/functions/amPmTime.ts';

type Slots = Record<string, unknown>[] | undefined;

type WheelRow = {
    id: number;
    name: string;
    color?: string;
    is_active?: boolean;
    daypart_id?: number | null;
    template_id?: number | null;
    slots?: Record<string, unknown>[];
    links: {self: string};
};

type TemplateRow = {
    id: number;
    name: string;
    color?: string | null;
    slots?: Record<string, unknown>[];
    links: {self: string};
};

type DaypartRow = {
    id: number;
    name: string;
    template_id: number;
    start_hour: number;
    end_hour: number;
    days?: number[];
    color?: string | null;
    is_active: boolean;
    links: {self: string};
};

type EditorState = {
    kind: 'wheel' | 'template' | 'daypart';
    url: string | null;
    presetTemplateId?: number | null;
};

const {$gettext, $ngettext} = useTranslate();
const {axios} = useAxios();
const {notifyError} = useNotify();
const {getStationApiUrl} = useApiRouter();

const listUrl = getStationApiUrl('/clock-wheels');
const templatesUrl = getStationApiUrl('/clock-wheel-templates');
const daypartsUrl = getStationApiUrl('/clock-dayparts');

const activeTab = ref('wheels');
const wheels = ref<WheelRow[]>([]);
const templates = ref<TemplateRow[]>([]);
const dayparts = ref<DaypartRow[]>([]);
const isLoading = ref(true);
const editor = ref<EditorState | null>(null);
const editorKey = ref(0);

const asList = <T>(data: unknown): T[] => (Array.isArray(data) ? data as T[] : []);

const load = async () => {
    isLoading.value = true;
    try {
        const [w, t, d] = await Promise.all([
            axios.get(listUrl.value),
            axios.get(templatesUrl.value),
            axios.get(daypartsUrl.value),
        ]);
        wheels.value = asList<WheelRow>(w.data);
        templates.value = asList<TemplateRow>(t.data);
        dayparts.value = asList<DaypartRow>(d.data);
    } catch {
        notifyError($gettext('Could not load clock wheels.'));
    } finally {
        isLoading.value = false;
    }
};

onMounted(load);

const openEditor = (kind: EditorState['kind'], url: string | null, presetTemplateId: number | null = null) => {
    editor.value = {kind, url, presetTemplateId};
    editorKey.value += 1;
};

const newLabel = computed(() => {
    switch (activeTab.value) {
        case 'templates':
            return $gettext('New Hour Template');
        case 'dayparts':
            return $gettext('New Daypart');
        default:
            return $gettext('New Clock Wheel');
    }
});

const openNew = () => {
    if (activeTab.value === 'templates') {
        openEditor('template', null);
    } else if (activeTab.value === 'dayparts') {
        openEditor(templates.value.length ? 'daypart' : 'template', null);
    } else {
        openEditor('wheel', null);
    }
};

const onSaved = () => {
    editor.value = null;
    void load();
};

const rows = (slots: Slots) => (slots ?? []).map((s) => mapApiSlotToEditorRow(s));

const dialSlots = (slots: Slots) =>
    rows(slots).map((r) => ({position_seconds: r.position_seconds, type: r.type}));

const musicSummary = (slots: Slots) => {
    const list = rows(slots);
    if (list.length === 0) {
        return $gettext('No slots yet');
    }
    const d = getClockWheelContentDensity(list);
    return $gettext('%{music}% music · %{talk}% talk · %{id}% IDs · %{promo}% promo/ad', {
        music: String(d.musicPercent),
        talk: String(d.talkPercent),
        id: String(d.idPercent),
        promo: String(d.promoAdPercent),
    });
};

const templateName = (id: number | null | undefined) =>
    templates.value.find((t) => t.id === id)?.name ?? $gettext('(missing template)');

const daypartName = (id: number | null | undefined) =>
    dayparts.value.find((d) => d.id === id)?.name ?? '';

const templateUsage = (id: number) => {
    const used = dayparts.value.filter((d) => d.template_id === id).length;
    return used === 0
        ? $gettext('Not on air yet')
        : $ngettext('Used by %{n} daypart', 'Used by %{n} dayparts', used, {n: String(used)});
};

const daypartColor = (daypart: DaypartRow) =>
    daypart.color || templates.value.find((t) => t.id === daypart.template_id)?.color || 'var(--bs-primary)';

const coversHour = (daypart: DaypartRow, hour: number) => {
    const {start_hour: start, end_hour: end} = daypart;
    return start <= end
        ? hour >= start && hour <= end
        : hour >= start || hour <= end;
};

const daypartHours = (daypart: DaypartRow) => {
    const count = ((daypart.end_hour - daypart.start_hour + 24) % 24) + 1;
    return $gettext('%{start} – %{end} · %{n} hours', {
        start: formatHourOfDayToAmPm(daypart.start_hour),
        end: formatHourOfDayToAmPm((daypart.end_hour + 1) % 24),
        n: String(count),
    });
};

const daypartDays = (daypart: DaypartRow) => {
    const days = daypart.days ?? [];
    const names = [$gettext('Mon'), $gettext('Tue'), $gettext('Wed'), $gettext('Thu'), $gettext('Fri'), $gettext('Sat'), $gettext('Sun')];
    if (days.length === 0 || days.length === 7) {
        return $gettext('Every day');
    }
    if (days.length === 5 && [1, 2, 3, 4, 5].every((d) => days.includes(d))) {
        return $gettext('Weekdays');
    }
    if (days.length === 2 && days.includes(6) && days.includes(7)) {
        return $gettext('Weekends');
    }
    return days.map((d) => names[d - 1]).join(', ');
};
</script>

<style scoped>
.wheel-card {
    border-top-width: 4px;
}

.day-row {
    display: grid;
    grid-template-columns: minmax(6rem, 10rem) 1fr;
    gap: .75rem;
    align-items: center;
}

.hour-strip {
    display: grid;
    grid-template-columns: repeat(24, 1fr);
    gap: 2px;
}

.hour-strip__cell {
    height: 1.1rem;
    border-radius: .2rem;
    background: var(--bs-secondary-bg);
}
</style>
