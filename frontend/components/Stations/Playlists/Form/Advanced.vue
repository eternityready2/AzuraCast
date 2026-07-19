<template>
    <tab
        :label="$gettext('Advanced')"
        :item-header-class="tabClass"
    >
        <div class="row g-3">
            <form-group-multi-check
                id="edit_form_backend_options"
                class="col-md-12"
                :field="r$.backend_options"
                :options="backendOptions"
                stacked
                :label="$gettext('Advanced Manual AutoDJ Scheduling Options')"
                :description="$gettext('Control how this playlist is handled by the AutoDJ software.')"
            />
        </div>

        <form-fieldset>
            <template #label>
                {{ $gettext('Sponsor Guaranteed Playout') }}
            </template>

            <div class="form-check mb-3">
                <input
                    id="edit_form_is_sponsor"
                    v-model="form.is_sponsor"
                    class="form-check-input"
                    type="checkbox"
                >
                <label class="form-check-label" for="edit_form_is_sponsor">
                    {{ $gettext('This is a sponsor/paid ad spot') }}
                </label>
            </div>
            <small class="text-muted d-block mb-3">
                {{ $gettext('When enabled, AzuraCast guarantees this playlist gets its required number of plays each day -- never silently skipped by normal rotation, the same way the legal ID is guaranteed at the top of the hour. Shows up in the Sponsor Play Report for proof-of-delivery.') }}
            </small>

            <template v-if="form.is_sponsor">
                <div class="row g-3">
                    <form-group-field
                        id="edit_form_sponsor_name"
                        class="col-md-6"
                        :field="r$.sponsor_name"
                        :label="$gettext('Sponsor Name')"
                        :description="$gettext('Shown on the Sponsor Play Report. Defaults to the playlist name if left blank.')"
                    />
                    <form-group-field
                        id="edit_form_sponsor_guaranteed_plays_per_day"
                        class="col-md-6"
                        :field="r$.sponsor_guaranteed_plays_per_day"
                        type="number"
                        :label="$gettext('Guaranteed Plays Per Day')"
                        :description="$gettext('Minimum number of times this must air each day. Leave empty to disable the guarantee (still tracked, not enforced).')"
                    />
                </div>
            </template>
        </form-fieldset>
    </tab>
</template>

<script setup lang="ts">
import {useTranslate} from "~/vendor/gettext";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormFieldset from "~/components/Form/FormFieldset.vue";
import Tab from "~/components/Common/Tab.vue";
import {storeToRefs} from "pinia";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form.ts";
import {useFormTabClass} from "~/functions/useFormTabClass.ts";
import {computed} from "vue";

const {r$, form} = storeToRefs(useStationsPlaylistsForm());

const tabClass = useFormTabClass(computed(() => r$.value.$groups.advancedTab));

const {$gettext} = useTranslate();

const backendOptions = [
    {
        value: 'interrupt',
        text: $gettext('Interrupt other songs to play at scheduled time.')
    },
    {
        value: 'single_track',
        text: $gettext('Only play one track at scheduled time.')
    },
    {
        value: 'merge',
        text: $gettext('Merge playlist to play as a single track.')
    },
    {
        value: 'prioritize',
        text: $gettext('Prioritize over listener requests.')
    }
];
</script>
