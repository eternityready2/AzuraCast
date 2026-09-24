<template>
    <wheel-editor
        v-if="editorOpen"
        :key="editorKey"
        :create-url="listUrl"
        :record-url="editorUrl"
        @saved="onSaved"
        @cancel="editorOpen = false"
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
                @click="openEditor(null)"
            >
                <icon-ic-add />
                {{ $gettext('New Clock Wheel') }}
            </button>
        </div>

        <div class="card-body">
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
                        @click="openEditor(null)"
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
                                @click="openEditor(wheel)"
                            >
                                <wheel-dial
                                    :slots="dialSlots(wheel)"
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
                                    {{ musicSummary(wheel) }}
                                </div>
                            </div>
                            <div class="card-footer d-flex gap-2">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-primary flex-fill"
                                    @click="openEditor(wheel)"
                                >
                                    {{ $gettext('Edit') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </loading>
        </div>
    </section>
</template>

<script setup lang="ts">
import {onMounted, ref} from 'vue';
import {useAxios} from '~/vendor/axios';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import Loading from '~/components/Common/Loading.vue';
import WheelDial from '~/components/Stations/ClockWheels/WheelDial.vue';
import WheelEditor from '~/components/Stations/ClockWheels/WheelEditor.vue';
import IconIcAdd from '~icons/ic/baseline-add';
import {getClockWheelContentDensity} from '~/functions/clockWheelPosition.ts';
import {mapApiSlotToEditorRow} from '~/functions/clockWheelSlotEditor.ts';

type WheelRow = {
    id: number;
    name: string;
    color?: string;
    is_active?: boolean;
    slots?: Record<string, unknown>[];
    links: {self: string};
};

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifyError} = useNotify();
const {getStationApiUrl} = useApiRouter();

const listUrl = getStationApiUrl('/clock-wheels');

const wheels = ref<WheelRow[]>([]);
const isLoading = ref(true);
const editorOpen = ref(false);
const editorUrl = ref<string | null>(null);
const editorKey = ref(0);

const load = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<WheelRow[]>(listUrl.value);
        wheels.value = Array.isArray(data) ? data : [];
    } catch {
        notifyError($gettext('Could not load clock wheels.'));
    } finally {
        isLoading.value = false;
    }
};

onMounted(load);

const openEditor = (wheel: WheelRow | null) => {
    editorUrl.value = wheel?.links.self ?? null;
    editorKey.value += 1;
    editorOpen.value = true;
};

const onSaved = () => {
    editorOpen.value = false;
    void load();
};

const rows = (wheel: WheelRow) => (wheel.slots ?? []).map((s) => mapApiSlotToEditorRow(s));

const dialSlots = (wheel: WheelRow) =>
    rows(wheel).map((r) => ({position_seconds: r.position_seconds, type: r.type}));

const musicSummary = (wheel: WheelRow) => {
    const slots = rows(wheel);
    if (slots.length === 0) {
        return $gettext('No slots yet');
    }
    const d = getClockWheelContentDensity(slots);
    return $gettext('%{music}% music · %{talk}% talk · %{id}% IDs · %{promo}% promo/ad', {
        music: String(d.musicPercent),
        talk: String(d.talkPercent),
        id: String(d.idPercent),
        promo: String(d.promoAdPercent),
    });
};
</script>

<style scoped>
.wheel-card {
    border-top-width: 4px;
}
</style>
