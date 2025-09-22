<template>
    <tab
        :label="title"
        :item-header-class="tabClass"
    >
        <div class="row g-3">
            <form-group-field
                id="form_config_webhookurl"
                class="col-md-12"
                :field="r$!.config.webhookurl"
                :label="$gettext('RadioReg Webhook URL')"
                :description="$gettext('Found under the settings page for the corresponding RadioReg station.')"
            />

            <form-group-field
                id="form_config_apikey"
                class="col-md-6"
                :field="r$!.config.apikey"
                :label="$gettext('RadioReg Organization API Key')"
                :description="$gettext('An API token is issued on a per-organization basis and are found on the org. settings page.')"
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

const r$ = variantToRef(original$, 'type', WebhookTypes.RadioReg);

const tabClass = useFormTabClass(computed(() => r$.value!.$groups.radioRegTab));
</script>
