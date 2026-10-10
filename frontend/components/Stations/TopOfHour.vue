<template>
    <form @submit.prevent="saveChanges">
        <card-page header-id="hdr_top_of_hour">
            <template #header="{id}">
                <div>
                    <h2 :id="id" class="card-title my-0">
                        {{ $gettext('Top of Hour Station ID') }}
                    </h2>
                    <div class="text-secondary small mt-1">
                        {{ $gettext('Exact wall-clock station identification') }}
                    </div>
                </div>
            </template>

            <info-card>
                <p class="mb-2">
                    {{ $gettext('When enabled, the Station ID owns the configured second inside minute :59. If another source is still on air, AzuraCast fades it down before the deadline and starts the ID exactly on time.') }}
                </p>
                <p class="mb-0">
                    {{ $gettext('A rigid program scheduled at :00 always starts at :00, even if that means cutting the tail of the ID. On an open hour the ID finishes naturally, then normal AutoDJ or top-hour AI News can continue.') }}
                </p>
            </info-card>

            <loading :loading="isLoading" lazy>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-xl-7">
                            <div class="border rounded h-100 p-3">
                                <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                                    <div>
                                        <div class="text-uppercase text-secondary small fw-semibold">
                                            {{ $gettext('Next ID') }}
                                        </div>
                                        <div class="fs-5 fw-semibold">
                                            {{ nextModeTitle }}
                                        </div>
                                    </div>
                                    <span class="badge" :class="nextModeBadgeClass">
                                        {{ nextModeBadge }}
                                    </span>
                                </div>

                                <template v-if="!form.top_of_hour_id_enabled">
                                    <p class="text-secondary mb-0">
                                        {{ $gettext('Top-of-Hour Station ID is disabled. No automatic :59 takeover is applied.') }}
                                    </p>
                                </template>
                                <template v-else-if="nextPlan">
                                    <div class="row g-3">
                                        <div class="col-6 col-lg-3">
                                            <div class="small text-secondary">{{ $gettext('ID Start') }}</div>
                                            <div class="fw-semibold">{{ formatClock(nextPlan.target_start_at) }}</div>
                                        </div>
                                        <div class="col-6 col-lg-3">
                                            <div class="small text-secondary">{{ $gettext('Boundary') }}</div>
                                            <div class="fw-semibold">{{ formatClock(nextPlan.boundary_at) }}</div>
                                        </div>
                                        <div class="col-6 col-lg-3">
                                            <div class="small text-secondary">{{ $gettext('ID Length') }}</div>
                                            <div class="fw-semibold">{{ formatDuration(nextPlan.duration_seconds) }}</div>
                                        </div>
                                        <div class="col-6 col-lg-3">
                                            <div class="small text-secondary">{{ $gettext('Selected ID') }}</div>
                                            <div class="fw-semibold text-truncate" :title="nextPlan.media.title ?? ''">
                                                {{ nextPlan.media.title || $gettext('Untitled ID') }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-3 d-flex flex-wrap gap-2 align-items-center">
                                        <span class="badge" :class="staging.is_staged ? 'text-bg-success' : 'text-bg-secondary'">
                                            {{ staging.is_staged ? $gettext('Staged in Upcoming Queue') : $gettext('Not staged yet') }}
                                        </span>
                                        <span v-if="staging.queue_id" class="small text-secondary">
                                            {{ $gettext('Queue #%{id}', {id: staging.queue_id}) }}
                                        </span>
                                    </div>

                                    <div v-if="nextPlan.will_be_cut_at_boundary" class="alert alert-warning mt-3 mb-0">
                                        {{ $gettext('This ID is longer than the time available before the rigid :00 program. The program will still start exactly at :00 and will cut the remaining ID audio. Move the ID start earlier to avoid that.') }}
                                    </div>
                                </template>
                                <template v-else>
                                    <p class="text-warning mb-0">
                                        {{ $gettext('No eligible Station ID is available for the next hour. Add an ID file with a valid duration within the configured maximum.') }}
                                    </p>
                                </template>
                            </div>
                        </div>

                        <div class="col-12 col-xl-5">
                            <div class="border rounded h-100 p-3">
                                <div class="text-uppercase text-secondary small fw-semibold mb-2">
                                    {{ $gettext('How it behaves') }}
                                </div>
                                <div class="mb-3">
                                    <div class="fw-semibold">{{ $gettext('ID deadline') }}</div>
                                    <div class="small text-secondary">
                                        {{ $gettext('The ID begins at your selected :59:ss time every hour. Music cannot push it late.') }}
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="fw-semibold">{{ $gettext('If music is still playing') }}</div>
                                    <div class="small text-secondary">
                                        {{ $gettext('The outgoing source receives a slow pre-fade, reaches silence at the deadline, and the Station ID takes air exactly on time.') }}
                                    </div>
                                </div>
                                <div>
                                    <div class="fw-semibold">{{ $gettext('At :00') }}</div>
                                    <div class="small text-secondary">
                                        {{ $gettext('Rigid programs win exactly at :00. If the hour is open, the ID finishes naturally and normal continuity resumes.') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-12 col-xl-7">
                            <h3 class="h6 mb-3">{{ $gettext('Station ID Control') }}</h3>

                            <form-group id="top_of_hour_id_enabled" class="mb-3">
                                <template #label>{{ $gettext('Enable automatic Top-of-Hour Station ID') }}</template>
                                <form-checkbox id="top_of_hour_id_enabled" v-model="form.top_of_hour_id_enabled" />
                                <template #description>
                                    {{ $gettext('When disabled, the entire automatic :59 takeover is bypassed.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_id_start_second" class="mb-3">
                                <template #label>{{ $gettext('ID start time') }}</template>
                                <div class="input-group">
                                    <span class="input-group-text">:</span>
                                    <input
                                        id="top_of_hour_id_start_minute"
                                        v-model.number="form.top_of_hour_id_start_minute"
                                        type="number"
                                        class="form-control"
                                        min="0"
                                        max="59"
                                        :aria-label="$gettext('Minute')"
                                    >
                                    <span class="input-group-text">:</span>
                                    <input
                                        id="top_of_hour_id_start_second"
                                        v-model.number="form.top_of_hour_id_start_second"
                                        type="number"
                                        class="form-control"
                                        min="0"
                                        max="59"
                                        :aria-label="$gettext('Second')"
                                    >
                                </div>
                                <template #description>
                                    {{ $gettext('Choose the minute and second within the hour when the ID starts. Current setting: %{time}.', {time: configuredStartLabel}) }}
                                    {{ $gettext('The default of :59:00 lands the ID in the final minute before :00; moving it earlier gives more room before the next hour.') }}
                                    <template v-if="nextPlan">
                                        {{ $gettext(' For the selected ID, :59:%{second} would be the latest whole-second start that should finish before :00 at the default minute.', {second: padSecond(nextPlan.recommended_start_second)}) }}
                                    </template>
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_id_fade_seconds" class="mb-3">
                                <template #label>{{ $gettext('Slow fade before ID') }}</template>
                                <div class="input-group">
                                    <input
                                        id="top_of_hour_id_fade_seconds"
                                        v-model.number="form.top_of_hour_id_fade_seconds"
                                        type="number"
                                        class="form-control"
                                        min="1"
                                        max="10"
                                        step="0.5"
                                    >
                                    <span class="input-group-text">{{ $gettext('seconds') }}</span>
                                </div>
                                <template #description>
                                    {{ $gettext('If audio is still on air, it is faded to silence during this period immediately before the ID deadline.') }}
                                </template>
                            </form-group>

                            <h3 class="h6 mt-4 mb-3">{{ $gettext('Landing the Hour') }}</h3>

                            <form-group id="top_of_hour_swap_enabled" class="mb-3">
                                <template #label>{{ $gettext('Swap the last song of the hour to land on the ID') }}</template>
                                <form-checkbox id="top_of_hour_swap_enabled" v-model="form.top_of_hour_swap_enabled" />
                                <template #description>
                                    {{ $gettext('The AutoDJ replaces the final music slot with a track from the same playlist whose natural length ends at the ID deadline. The fade above becomes a rare fallback used only when no match exists.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_swap_tolerance_seconds" class="mb-3">
                                <template #label>{{ $gettext('Landing tolerance') }}</template>
                                <div class="input-group">
                                    <input
                                        id="top_of_hour_swap_tolerance_seconds"
                                        v-model.number="form.top_of_hour_swap_tolerance_seconds"
                                        type="number"
                                        class="form-control"
                                        min="1"
                                        max="30"
                                        :disabled="!form.top_of_hour_swap_enabled"
                                    >
                                    <span class="input-group-text">{{ $gettext('seconds') }}</span>
                                </div>
                                <template #description>
                                    {{ $gettext('How far a track may miss the deadline and still count as a clean landing. Tighter is more accurate but finds fewer matches; widen it on a small library.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_swap_min_gap_seconds" class="mb-3">
                                <template #label>{{ $gettext('Minimum swap gap') }}</template>
                                <div class="input-group">
                                    <input
                                        id="top_of_hour_swap_min_gap_seconds"
                                        v-model.number="form.top_of_hour_swap_min_gap_seconds"
                                        type="number"
                                        class="form-control"
                                        min="15"
                                        max="600"
                                        :disabled="!form.top_of_hour_swap_enabled"
                                    >
                                    <span class="input-group-text">{{ $gettext('seconds') }}</span>
                                </div>
                                <template #description>
                                    {{ $gettext('If less than this much of the hour remains, no song is substituted and the fade fallback is used instead. This prevents very short stub tracks being scheduled just before the ID.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_lookahead_minutes" class="mb-3">
                                <template #label>{{ $gettext('Staging lookahead') }}</template>
                                <div class="input-group">
                                    <input
                                        id="top_of_hour_lookahead_minutes"
                                        v-model.number="form.top_of_hour_lookahead_minutes"
                                        type="number"
                                        class="form-control"
                                        min="1"
                                        max="60"
                                    >
                                    <span class="input-group-text">{{ $gettext('minutes') }}</span>
                                </div>
                                <template #description>
                                    {{ $gettext('The selected ID is resolved and staged this far ahead so Liquidsoap already has it before the exact wall-clock deadline.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_id_max_seconds" class="mb-3">
                                <template #label>{{ $gettext('Maximum Station ID length') }}</template>
                                <div class="input-group">
                                    <input
                                        id="top_of_hour_id_max_seconds"
                                        v-model.number="form.top_of_hour_id_max_seconds"
                                        type="number"
                                        class="form-control"
                                        min="15"
                                        max="60"
                                    >
                                    <span class="input-group-text">{{ $gettext('seconds') }}</span>
                                </div>
                                <template #description>
                                    {{ $gettext('Only files tagged as ID and within this maximum are eligible. Promos and commercials are never substituted.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_compliance_tolerance_seconds" class="mb-0">
                                <template #label>{{ $gettext('Compliance reporting tolerance') }}</template>
                                <div class="input-group">
                                    <input
                                        id="top_of_hour_compliance_tolerance_seconds"
                                        v-model.number="form.top_of_hour_compliance_tolerance_seconds"
                                        type="number"
                                        class="form-control"
                                        min="1"
                                        max="60"
                                    >
                                    <span class="input-group-text">{{ $gettext('seconds') }}</span>
                                </div>
                                <template #description>
                                    {{ $gettext('Reporting tolerance only. It does not change the wall-clock deadline or let an ID delay a rigid :00 program.') }}
                                </template>
                            </form-group>
                        </div>

                        <div class="col-12 col-xl-5">
                            <h3 class="h6 mb-3">{{ $gettext('Readiness') }}</h3>
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>{{ $gettext('Station ID files') }}</span>
                                    <span class="fs-5 fw-semibold">{{ idMediaCount }}</span>
                                </div>
                                <div class="small text-secondary mt-1">
                                    {{ $gettext('Only files tagged as ID are eligible.') }}
                                </div>
                            </div>

                            <template v-if="compliance">
                                <h3 class="h6 mt-4 mb-3">{{ $gettext('7-Day Compliance') }}</h3>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold">
                                                {{ compliance.compliance_percent ?? '—' }}<span v-if="compliance.compliance_percent != null" class="fs-6">%</span>
                                            </div>
                                            <div class="small text-secondary">{{ $gettext('On time') }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold">{{ compliance.on_time_count ?? 0 }}</div>
                                            <div class="small text-secondary">{{ $gettext('Compliant hours') }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold text-warning">{{ compliance.late_count ?? 0 }}</div>
                                            <div class="small text-secondary">{{ $gettext('Late / missed') }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold text-secondary">{{ compliance.fallback_count ?? 0 }}</div>
                                            <div class="small text-secondary">{{ $gettext('Fallback events') }}</div>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <template v-if="landing">
                                <div class="d-flex align-items-center justify-content-between gap-2 mt-4 mb-3">
                                    <h3 class="h6 mb-0">{{ $gettext('Landing Before the ID') }}</h3>
                                    <select
                                        v-model.number="landingDays"
                                        class="form-select form-select-sm w-auto"
                                        :aria-label="$gettext('Period')"
                                        :disabled="isLandingLoading"
                                        @change="loadLanding"
                                    >
                                        <option
                                            v-for="option in landingDayOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.text }}
                                        </option>
                                    </select>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold text-success">
                                                {{ landing.clean_percent ?? '—' }}<span v-if="landing.clean_percent != null" class="fs-6">%</span>
                                            </div>
                                            <div class="small text-secondary">{{ $gettext('Ended cleanly') }}</div>
                                            <div class="small text-secondary">{{ landingCountLabel(landing.clean_count) }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold text-warning">
                                                {{ landing.cut_percent ?? '—' }}<span v-if="landing.cut_percent != null" class="fs-6">%</span>
                                            </div>
                                            <div class="small text-secondary">{{ $gettext('Cut or faded') }}</div>
                                            <div class="small text-secondary">{{ landingCountLabel(landing.cut_count) }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold text-warning">
                                                {{ landing.resumed_percent ?? '—' }}<span v-if="landing.resumed_percent != null" class="fs-6">%</span>
                                            </div>
                                            <div class="small text-secondary">{{ $gettext('Resumed after ID') }}</div>
                                            <div class="small text-secondary">{{ landingCountLabel(landing.resumed_count) }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold text-secondary">
                                                {{ landing.early_percent ?? '—' }}<span v-if="landing.early_percent != null" class="fs-6">%</span>
                                            </div>
                                            <div class="small text-secondary">{{ $gettext('Ended early (gap)') }}</div>
                                            <div class="small text-secondary">{{ landingCountLabel(landing.early_count) }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="small text-secondary mt-2">
                                    {{ $gettext('Counts the hours where a song or promo led into the ID. Show and feed hours (%{shows} in this period) are left out.', {shows: String(landing.show_hours)}) }}
                                </div>

                                <h4 class="h6 mt-3 mb-2">{{ $gettext('Failures') }}</h4>
                                <div v-if="landing.failures.length === 0" class="small text-secondary">
                                    {{ $gettext('None in this period.') }}
                                </div>
                                <ul v-else class="list-unstyled small mb-0 landing-failures">
                                    <li
                                        v-for="failure in landing.failures"
                                        :key="failure.id_at"
                                        class="border-top py-1"
                                    >
                                        <div class="fw-semibold">
                                            {{ formatIsoAsDateTime(failure.id_at) }}
                                        </div>
                                        <div>{{ landingFailureLabel(failure) }}</div>
                                        <div class="text-secondary text-truncate">{{ failure.title }}</div>
                                    </li>
                                </ul>
                                <div class="small text-secondary mt-2">
                                    {{ $gettext('Worked out from the play history each time the page loads. No extra log is stored, and the list never goes back more than 30 days.') }}
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </loading>

            <template #footer_actions>
                <button type="submit" class="btn btn-primary" :disabled="isLoading || isSaving">
                    {{ $gettext('Save Changes') }}
                </button>
            </template>
        </card-page>
    </form>
</template>

<script setup lang="ts">
import CardPage from '~/components/Common/CardPage.vue';
import InfoCard from '~/components/Common/InfoCard.vue';
import Loading from '~/components/Common/Loading.vue';
import FormGroup from '~/components/Form/FormGroup.vue';
import FormCheckbox from '~/components/Form/FormCheckbox.vue';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import type {
    TopOfHourCompliance,
    TopOfHourForm,
    TopOfHourLanding,
    TopOfHourLandingFailure,
    TopOfHourNextPlan,
    TopOfHourSettings,
    TopOfHourStagingStatus,
} from '~/entities/TopOfHour.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';
import {useAxios} from '~/vendor/axios.ts';
import {useTranslate} from '~/vendor/gettext';
import {computed, onMounted, ref} from 'vue';

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();
const {formatIsoAsTime, formatIsoAsDateTime} = useStationDateTimeFormatter();
const {$gettext} = useTranslate();

const apiUrl = getStationApiUrl('/top-of-hour');
const isLoading = ref(true);
const isSaving = ref(false);
const idMediaCount = ref(0);
const compliance = ref<TopOfHourCompliance | null>(null);
const nextPlan = ref<TopOfHourNextPlan | null>(null);
const configuredStartLabel = ref(':59:00');
const staging = ref<TopOfHourStagingStatus>({is_staged: false, queue_id: null});

const landingUrl = getStationApiUrl('/top-of-hour/landing');
const landing = ref<TopOfHourLanding | null>(null);
const landingDays = ref(7);
const isLandingLoading = ref(false);
const landingDayOptions = [
    {value: 1, text: $gettext('Last 24 hours')},
    {value: 3, text: $gettext('Last 3 days')},
    {value: 7, text: $gettext('Last 7 days')},
    {value: 14, text: $gettext('Last 14 days')},
    {value: 30, text: $gettext('Last 30 days')},
];

const form = ref<TopOfHourForm>({
    top_of_hour_id_enabled: false,
    top_of_hour_lookahead_minutes: 10,
    top_of_hour_compliance_tolerance_seconds: 10,
    top_of_hour_id_max_seconds: 60,
    top_of_hour_id_start_second: 0,
    top_of_hour_id_start_minute: 59,
    top_of_hour_id_fade_seconds: 5,
    top_of_hour_swap_enabled: true,
    top_of_hour_swap_tolerance_seconds: 5,
    top_of_hour_swap_min_gap_seconds: 45,
});

const nextModeBadge = computed(() => {
    if (!form.value.top_of_hour_id_enabled) return 'OFF';
    if (!nextPlan.value) return 'NO ID';
    return nextPlan.value.mode === 'hard_toh' ? 'HARD :00' : 'OPEN HOUR';
});

const nextModeBadgeClass = computed(() => {
    if (!form.value.top_of_hour_id_enabled) return 'text-bg-secondary';
    if (!nextPlan.value) return 'text-bg-warning';
    return nextPlan.value.mode === 'hard_toh' ? 'text-bg-danger' : 'text-bg-primary';
});

const nextModeTitle = computed(() => {
    if (!form.value.top_of_hour_id_enabled) return 'Automatic ID Disabled';
    if (!nextPlan.value) return 'Station ID Required';
    return nextPlan.value.mode === 'hard_toh'
        ? 'ID before rigid :00 program'
        : 'ID before open new hour';
});

const formatClock = (value: string): string => formatIsoAsTime(value);
const formatDuration = (seconds: number): string => `${seconds.toFixed(1)}s`;
const padSecond = (second: number): string => String(second).padStart(2, '0');

const loadSettings = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<TopOfHourSettings>(apiUrl.value);
        form.value = {
            top_of_hour_id_enabled: data.top_of_hour_id_enabled ?? false,
            top_of_hour_lookahead_minutes: data.top_of_hour_lookahead_minutes ?? 10,
            top_of_hour_compliance_tolerance_seconds: data.top_of_hour_compliance_tolerance_seconds ?? 10,
            top_of_hour_id_max_seconds: data.top_of_hour_id_max_seconds ?? 60,
            top_of_hour_id_start_second: data.top_of_hour_id_start_second ?? 0,
            top_of_hour_id_start_minute: data.top_of_hour_id_start_minute ?? 59,
            top_of_hour_id_fade_seconds: data.top_of_hour_id_fade_seconds ?? 5,
            top_of_hour_swap_enabled: data.top_of_hour_swap_enabled ?? true,
            top_of_hour_swap_tolerance_seconds: data.top_of_hour_swap_tolerance_seconds ?? 5,
            top_of_hour_swap_min_gap_seconds: data.top_of_hour_swap_min_gap_seconds ?? 45,
        };
        configuredStartLabel.value = data.configured_start_label ?? ':59:00';
        idMediaCount.value = data.id_media_count ?? 0;
        compliance.value = data.compliance ?? null;
        nextPlan.value = data.next ?? null;
        staging.value = data.staging ?? {is_staged: false, queue_id: null};
    } catch {
        notifyError();
    } finally {
        isLoading.value = false;
    }
};

const loadLanding = async () => {
    isLandingLoading.value = true;
    try {
        const {data} = await axios.get<TopOfHourLanding>(landingUrl.value, {params: {days: landingDays.value}});
        landing.value = data;
    } catch {
        notifyError();
    } finally {
        isLandingLoading.value = false;
    }
};

const landingCountLabel = (count: number): string => $gettext(
    '%{count} of %{total} hours',
    {count: String(count), total: String(landing.value?.music_hours ?? 0)}
);

const landingFailureLabel = (failure: TopOfHourLandingFailure): string => {
    const seconds = String(Math.round(failure.seconds));
    const parts: string[] = [];

    switch (failure.kind) {
        case 'cut_short':
            parts.push($gettext('Cut short: %{seconds}s trimmed off the ending', {seconds}));
            break;
        case 'cut_late_start':
            parts.push($gettext('Cut by the ID: started too close to it, %{seconds}s lost', {seconds}));
            break;
        case 'cut_too_long':
            parts.push($gettext('Cut by the ID: %{seconds}s lost', {seconds}));
            break;
        case 'ended_early':
            parts.push($gettext('Ended %{seconds}s early, leaving a gap before the ID', {seconds}));
            break;
    }

    if (failure.resumed) {
        parts.push($gettext('Played again after the ID'));
    }

    return parts.join('. ');
};

const saveChanges = async () => {
    isSaving.value = true;
    try {
        await axios.put(apiUrl.value, form.value);
        notifySuccess();
        await loadSettings();
    } catch {
        notifyError();
    } finally {
        isSaving.value = false;
    }
};

onMounted(() => {
    void loadSettings();
    void loadLanding();
});
</script>

<style scoped>
.landing-failures {
    max-height: 20rem;
    overflow-y: auto;
}
</style>
