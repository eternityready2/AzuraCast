<template>
    <form @submit.prevent="saveChanges">
        <card-page header-id="hdr_top_of_hour">
            <template #header="{id}">
                <div>
                    <h2 :id="id" class="card-title my-0">
                        {{ $gettext('Top of Hour Station ID') }}
                    </h2>
                    <div class="small mt-1">
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
                    <nav class="nav nav-tabs mb-4" role="tablist">
                        <div class="nav-item" role="presentation">
                            <button
                                type="button"
                                class="nav-link"
                                :class="{active: activeTab === 'settings'}"
                                role="tab"
                                :aria-selected="activeTab === 'settings'"
                                @click="activeTab = 'settings'"
                            >
                                {{ $gettext('Settings') }}
                            </button>
                        </div>
                        <div class="nav-item" role="presentation">
                            <button
                                type="button"
                                class="nav-link"
                                :class="{active: activeTab === 'performance'}"
                                role="tab"
                                :aria-selected="activeTab === 'performance'"
                                @click="activeTab = 'performance'"
                            >
                                {{ $gettext('Performance') }}
                            </button>
                        </div>
                    </nav>

                    <div v-show="activeTab === 'settings'">
                        <form-group id="top_of_hour_id_enabled" class="mb-4 toh-row">
                            <template #label>{{ $gettext('Enable automatic Top-of-Hour Station ID') }}</template>
                            <form-checkbox id="top_of_hour_id_enabled" v-model="form.top_of_hour_id_enabled" />
                            <template #description>
                                {{ $gettext('When disabled, the entire automatic :59 takeover is bypassed.') }}
                            </template>
                        </form-group>

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

                                    <hr class="my-3">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                        <div>
                                            <span>{{ $gettext('Station ID files') }}</span>
                                            <span class="fs-5 fw-semibold ms-2">{{ idMediaCount }}</span>
                                            <div class="small text-secondary">
                                                {{ $gettext('Only files tagged as ID are eligible.') }}
                                            </div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <router-link class="btn btn-sm btn-outline-secondary" :to="{name: 'stations:files:index'}">
                                                {{ $gettext('Manage ID files') }}
                                            </router-link>
                                            <button type="button" class="btn btn-sm btn-outline-primary" @click="showIdUpload = !showIdUpload">
                                                {{ $gettext('Upload an ID') }}
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="showIdUpload" class="mt-3">
                                        <flow-upload
                                            :target-url="idUploadUrl"
                                            :valid-mime-types="['audio/*']"
                                            @success="onIdUploaded"
                                        />
                                        <div class="small text-secondary mt-2">
                                            {{ $gettext('The file goes into the "Station IDs" folder in Media and is tagged as an ID. You can change its type later on the Media page.') }}
                                        </div>
                                    </div>
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
                                        <div class="fw-semibold">{{ $gettext('Landing the last song') }}</div>
                                        <div class="small text-secondary">
                                            {{ $gettext('The last song of the hour is normally one that ends on its own at the ID start time: AutoDJ swaps in a song of the right length when needed, and a small tempo fit trims any seconds left over.') }}
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="fw-semibold">{{ $gettext('If music is still playing') }}</div>
                                        <div class="small text-secondary">
                                            {{ $gettext('Rare fallback, used only when no song fits: the outgoing source receives a slow pre-fade, reaches silence at the deadline, and the Station ID takes air exactly on time.') }}
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

                                <form-group id="top_of_hour_id_start_second" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('ID start time') }}</template>
                                    <div class="input-group toh-input">
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
                                        {{ $gettext('When the ID starts each hour: :00:00, or :45:00 to :59:59. Current setting: %{time}.', {time: configuredStartLabel}) }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_id_fade_seconds" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Slow fade before ID') }}</template>
                                    <div class="input-group toh-input">
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
                                        {{ $gettext('Fade length if audio is still on air right before the ID.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_lookahead_minutes" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Staging lookahead') }}</template>
                                    <div class="input-group toh-input">
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
                                        {{ $gettext('How far ahead the ID is lined up in the playout engine.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_id_max_seconds" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Maximum Station ID length') }}</template>
                                    <div class="input-group toh-input">
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
                                        {{ $gettext('Only files tagged as ID and no longer than this are eligible.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_compliance_tolerance_seconds" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Compliance reporting tolerance') }}</template>
                                    <div class="input-group toh-input">
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
                                        {{ $gettext('For reporting only. It does not change when the ID plays.') }}
                                    </template>
                                </form-group>

                                <h3 class="h6 mt-4 mb-3">{{ $gettext('Tempo fit') }}</h3>

                                <form-group id="top_of_hour_fit_tempo" class="mb-0 toh-row">
                                    <template #label>{{ $gettext('Last-song tempo fit') }}</template>
                                    <div class="input-group toh-input">
                                        <input
                                            id="top_of_hour_fit_tempo"
                                            type="number"
                                            class="form-control"
                                            value="3"
                                            disabled
                                        >
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <template #description>
                                        {{ $gettext('Fixed. The last song before the ID may be tempo-fitted by up to 3% in total so it ends on the ID start.') }}
                                    </template>
                                </form-group>
                            </div>

                            <div class="col-12 col-xl-5 toh-divider">

                                <h3 class="h6 mb-3">{{ $gettext('Landing the Hour') }}</h3>

                                <form-group id="top_of_hour_swap_enabled" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Swap the last song of the hour to land on the ID') }}</template>
                                    <form-checkbox
                                        id="top_of_hour_swap_enabled"
                                        :model-value="form.top_of_hour_swap_enabled"
                                        @update:model-value="onSwapToggle"
                                    />
                                    <template #description>
                                        {{ $gettext('Replaces the last song with one that ends on the ID start. The fade becomes a rare fallback.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_swap_tolerance_seconds" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Landing tolerance') }}</template>
                                    <div class="input-group toh-input">
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
                                        {{ $gettext('How far a song may miss the ID start and still count as landed.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_swap_min_gap_seconds" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Minimum swap gap') }}</template>
                                    <div class="input-group toh-input">
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
                                        {{ $gettext('With less than this left before the ID, no song is swapped in and the fade is used.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_swap_final_approach" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Final approach') }}</template>
                                    <div class="input-group toh-input">
                                        <input
                                            id="top_of_hour_swap_final_approach"
                                            type="number"
                                            class="form-control"
                                            value="12"
                                            disabled
                                        >
                                        <span class="input-group-text">{{ $gettext('minutes') }}</span>
                                    </div>
                                    <template #description>
                                        {{ $gettext('Fixed. Songs go to the playout engine one at a time in this last stretch.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_swap_late_start" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Late start window') }}</template>
                                    <div class="input-group toh-input">
                                        <input
                                            id="top_of_hour_swap_late_start"
                                            type="number"
                                            class="form-control"
                                            value="2"
                                            disabled
                                        >
                                        <span class="input-group-text">{{ $gettext('minutes') }}</span>
                                    </div>
                                    <template #description>
                                        {{ $gettext('Fixed. A song starting this close that the ID would cut is replaced.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_swap_hold_window" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Hold window') }}</template>
                                    <div class="input-group toh-input">
                                        <input
                                            id="top_of_hour_swap_hold_window"
                                            type="number"
                                            class="form-control"
                                            value="45"
                                            disabled
                                        >
                                        <span class="input-group-text">{{ $gettext('seconds') }}</span>
                                    </div>
                                    <template #description>
                                        {{ $gettext('Fixed. A song starting this close that cannot finish opens the new hour instead.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_swap_max_cut" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Largest cut allowed') }}</template>
                                    <div class="input-group toh-input">
                                        <input
                                            id="top_of_hour_swap_max_cut"
                                            type="number"
                                            class="form-control"
                                            value="30"
                                            disabled
                                        >
                                        <span class="input-group-text">{{ $gettext('seconds') }}</span>
                                    </div>
                                    <template #description>
                                        {{ $gettext('Fixed. A song that would lose more than this to the ID is replaced or dropped.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_swap_settle" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Settle time after the hour') }}</template>
                                    <div class="input-group toh-input">
                                        <input
                                            id="top_of_hour_swap_settle"
                                            type="number"
                                            class="form-control"
                                            value="6"
                                            disabled
                                        >
                                        <span class="input-group-text">{{ $gettext('minutes') }}</span>
                                    </div>
                                    <template #description>
                                        {{ $gettext('Fixed. Nothing is swapped for this long after :00.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_swap_landed" class="mb-3 toh-row">
                                    <template #label>{{ $gettext('Already landed') }}</template>
                                    <div class="input-group toh-input">
                                        <input
                                            id="top_of_hour_swap_landed"
                                            type="number"
                                            class="form-control"
                                            value="2"
                                            disabled
                                        >
                                        <span class="input-group-text">{{ $gettext('seconds') }}</span>
                                    </div>
                                    <template #description>
                                        {{ $gettext('Fixed. Ending this close to the ID start needs no swap.') }}
                                    </template>
                                </form-group>

                                <form-group id="top_of_hour_swap_pool" class="mb-0 toh-row">
                                    <template #label>{{ $gettext('Songs to rotate between') }}</template>
                                    <div class="input-group toh-input">
                                        <input
                                            id="top_of_hour_swap_pool"
                                            type="number"
                                            class="form-control"
                                            value="5"
                                            disabled
                                        >
                                    </div>
                                    <template #description>
                                        {{ $gettext('Fixed. Number of equally good songs the swap rotates between.') }}
                                    </template>
                                </form-group>
                            </div>
                        </div>
                    </div>

                    <div v-if="activeTab === 'performance'">
                        <template v-if="compliance">
                            <h3 class="h6 mb-3">{{ $gettext('ID On Time (last 7 days)') }}</h3>
                            <div class="row g-2 mb-4">
                                <div class="col-6 col-md-3">
                                    <div class="border rounded p-2 text-center h-100">
                                        <div class="fs-4 fw-semibold">
                                            {{ compliance.compliance_percent ?? '—' }}<span v-if="compliance.compliance_percent != null" class="fs-6">%</span>
                                        </div>
                                        <div class="small text-secondary">{{ $gettext('On time') }}</div>
                                        <div class="small text-secondary">
                                            {{ $gettext('Within %{seconds}s of its set time', {seconds: String(compliance.tolerance_seconds)}) }}
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="border rounded p-2 text-center h-100">
                                        <div class="fs-4 fw-semibold">{{ compliance.on_time_count ?? 0 }}</div>
                                        <div class="small text-secondary">{{ $gettext('Hours on time') }}</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="border rounded p-2 text-center h-100">
                                        <div class="fs-4 fw-semibold text-warning">{{ compliance.late_count ?? 0 }}</div>
                                        <div class="small text-secondary">{{ $gettext('Hours off time') }}</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="border rounded p-2 text-center h-100">
                                        <div class="fs-4 fw-semibold" :class="lastOffTime ? 'text-warning' : 'text-success'">
                                            {{ lastOffTime ? lastOffTime.day : $gettext('None') }}
                                        </div>
                                        <div class="small text-secondary">{{ $gettext('Last ID off time') }}</div>
                                        <div v-if="lastOffTime" class="small text-secondary">{{ lastOffTime.detail }}</div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <landing-panel :api-url="landingUrl" />
                    </div>
                </div>
            </loading>

            <template v-if="activeTab === 'settings'" #footer_actions>
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
import FlowUpload from '~/components/Common/FlowUpload.vue';
import LandingPanel from '~/components/Stations/TopOfHour/LandingPanel.vue';
import {useDialog} from '~/components/Common/Dialogs/useDialog.ts';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import type {
    TopOfHourCompliance,
    TopOfHourForm,
    TopOfHourNextPlan,
    TopOfHourSettings,
    TopOfHourStagingStatus,
} from '~/entities/TopOfHour.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';
import {useAxios} from '~/vendor/axios.ts';
import {useTranslate} from '~/vendor/gettext.ts';
import {computed, onMounted, ref} from 'vue';

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();
const {$gettext} = useTranslate();
const {showAlert} = useDialog();
const {formatIsoAsTime, formatIsoAsDateTime} = useStationDateTimeFormatter();

const apiUrl = getStationApiUrl('/top-of-hour');
const landingUrl = getStationApiUrl('/top-of-hour/landing');

// An ID uploaded here lands in one Media folder and is tagged as an ID straight away.
const idUploadDirectory = 'Station IDs';
const filesUploadUrl = getStationApiUrl('/files/upload');
const filesBatchUrl = getStationApiUrl('/files/batch');
const showIdUpload = ref(false);
const idUploadUrl = computed(() => {
    const url = new URL(filesUploadUrl.value, document.location.href);
    url.searchParams.set('currentDirectory', idUploadDirectory);
    return url.toString();
});
const activeTab = ref<'settings' | 'performance'>('settings');
const isLoading = ref(true);
const isSaving = ref(false);
const idMediaCount = ref(0);
const compliance = ref<TopOfHourCompliance | null>(null);
const nextPlan = ref<TopOfHourNextPlan | null>(null);
const configuredStartLabel = ref(':59:00');
const staging = ref<TopOfHourStagingStatus>({is_staged: false, queue_id: null});

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

// The most recent ID in the last 7 days that aired outside the tolerance, if any.
const lastOffTime = computed(() => {
    const event = compliance.value?.late_events?.[0];
    if (!event) {
        return null;
    }

    const at = event.actual_play_at ?? event.expected_play_at;
    const drift = event.drift_seconds ?? 0;
    const values = {time: formatIsoAsTime(at), seconds: String(Math.abs(drift))};

    return {
        day: formatIsoAsDateTime(at, {month: 'short', day: 'numeric'}),
        detail: drift < 0
            ? $gettext('%{time}, %{seconds}s early', values)
            : $gettext('%{time}, %{seconds}s late', values),
    };
});

// Turning the swap off changes how nearly every hour ends, so it asks first.
const onSwapToggle = async (enabled: boolean | null) => {
    form.value.top_of_hour_swap_enabled = !!enabled;
    if (enabled) {
        return;
    }

    const {value} = await showAlert({
        title: $gettext('Turn off the swap? Songs will be faded at the ID instead of landing on it.'),
        confirmButtonText: $gettext('Turn Off'),
        confirmButtonClass: 'btn-warning',
        focusCancel: true,
    });
    if (!value) {
        form.value.top_of_hour_swap_enabled = true;
    }
};

const onIdUploaded = async (file: {name: string}) => {
    try {
        await axios.put(filesBatchUrl.value, {
            do: 'classify',
            current_directory: idUploadDirectory,
            files: [`${idUploadDirectory}/${file.name}`],
            dirs: [],
            media_type: 'id',
        });
        notifySuccess($gettext('Station ID uploaded and tagged as an ID.'));
        await loadSettings();
    } catch {
        notifyError($gettext('The file uploaded, but it could not be tagged as an ID. Set its type on the Media page.'));
    }
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

onMounted(loadSettings);
</script>

<style scoped>
/* Number boxes stay compact and their one-line explanation sits under them. */
.toh-input {
    max-width: 12rem;
}

.toh-row :deep(.form-text) {
    max-width: 30rem;
}

/* A plain line between the two settings columns once they sit side by side. */
@media (min-width: 1200px) {
    .toh-divider {
        border-left: 1px solid var(--bs-border-color);
        padding-left: 1.5rem;
    }
}
</style>
