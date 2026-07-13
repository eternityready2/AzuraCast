<template>
    <!--
        FILE LOCATION: frontend/components/Admin/Stations/Form/DmcaComplianceForm.vue

        ADD TO: frontend/components/Admin/Stations/Form/BackendForm.vue
        1. Import at the top of <script setup>:
           import DmcaComplianceForm from './DmcaComplianceForm.vue';

        2. Add the component inside the form, after the crossfade section:
           <dmca-compliance-form v-model="form" />
    -->
    <fieldset>
        <legend>{{ $gettext('DMCA Compliance') }}</legend>

        <p class="text-muted small mb-3">
            {{ $gettext('Enforce DMCA § 114 digital performance rules at the queue level. When enabled, songs that would violate these limits are automatically rejected before reaching Liquidsoap, and AzuraCast retries with a different track. Works with both standard playlists and clock wheels.') }}
        </p>

        <div class="mb-3">
            <div class="form-check form-switch">
                <input
                    id="dmca_compliance_enabled"
                    v-model="form.dmca_compliance_enabled"
                    type="checkbox"
                    class="form-check-input"
                >
                <label
                    class="form-check-label"
                    for="dmca_compliance_enabled"
                >
                    {{ $gettext('Enable DMCA Compliance Enforcement') }}
                </label>
            </div>
            <p class="text-muted small mt-1">
                {{ $gettext('When enabled, the compliance plugin actively blocks songs that exceed the limits below.') }}
            </p>
        </div>

        <div v-if="form.dmca_compliance_enabled">
            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label" for="dmca_window_minutes">
                        {{ $gettext('Rolling Window (minutes)') }}
                    </label>
                    <input
                        id="dmca_window_minutes"
                        v-model.number="form.dmca_window_minutes"
                        type="number"
                        class="form-control"
                        min="60"
                        max="360"
                    >
                    <p class="text-muted small mt-1">
                        {{ $gettext('DMCA statutory window is 180 minutes (3 hours). Do not lower below 180 without legal advice.') }}
                    </p>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="dmca_max_song_plays">
                        {{ $gettext('Rule 1 — Max Same Song Plays per Window') }}
                    </label>
                    <input
                        id="dmca_max_song_plays"
                        v-model.number="form.dmca_max_song_plays"
                        type="number"
                        class="form-control"
                        min="1"
                        max="10"
                    >
                    <p class="text-muted small mt-1">{{ $gettext('DMCA default: 3') }}</p>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="dmca_max_consecutive_song">
                        {{ $gettext('Rule 2 — Max Consecutive Same Song Plays') }}
                    </label>
                    <input
                        id="dmca_max_consecutive_song"
                        v-model.number="form.dmca_max_consecutive_song"
                        type="number"
                        class="form-control"
                        min="1"
                        max="5"
                    >
                    <p class="text-muted small mt-1">{{ $gettext('DMCA default: 2') }}</p>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="dmca_max_album_plays">
                        {{ $gettext('Rule 3 — Max Same Album Plays per Window') }}
                    </label>
                    <input
                        id="dmca_max_album_plays"
                        v-model.number="form.dmca_max_album_plays"
                        type="number"
                        class="form-control"
                        min="1"
                        max="10"
                    >
                    <p class="text-muted small mt-1">{{ $gettext('DMCA default: 3') }}</p>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="dmca_max_artist_plays">
                        {{ $gettext('Rule 4a — Max Same Artist Plays per Window') }}
                    </label>
                    <input
                        id="dmca_max_artist_plays"
                        v-model.number="form.dmca_max_artist_plays"
                        type="number"
                        class="form-control"
                        min="1"
                        max="10"
                    >
                    <p class="text-muted small mt-1">{{ $gettext('DMCA default: 4') }}</p>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="dmca_max_consecutive_artist">
                        {{ $gettext('Rule 4b — Max Consecutive Same Artist Plays') }}
                    </label>
                    <input
                        id="dmca_max_consecutive_artist"
                        v-model.number="form.dmca_max_consecutive_artist"
                        type="number"
                        class="form-control"
                        min="1"
                        max="10"
                    >
                    <p class="text-muted small mt-1">{{ $gettext('DMCA default: 3') }}</p>
                </div>

            </div>
        </div>
    </fieldset>
</template>

<script setup lang="ts">
const form = defineModel<{
    dmca_compliance_enabled: boolean;
    dmca_window_minutes: number;
    dmca_max_song_plays: number;
    dmca_max_consecutive_song: number;
    dmca_max_album_plays: number;
    dmca_max_artist_plays: number;
    dmca_max_consecutive_artist: number;
}>();
</script>
