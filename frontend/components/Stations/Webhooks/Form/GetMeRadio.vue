<template>
    <tab
        :label="title"
        :item-header-class="tabClass"
    >
        <div class="row g-3">
            <form-group-field
                id="form_config_token"
                class="col-md-6"
                :field="r$!.config.token"
                :label="$gettext('API Token')"
                :description="$gettext('This can be retrieved from the GetMeRadio dashboard.')"
            />

            <form-group-field
                id="form_config_station_id"
                class="col-md-6"
                :field="r$!.config.station_id"
                :label="$gettext('GetMeRadio Station ID')"
                :description="$gettext('This is a 3-5 digit number.')"
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

const r$ = variantToRef(original$, 'type', WebhookTypes.GetMeRadio);

const tabClass = useFormTabClass(computed(() => r$.value!.$groups.getMeRadioTab));
</script>
