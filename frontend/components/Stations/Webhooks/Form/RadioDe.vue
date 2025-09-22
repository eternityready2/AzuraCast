<template>
    <tab
        :label="title"
        :item-header-class="tabClass"
    >
        <div class="row g-3">
            <form-group-field
                id="form_config_broadcastsubdomain"
                class="col-md-12"
                :field="r$!.config.broadcastsubdomain"
                :label="$gettext('Radio.de Broadcast Subdomain')"
            />

            <form-group-field
                id="form_config_apikey"
                class="col-md-6"
                :field="r$!.config.apikey"
                :label="$gettext('Radio.de API Key')"
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

const r$ = variantToRef(original$, 'type', WebhookTypes.RadioDe);

const tabClass = useFormTabClass(computed(() => r$.value!.$groups.radioDeTab));
</script>
