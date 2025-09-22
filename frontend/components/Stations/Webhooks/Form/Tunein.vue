<template>
    <tab
        :label="title"
        :item-header-class="tabClass"
    >
        <div class="row g-3">
            <form-group-field
                id="form_config_station_id"
                class="col-md-6"
                :field="r$!.config.station_id"
                :label="$gettext('TuneIn Station ID')"
                :description="$gettext('The station ID will be a numeric string that starts with the letter S.')"
            />

            <form-group-field
                id="form_config_partner_id"
                class="col-md-6"
                :field="r$!.config.partner_id"
                :label="$gettext('TuneIn Partner ID')"
            />

            <form-group-field
                id="form_config_partner_key"
                class="col-md-6"
                :field="r$!.config.partner_key"
                :label="$gettext('TuneIn Partner Key')"
            />
        </div>
    </tab>
</template>

<script setup lang="ts">
import FormGroupField from "~/components/Form/FormGroupField.vue";
import Tab from "~/components/Common/Tab.vue";
import {WebhookComponentProps} from "~/components/Stations/Webhooks/EditModal.vue";
import {useStationsWebhooksForm} from "~/components/Stations/Webhooks/Form/form.ts";
import {useFormTabClass} from "~/functions/useFormTabClass.ts";
import {storeToRefs} from "pinia";
import {variantToRef} from "@regle/core";
import {WebhookTypes} from "~/entities/ApiInterfaces.ts";
import {computed} from "vue";

defineProps<WebhookComponentProps>();

const formStore = useStationsWebhooksForm();
const {r$: original$} = storeToRefs(formStore);

const r$ = variantToRef(original$, 'type', WebhookTypes.TuneIn);

const tabClass = useFormTabClass(computed(() => r$.value!.$groups.tuneInTab));
</script>
