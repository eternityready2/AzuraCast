<template>
    <!--
        FILE LOCATION: frontend/components/Stations/Reports/Overview/DmcaComplianceTab.vue

        ADD TO: frontend/components/Stations/Reports/Overview.vue
        1. Import this component at the top of the <script setup> block:
           import DmcaComplianceTab from './Overview/DmcaComplianceTab.vue';

        2. Add a prop for the API URL in Overview.vue:
           const dmcaUrl = computed(() => generateApiUrl('reports/overview/dmca-compliance'));

        3. Add the tab in the <tabs> section:
           <tab :label="$gettext('DMCA Compliance')">
               <dmca-compliance-tab :api-url="dmcaUrl" />
           </tab>
    -->
    <loading :loading="isLoading" lazy>
        <div v-if="state">

            <!-- Status Banner -->
            <div
                class="alert mb-3"
                :class="state.enabled ? 'alert-success' : 'alert-warning'"
            >
                <strong>{{ state.enabled ? $gettext('DMCA Compliance: Active') : $gettext('DMCA Compliance: Disabled') }}</strong>
                <span class="ms-2 text-muted small">
                    {{ $gettext('Monitoring last') }} {{ state.window_minutes }} {{ $gettext('minutes') }}
                    &nbsp;·&nbsp;
                    {{ state.total_plays_in_window }} {{ $gettext('plays in current window') }}
                    <span v-if="!state.enabled">
                        &nbsp;·&nbsp; {{ $gettext('Enable in Station Settings → Broadcasting') }}
                    </span>
                </span>
            </div>

            <!-- Limits Summary -->
            <fieldset class="mb-4">
                <legend>{{ $gettext('Current Limits (DMCA § 114)') }}</legend>
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>{{ $gettext('Rule') }}</th>
                            <th class="text-center">{{ $gettext('Limit') }}</th>
                            <th>{{ $gettext('Description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>{{ $gettext('Rule 1') }}</td>
                            <td class="text-center"><strong>{{ state.limits.max_song_plays }}</strong></td>
                            <td>{{ $gettext('Max plays of the same song per 3-hour window') }}</td>
                        </tr>
                        <tr>
                            <td>{{ $gettext('Rule 2') }}</td>
                            <td class="text-center"><strong>{{ state.limits.max_consecutive_song }}</strong></td>
                            <td>{{ $gettext('Max consecutive plays of the same song') }}</td>
                        </tr>
                        <tr>
                            <td>{{ $gettext('Rule 3') }}</td>
                            <td class="text-center"><strong>{{ state.limits.max_album_plays }}</strong></td>
                            <td>{{ $gettext('Max songs from the same album per 3-hour window') }}</td>
                        </tr>
                        <tr>
                            <td>{{ $gettext('Rule 4a') }}</td>
                            <td class="text-center"><strong>{{ state.limits.max_artist_plays }}</strong></td>
                            <td>{{ $gettext('Max songs by the same artist per 3-hour window') }}</td>
                        </tr>
                        <tr>
                            <td>{{ $gettext('Rule 4b') }}</td>
                            <td class="text-center"><strong>{{ state.limits.max_consecutive_artist }}</strong></td>
                            <td>{{ $gettext('Max consecutive songs by the same artist') }}</td>
                        </tr>
                    </tbody>
                </table>
            </fieldset>

            <!-- Warnings Table -->
            <fieldset>
                <legend>
                    {{ $gettext('Songs At or Near Limits') }}
                    <span
                        v-if="state.warning_count > 0"
                        class="badge bg-danger ms-2"
                    >{{ state.warning_count }}</span>
                    <span
                        v-else
                        class="badge bg-success ms-2"
                    >{{ $gettext('All Clear') }}</span>
                </legend>

                <p class="text-muted small mb-3">
                    {{ $gettext('Songs that have reached or are within one play of a DMCA limit in the current window. These are candidates for rejection by the compliance plugin.') }}
                </p>

                <table
                    v-if="state.warnings.length > 0"
                    class="table table-striped table-condensed"
                >
                    <thead>
                        <tr>
                            <th>{{ $gettext('Song') }}</th>
                            <th class="text-center">{{ $gettext('Song Plays') }}</th>
                            <th class="text-center">{{ $gettext('Album Plays') }}</th>
                            <th class="text-center">{{ $gettext('Artist Plays') }}</th>
                            <th>{{ $gettext('Issues') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in state.warnings"
                            :key="row.song_id"
                        >
                            <td>
                                <strong>{{ row.title }}</strong>
                                <br>
                                <span class="text-muted small">{{ row.artist }}</span>
                                <span
                                    v-if="row.album"
                                    class="text-muted small"
                                > · {{ row.album }}</span>
                            </td>
                            <td class="text-center">
                                <span :class="row.song_plays >= state.limits.max_song_plays ? 'text-danger fw-bold' : 'text-warning'">
                                    {{ row.song_plays }}/{{ state.limits.max_song_plays }}
                                </span>
                            </td>
                            <td class="text-center">
                                <span :class="row.album_plays >= state.limits.max_album_plays ? 'text-danger fw-bold' : ''">
                                    {{ row.album_plays }}/{{ state.limits.max_album_plays }}
                                </span>
                            </td>
                            <td class="text-center">
                                <span :class="row.artist_plays >= state.limits.max_artist_plays ? 'text-danger fw-bold' : ''">
                                    {{ row.artist_plays }}/{{ state.limits.max_artist_plays }}
                                </span>
                            </td>
                            <td>
                                <span
                                    v-for="issue in row.issues"
                                    :key="issue"
                                    class="badge bg-danger me-1"
                                >{{ issue }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p
                    v-else
                    class="text-success mb-0"
                >
                    ✓ {{ $gettext('No songs are at or near DMCA limits in the current window.') }}
                </p>
            </fieldset>
        </div>
    </loading>
</template>

<script setup lang="ts">
import { toRef } from 'vue';
import { useAxios } from '~/vendor/axios';
import Loading from '~/components/Common/Loading.vue';
import { useAsyncState } from '@vueuse/core';

const props = defineProps<{
    apiUrl: string;
}>();

const { axios } = useAxios();

const { state, isLoading } = useAsyncState(
    () => axios.get(toRef(props, 'apiUrl').value).then(r => r.data),
    null
);
</script>
