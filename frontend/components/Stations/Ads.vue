<template>
    <card-page :title="$gettext('Advertising')">
        <template #info>
            <p class="card-text">
                {{
                    $gettext('Sell airtime and let the station keep the promise. Book an order, load the spot, and the AutoDJ guarantees the plays per day and logs every airing for the proof-of-delivery report.')
                }}
            </p>
        </template>

        <div class="card-body">
            <ul class="nav nav-pills mb-3">
                <li class="nav-item">
                    <button
                        type="button"
                        class="nav-link"
                        :class="{active: tab === 'orders'}"
                        @click="tab = 'orders'"
                    >
                        {{ $gettext('Orders') }}
                        <span class="badge text-bg-secondary ms-1">{{ orders.length }}</span>
                    </button>
                </li>
                <li class="nav-item">
                    <button
                        type="button"
                        class="nav-link"
                        :class="{active: tab === 'advertisers'}"
                        @click="tab = 'advertisers'"
                    >
                        {{ $gettext('Advertisers') }}
                        <span class="badge text-bg-secondary ms-1">{{ advertisers.length }}</span>
                    </button>
                </li>
                <li class="nav-item ms-auto">
                    <router-link
                        class="nav-link"
                        :to="{name: 'stations:reports:overview'}"
                    >
                        {{ $gettext('Proof of Delivery Report') }}
                    </router-link>
                </li>
            </ul>

            <loading :loading="isLoading">
                <!-- ══════════════ Orders ══════════════ -->
                <section v-if="tab === 'orders'">
                    <div class="mb-3">
                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="advertisers.length === 0"
                            @click="openOrderEditor(null)"
                        >
                            {{ $gettext('Book an Order') }}
                        </button>
                        <span
                            v-if="advertisers.length === 0"
                            class="text-muted ms-2"
                        >
                            {{ $gettext('Add an advertiser first, then book their airtime.') }}
                        </span>
                    </div>

                    <div
                        v-if="orderEditorOpen"
                        class="card mb-4"
                    >
                        <div class="card-header text-bg-primary">
                            <h5 class="m-0">
                                {{ orderForm.id ? $gettext('Edit Order') : $gettext('Book an Order') }}
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">{{ $gettext('Advertiser') }}</label>
                                    <select
                                        v-model.number="orderForm.advertiser_id"
                                        class="form-select"
                                        :disabled="!!orderForm.id"
                                    >
                                        <option
                                            v-for="a in advertisers"
                                            :key="a.id"
                                            :value="a.id"
                                        >
                                            {{ a.name }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ $gettext('Order Name') }}</label>
                                    <input
                                        v-model="orderForm.name"
                                        type="text"
                                        class="form-control"
                                        :placeholder="$gettext('e.g. Fall campaign')"
                                    >
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">{{ $gettext('What They Bought') }}</label>
                                    <select
                                        v-model="orderForm.product"
                                        class="form-select"
                                    >
                                        <option value="spots_4">{{ $gettext('30-second spots, 4 a day') }}</option>
                                        <option value="spots_8">{{ $gettext('30-second spots, 8 a day') }}</option>
                                        <option value="program_sponsor">{{ $gettext('Program sponsorship') }}</option>
                                        <option value="custom">{{ $gettext('Custom booking') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">{{ $gettext('Spots a Day') }}</label>
                                    <input
                                        v-model.number="orderForm.spots_per_day"
                                        type="number"
                                        min="0"
                                        max="96"
                                        class="form-control"
                                        :disabled="productSpots !== null"
                                    >
                                    <div
                                        v-if="productSpots !== null"
                                        class="form-text"
                                    >
                                        {{ $gettext('Set by the product.') }}
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">{{ $gettext('Spot Length') }}</label>
                                    <select
                                        v-model.number="orderForm.spot_seconds"
                                        class="form-select"
                                    >
                                        <option :value="30">{{ $gettext('30 seconds') }}</option>
                                        <option :value="60">{{ $gettext('60 seconds') }}</option>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">{{ $gettext('Starts') }}</label>
                                    <input
                                        v-model="orderStartDate"
                                        type="date"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ $gettext('Ends') }}</label>
                                    <input
                                        v-model="orderEndDate"
                                        type="date"
                                        class="form-control"
                                    >
                                    <div class="form-text">
                                        {{ $gettext('Runs through the end of this day.') }}
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ $gettext('Monthly Rate ($)') }}</label>
                                    <input
                                        v-model="orderForm.rate_monthly"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        class="form-control"
                                    >
                                </div>

                                <div
                                    v-if="orderForm.product === 'program_sponsor'"
                                    class="col-md-6"
                                >
                                    <label class="form-label">{{ $gettext('Which Show') }}</label>
                                    <input
                                        v-model="orderForm.program_name"
                                        type="text"
                                        class="form-control"
                                        :placeholder="$gettext('The weekly show this sponsors')"
                                    >
                                </div>

                                <div class="col-12">
                                    <div class="form-check">
                                        <input
                                            id="ad_order_live_read"
                                            v-model="orderForm.is_live_read"
                                            class="form-check-input"
                                            type="checkbox"
                                        >
                                        <label
                                            class="form-check-label"
                                            for="ad_order_live_read"
                                        >
                                            {{ $gettext('Live read — the host reads a script on air, no audio file') }}
                                        </label>
                                    </div>
                                </div>

                                <div
                                    v-if="orderForm.is_live_read"
                                    class="col-12"
                                >
                                    <label class="form-label">{{ $gettext('Script') }}</label>
                                    <textarea
                                        v-model="orderForm.script"
                                        class="form-control"
                                        rows="4"
                                        :placeholder="$gettext('What the host reads. Keep it to the booked length.')"
                                    />
                                </div>

                                <div
                                    v-else
                                    class="col-12"
                                >
                                    <label class="form-label">{{ $gettext('Spot Audio') }}</label>
                                    <div
                                        v-if="orderForm.media_id"
                                        class="mb-2"
                                    >
                                        <span class="badge text-bg-success me-2">{{ $gettext('Loaded') }}</span>
                                        {{ orderForm.media_title || ('#' + orderForm.media_id) }}
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger ms-2"
                                            @click="clearSpotFile"
                                        >
                                            {{ $gettext('Change') }}
                                        </button>
                                    </div>
                                    <template v-else>
                                        <input
                                            v-model="mediaSearch"
                                            type="text"
                                            class="form-control"
                                            :placeholder="$gettext('Search the station media library for the spot…')"
                                            @input="searchMedia"
                                        >
                                        <div
                                            v-if="mediaResults.length > 0"
                                            class="list-group mt-2"
                                        >
                                            <button
                                                v-for="m in mediaResults"
                                                :key="m.id"
                                                type="button"
                                                class="list-group-item list-group-item-action"
                                                @click="pickSpotFile(m)"
                                            >
                                                {{ m.label }}
                                            </button>
                                        </div>
                                        <div class="form-text">
                                            {{ $gettext('Upload new audio under Media > Music Files first (a "Promos, Ads" folder keeps things tidy), then find it here.') }}
                                        </div>
                                    </template>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">{{ $gettext('Status') }}</label>
                                    <select
                                        v-model="orderForm.status"
                                        class="form-select"
                                    >
                                        <option value="draft">{{ $gettext('Draft — not on air') }}</option>
                                        <option value="approved">{{ $gettext('Approved — airs inside its dates') }}</option>
                                        <option value="paused">{{ $gettext('Paused') }}</option>
                                        <option value="ended">{{ $gettext('Ended') }}</option>
                                        <option value="declined">{{ $gettext('Declined') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ $gettext('PO / Order #') }}</label>
                                    <input
                                        v-model="orderForm.po_number"
                                        type="text"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ $gettext('Notes (private)') }}</label>
                                    <input
                                        v-model="orderForm.notes"
                                        type="text"
                                        class="form-control"
                                    >
                                </div>
                            </div>

                            <div class="mt-3">
                                <button
                                    type="button"
                                    class="btn btn-success me-2"
                                    :disabled="isSaving"
                                    @click="saveOrder"
                                >
                                    {{ orderForm.id ? $gettext('Save Changes') : $gettext('Book It') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="orderEditorOpen = false"
                                >
                                    {{ $gettext('Cancel') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ $gettext('Advertiser / Order') }}</th>
                                    <th>{{ $gettext('Bought') }}</th>
                                    <th>{{ $gettext('Runs') }}</th>
                                    <th>{{ $gettext('Rate') }}</th>
                                    <th>{{ $gettext('Creative') }}</th>
                                    <th>{{ $gettext('Status') }}</th>
                                    <th class="text-end">{{ $gettext('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="orders.length === 0">
                                    <td
                                        colspan="7"
                                        class="text-muted"
                                    >
                                        {{ $gettext('Nothing sold yet. Book the first order and it goes straight to air.') }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="o in orders"
                                    :key="o.id"
                                >
                                    <td>
                                        <strong>{{ o.advertiser_name }}</strong><br>
                                        <small class="text-muted">{{ o.name }}</small>
                                    </td>
                                    <td>
                                        {{ productLabel(o.product) }}<br>
                                        <small class="text-muted">
                                            {{ o.spots_per_day_effective }} {{ $gettext('a day') }} · {{ o.spot_seconds }}s
                                            <template v-if="o.program_name"> · {{ o.program_name }}</template>
                                        </small>
                                    </td>
                                    <td>
                                        {{ shortDate(o.starts_at) }} → {{ shortDate(o.ends_at) }}
                                    </td>
                                    <td>
                                        <template v-if="o.rate_monthly">${{ o.rate_monthly }}/mo</template>
                                        <template v-else>—</template>
                                    </td>
                                    <td>
                                        <span
                                            v-if="o.is_live_read"
                                            class="badge text-bg-info"
                                        >{{ $gettext('Live read') }}</span>
                                        <span
                                            v-else-if="o.has_creative"
                                            class="badge text-bg-success"
                                        >{{ $gettext('Loaded') }}</span>
                                        <span
                                            v-else
                                            class="badge text-bg-danger"
                                        >{{ $gettext('Missing') }}</span>
                                    </td>
                                    <td>
                                        <span
                                            class="badge"
                                            :class="statusClass(o)"
                                        >{{ statusLabel(o) }}</span>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <button
                                                v-if="o.status === 'draft'"
                                                type="button"
                                                class="btn btn-success"
                                                @click="setStatus(o, 'approved')"
                                            >
                                                {{ $gettext('Approve') }}
                                            </button>
                                            <button
                                                v-if="o.status === 'approved'"
                                                type="button"
                                                class="btn btn-warning"
                                                @click="setStatus(o, 'paused')"
                                            >
                                                {{ $gettext('Pause') }}
                                            </button>
                                            <button
                                                v-if="o.status === 'paused'"
                                                type="button"
                                                class="btn btn-success"
                                                @click="setStatus(o, 'approved')"
                                            >
                                                {{ $gettext('Resume') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-primary"
                                                @click="openOrderEditor(o)"
                                            >
                                                {{ $gettext('Edit') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-danger"
                                                @click="deleteOrder(o)"
                                            >
                                                {{ $gettext('Delete') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- ══════════════ Advertisers ══════════════ -->
                <section v-if="tab === 'advertisers'">
                    <div class="mb-3">
                        <button
                            type="button"
                            class="btn btn-primary"
                            @click="openAdvertiserEditor(null)"
                        >
                            {{ $gettext('Add Advertiser') }}
                        </button>
                    </div>

                    <div
                        v-if="advertiserEditorOpen"
                        class="card mb-4"
                    >
                        <div class="card-header text-bg-primary">
                            <h5 class="m-0">
                                {{ advertiserForm.id ? $gettext('Edit Advertiser') : $gettext('Add Advertiser') }}
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">{{ $gettext('Business or Ministry Name') }}</label>
                                    <input
                                        v-model="advertiserForm.name"
                                        type="text"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ $gettext('Contact Person') }}</label>
                                    <input
                                        v-model="advertiserForm.contact_name"
                                        type="text"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ $gettext('Email') }}</label>
                                    <input
                                        v-model="advertiserForm.email"
                                        type="email"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ $gettext('Phone') }}</label>
                                    <input
                                        v-model="advertiserForm.phone"
                                        type="text"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ $gettext('Website') }}</label>
                                    <input
                                        v-model="advertiserForm.website"
                                        type="text"
                                        class="form-control"
                                        placeholder="https://"
                                    >
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input
                                            id="ad_adv_nonprofit"
                                            v-model="advertiserForm.is_nonprofit"
                                            class="form-check-input"
                                            type="checkbox"
                                        >
                                        <label
                                            class="form-check-label"
                                            for="ad_adv_nonprofit"
                                        >
                                            {{ $gettext('Church or nonprofit (rate card discount applies)') }}
                                        </label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">{{ $gettext('Notes (private)') }}</label>
                                    <textarea
                                        v-model="advertiserForm.notes"
                                        class="form-control"
                                        rows="2"
                                    />
                                </div>
                            </div>
                            <div class="mt-3">
                                <button
                                    type="button"
                                    class="btn btn-success me-2"
                                    :disabled="isSaving"
                                    @click="saveAdvertiser"
                                >
                                    {{ $gettext('Save') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="advertiserEditorOpen = false"
                                >
                                    {{ $gettext('Cancel') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ $gettext('Name') }}</th>
                                    <th>{{ $gettext('Contact') }}</th>
                                    <th>{{ $gettext('Orders') }}</th>
                                    <th class="text-end">{{ $gettext('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="advertisers.length === 0">
                                    <td
                                        colspan="4"
                                        class="text-muted"
                                    >
                                        {{ $gettext('No advertisers yet.') }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="a in advertisers"
                                    :key="a.id"
                                >
                                    <td>
                                        <strong>{{ a.name }}</strong>
                                        <span
                                            v-if="a.is_nonprofit"
                                            class="badge text-bg-info ms-2"
                                        >{{ $gettext('Nonprofit') }}</span>
                                        <br>
                                        <small
                                            v-if="a.website"
                                            class="text-muted"
                                        >{{ a.website }}</small>
                                    </td>
                                    <td>
                                        {{ a.contact_name }}<br>
                                        <small class="text-muted">{{ a.email }}<template v-if="a.phone"> · {{ a.phone }}</template></small>
                                    </td>
                                    <td>{{ a.orders_count }}</td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <button
                                                type="button"
                                                class="btn btn-primary"
                                                @click="openAdvertiserEditor(a)"
                                            >
                                                {{ $gettext('Edit') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-danger"
                                                @click="deleteAdvertiser(a)"
                                            >
                                                {{ $gettext('Delete') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </loading>
        </div>
    </card-page>
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from "vue";
import {useTranslate} from "~/vendor/gettext";
import Loading from "~/components/Common/Loading.vue";
import CardPage from "~/components/Common/CardPage.vue";
import {useAxios} from "~/vendor/axios";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {getStationApiUrl} = useApiRouter();

const advertisersUrl = getStationApiUrl('/ads/advertisers');
const ordersUrl = getStationApiUrl('/ads/orders');
const filesListUrl = getStationApiUrl('/files/list');
const orderItemUrl = (id: number) => getStationApiUrl(`/ads/order/${id}`).value;
const advertiserItemUrl = (id: number) => getStationApiUrl(`/ads/advertiser/${id}`).value;

interface Advertiser {
    id: number | null;
    name: string;
    contact_name: string | null;
    email: string | null;
    phone: string | null;
    website: string | null;
    is_nonprofit: boolean;
    notes: string | null;
    orders_count?: number;
}

interface Order {
    id: number | null;
    advertiser_id: number | null;
    advertiser_name?: string;
    name: string;
    product: string;
    status: string;
    spots_per_day: number;
    spots_per_day_effective?: number;
    spot_seconds: number;
    is_live_read: boolean;
    script: string | null;
    program_name: string | null;
    starts_at: number | null;
    ends_at: number | null;
    rate_monthly: string | number | null;
    po_number: string | null;
    notes: string | null;
    media_id: number | null;
    media_title?: string | null;
    is_on_air?: boolean;
    has_creative?: boolean;
}

const tab = ref<'orders' | 'advertisers'>('orders');
const isLoading = ref(true);
const isSaving = ref(false);

const advertisers = ref<Advertiser[]>([]);
const orders = ref<Order[]>([]);

/* Both endpoints paginate; rowCount=0 asks for everything. A station selling
   hundreds of concurrent campaigns has outgrown this screen anyway. */
const rows = (data: any): any[] => Array.isArray(data) ? data : (data?.rows ?? []);

const reload = async (): Promise<void> => {
    const [a, o] = await Promise.all([
        axios.get(advertisersUrl.value, {params: {rowCount: 0}}),
        axios.get(ordersUrl.value, {params: {rowCount: 0}}),
    ]);
    advertisers.value = rows(a.data);
    orders.value = rows(o.data);
};

onMounted(async () => {
    try {
        await reload();
    } catch (e) {
        notifyError($gettext('The advertising lists could not be loaded.'));
    } finally {
        isLoading.value = false;
    }
});

const apiError = (e: any): string =>
    e?.response?.data?.message ?? e?.response?.data?.formatted_message ?? $gettext('Something went wrong.');

/* ─── Orders ──────────────────────────────────────────────────────── */

const blankOrder = (): Order => ({
    id: null,
    advertiser_id: advertisers.value[0]?.id ?? null,
    name: '',
    product: 'spots_4',
    status: 'draft',
    spots_per_day: 0,
    spot_seconds: 30,
    is_live_read: false,
    script: null,
    program_name: null,
    starts_at: null,
    ends_at: null,
    rate_monthly: null,
    po_number: null,
    notes: null,
    media_id: null,
    media_title: null,
});

const orderEditorOpen = ref(false);
const orderForm = ref<Order>(blankOrder());

/* spots_4 and spots_8 carry their own daily count; the field locks to it. */
const productSpots = computed<number | null>(() => {
    switch (orderForm.value.product) {
        case 'spots_4':
            return 4;
        case 'spots_8':
            return 8;
        default:
            return null;
    }
});

/* Dates edit as local calendar days; the API stores Unix timestamps.
   The end lands on 23:59:59, so "ends Dec 24" still airs on Dec 24. */
const tsToDate = (ts: number | null): string => {
    if (!ts) {
        return '';
    }
    const d = new Date(ts * 1000);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};
const dateToTs = (v: string, endOfDay: boolean): number | null => {
    if (!v) {
        return null;
    }
    const [y, m, d] = v.split('-').map(Number);
    const date = endOfDay
        ? new Date(y, m - 1, d, 23, 59, 59)
        : new Date(y, m - 1, d, 0, 0, 0);
    return Math.floor(date.getTime() / 1000);
};

const orderStartDate = computed<string>({
    get: () => tsToDate(orderForm.value.starts_at),
    set: (v) => {
        orderForm.value.starts_at = dateToTs(v, false);
    }
});
const orderEndDate = computed<string>({
    get: () => tsToDate(orderForm.value.ends_at),
    set: (v) => {
        orderForm.value.ends_at = dateToTs(v, true);
    }
});

const openOrderEditor = (o: Order | null): void => {
    orderForm.value = o ? {...blankOrder(), ...o} : blankOrder();
    mediaSearch.value = '';
    mediaResults.value = [];
    orderEditorOpen.value = true;
    window.scrollTo({top: 0, behavior: 'smooth'});
};

const saveOrder = async (): Promise<void> => {
    const f = orderForm.value;

    if (!f.advertiser_id) {
        notifyError($gettext('Pick the advertiser this booking is for.'));
        return;
    }
    if (!f.name.trim()) {
        notifyError($gettext('Give the order a name.'));
        return;
    }
    if (productSpots.value !== null) {
        f.spots_per_day = productSpots.value;
    }

    isSaving.value = true;
    try {
        if (f.id) {
            await axios.put(orderItemUrl(f.id), f);
        } else {
            await axios.post(ordersUrl.value, f);
        }
        notifySuccess(f.id ? $gettext('Order saved.') : $gettext('Order booked.'));
        orderEditorOpen.value = false;
        await reload();
    } catch (e) {
        notifyError(apiError(e));
    } finally {
        isSaving.value = false;
    }
};

const setStatus = async (o: Order, status: string): Promise<void> => {
    try {
        await axios.put(orderItemUrl(o.id as number), {...o, status});
        notifySuccess($gettext('Order updated.'));
        await reload();
    } catch (e) {
        notifyError(apiError(e));
    }
};

const deleteOrder = async (o: Order): Promise<void> => {
    if (!confirm($gettext('Delete this order? It comes off the air immediately and its history stops counting toward this booking.'))) {
        return;
    }
    try {
        await axios.delete(orderItemUrl(o.id as number));
        notifySuccess($gettext('Order deleted.'));
        await reload();
    } catch (e) {
        notifyError(apiError(e));
    }
};

/* ─── The spot file picker ────────────────────────────────────────── */

const mediaSearch = ref('');
const mediaResults = ref<{ id: number, label: string }[]>([]);
let mediaSearchTimer: ReturnType<typeof setTimeout> | null = null;

const searchMedia = (): void => {
    if (mediaSearchTimer) {
        clearTimeout(mediaSearchTimer);
    }
    mediaSearchTimer = setTimeout(async () => {
        const phrase = mediaSearch.value.trim();
        if (phrase.length < 2) {
            mediaResults.value = [];
            return;
        }
        try {
            const {data} = await axios.get(filesListUrl.value, {
                params: {searchPhrase: phrase, rowCount: 10, current: 1}
            });
            mediaResults.value = rows(data)
                .filter((r: any) => r.media?.id)
                .map((r: any) => ({
                    id: r.media.id,
                    label: r.text || r.path_short || r.path,
                }));
        } catch {
            mediaResults.value = [];
        }
    }, 300);
};

const pickSpotFile = (m: { id: number, label: string }): void => {
    orderForm.value.media_id = m.id;
    orderForm.value.media_title = m.label;
    mediaResults.value = [];
    mediaSearch.value = '';
};

const clearSpotFile = (): void => {
    orderForm.value.media_id = null;
    orderForm.value.media_title = null;
};

/* ─── Labels ──────────────────────────────────────────────────────── */

const productLabel = (p: string): string => {
    switch (p) {
        case 'spots_4':
            return $gettext('Spots, 4 a day');
        case 'spots_8':
            return $gettext('Spots, 8 a day');
        case 'program_sponsor':
            return $gettext('Program sponsorship');
        default:
            return $gettext('Custom booking');
    }
};

const shortDate = (ts: number | null): string =>
    ts ? new Date(ts * 1000).toLocaleDateString() : '—';

const statusLabel = (o: Order): string => {
    if (o.status === 'approved') {
        return o.is_on_air ? $gettext('On air') : $gettext('Approved');
    }
    switch (o.status) {
        case 'draft':
            return $gettext('Draft');
        case 'paused':
            return $gettext('Paused');
        case 'ended':
            return $gettext('Ended');
        default:
            return $gettext('Declined');
    }
};

const statusClass = (o: Order): string => {
    if (o.status === 'approved') {
        return o.is_on_air ? 'text-bg-success' : 'text-bg-primary';
    }
    switch (o.status) {
        case 'draft':
            return 'text-bg-secondary';
        case 'paused':
            return 'text-bg-warning';
        case 'ended':
            return 'text-bg-dark';
        default:
            return 'text-bg-danger';
    }
};

/* ─── Advertisers ─────────────────────────────────────────────────── */

const blankAdvertiser = (): Advertiser => ({
    id: null,
    name: '',
    contact_name: null,
    email: null,
    phone: null,
    website: null,
    is_nonprofit: false,
    notes: null,
});

const advertiserEditorOpen = ref(false);
const advertiserForm = ref<Advertiser>(blankAdvertiser());

const openAdvertiserEditor = (a: Advertiser | null): void => {
    advertiserForm.value = a ? {...blankAdvertiser(), ...a} : blankAdvertiser();
    advertiserEditorOpen.value = true;
};

const saveAdvertiser = async (): Promise<void> => {
    const f = advertiserForm.value;
    if (!f.name.trim()) {
        notifyError($gettext('The advertiser needs a name.'));
        return;
    }
    isSaving.value = true;
    try {
        if (f.id) {
            await axios.put(advertiserItemUrl(f.id), f);
        } else {
            await axios.post(advertisersUrl.value, f);
        }
        notifySuccess($gettext('Advertiser saved.'));
        advertiserEditorOpen.value = false;
        await reload();
    } catch (e) {
        notifyError(apiError(e));
    } finally {
        isSaving.value = false;
    }
};

const deleteAdvertiser = async (a: Advertiser): Promise<void> => {
    if (!confirm($gettext('Delete this advertiser?'))) {
        return;
    }
    try {
        await axios.delete(advertiserItemUrl(a.id as number));
        notifySuccess($gettext('Advertiser deleted.'));
        await reload();
    } catch (e) {
        notifyError(apiError(e));
    }
};
</script>
