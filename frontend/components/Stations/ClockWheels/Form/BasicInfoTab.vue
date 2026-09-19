<template>
    <tab
        id="basic-info"
        :label="$gettext('Basic Info')"
    >
        <section class="clock-wheel-editor-section">
            <div class="clock-wheel-editor-section__heading">
                <div>
                    <h3>{{ $gettext('Basic Information') }}</h3>
                    <p>{{ $gettext('Name this wheel, set its color and configure its core playback rules.') }}</p>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-8">
                    <form-group-field
                        id="name"
                        :field="r$.name"
                        :label="$gettext('Title')"
                    />
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">{{ $gettext('Color') }}</label>
                    <div class="clock-wheel-color-field">
                        <input
                            id="color"
                            v-model="form.color"
                            type="color"
                            class="clock-wheel-color-input"
                        >
                        <span class="small text-muted">{{ form.color }}</span>
                    </div>
                </div>

                <div class="col-12">
                    <form-group-select
                        id="fill_strategy"
                        class="mb-0"
                        :field="r$.fill_strategy"
                        :label="$gettext('Fill Strategy')"
                        :description="$gettext('Choose how AutoDJ fills tight timing windows between anchors.')"
                        :options="fillStrategyOptions"
                    />
                </div>
            </div>
        </section>

        <section class="clock-wheel-editor-section mt-3">
            <div class="clock-wheel-editor-section__heading">
                <div>
                    <h3>{{ $gettext('Template & Separation') }}</h3>
                    <p>{{ $gettext('Optionally inherit a reusable template and protect artist/title spacing.') }}</p>
                </div>
            </div>

            <div
                v-if="isDaypartManaged"
                class="alert alert-secondary py-2 mb-3"
            >
                {{ $gettext('This wheel is managed by a Daypart. Its template link and slots are updated when that Daypart is saved or re-synced.') }}
            </div>

            <template v-else-if="templateOptions.length > 0">
                <div class="row g-3 mb-3">
                    <div class="col-md-7">
                        <form-group-select
                            id="clock_wheel_template"
                            class="mb-0"
                            :field="r$.template_id"
                            :label="$gettext('Clock Template')"
                            :options="templateSelectOptions"
                            :description="$gettext('Link this wheel to a reusable template layout.')"
                        />
                    </div>
                    <div class="col-md-5 d-flex align-items-end">
                        <form-group-checkbox
                            id="inherits_template_slots"
                            class="mb-0"
                            :field="r$.inherits_template_slots"
                            :label="$gettext('Inherit Template Slots')"
                            :description="$gettext('Keep this wheel synchronized with its linked template.')"
                            :input-attrs="{disabled: !form.template_id}"
                        />
                    </div>
                </div>
            </template>

            <form-group-checkbox
                id="separation_enabled"
                class="mb-3"
                :field="r$.separation_enabled"
                :label="$gettext('Enable Separation Rules')"
                :description="$gettext('Apply artist/title spacing and optional burn-rate protection while this wheel queues music.')"
            />

            <div
                v-if="form.separation_enabled"
                class="row g-3"
            >
                <div class="col-md-4">
                    <form-group-field
                        id="separation_artist_minutes"
                        :field="r$.separation_artist_minutes"
                        :label="$gettext('Artist Separation (min)')"
                        input-type="number"
                    />
                </div>
                <div class="col-md-4">
                    <form-group-field
                        id="separation_title_minutes"
                        :field="r$.separation_title_minutes"
                        :label="$gettext('Title Separation (min)')"
                        input-type="number"
                    />
                </div>
                <div class="col-md-4">
                    <form-group-field
                        id="burn_rate_max_plays_24h"
                        :field="r$.burn_rate_max_plays_24h"
                        :label="$gettext('Max Plays / 24h')"
                        input-type="number"
                        :description="$gettext('Leave empty to disable burn-rate deprioritization.')"
                    />
                </div>
            </div>
        </section>
    </tab>
</template>

<script setup lang="ts">
import {computed} from 'vue';
import Tab from '~/components/Common/Tab.vue';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import FormGroupCheckbox from '~/components/Form/FormGroupCheckbox.vue';
import FormGroupSelect from '~/components/Form/FormGroupSelect.vue';
import type {ValidatedField} from '~/components/Form/useFormField';
import {useTranslate} from '~/vendor/gettext';

type ClockWheelForm = {
    name: string;
    color: string;
    is_active: boolean;
    fill_strategy: string;
    separation_enabled: boolean;
    separation_artist_minutes: number;
    separation_title_minutes: number;
    burn_rate_max_plays_24h: number | null;
    template_id: number | null;
    inherits_template_slots: boolean;
    daypart_id: number | null;
};

type ClockWheelValidation = {
    name: ValidatedField<string>;
    color: ValidatedField<string>;
    is_active: ValidatedField<boolean>;
    fill_strategy: ValidatedField<string>;
    separation_enabled: ValidatedField<boolean>;
    separation_artist_minutes: ValidatedField<number>;
    separation_title_minutes: ValidatedField<number>;
    burn_rate_max_plays_24h: ValidatedField<number | null>;
    template_id: ValidatedField<number | null>;
    inherits_template_slots: ValidatedField<boolean>;
};

const props = withDefaults(defineProps<{
    form: ClockWheelForm;
    r$: ClockWheelValidation;
    templateOptions?: {value: number; text: string}[];
}>(), {
    templateOptions: () => [],
});

const {$gettext} = useTranslate();

const fillStrategyOptions = computed(() => [
    {value: 'conservative', text: $gettext('Conservative (defer tight windows)')},
    {value: 'aggressive', text: $gettext('Aggressive (shortest fit)')},
]);

const templateSelectOptions = computed(() => [
    {value: null as unknown as number, text: $gettext('— None —')},
    ...props.templateOptions,
]);

const isDaypartManaged = computed(() =>
    props.form.daypart_id != null && props.form.daypart_id > 0
);
</script>
