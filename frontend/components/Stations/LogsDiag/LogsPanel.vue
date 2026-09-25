<template>
    <div class="row row-of-cards">
        <div class="col-lg-8">
            <section
                class="card"
                role="region"
                aria-labelledby="hdr_available_logs"
            >
                <div class="card-header text-bg-primary d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <h2
                            id="hdr_available_logs"
                            class="card-title"
                        >
                            {{ $gettext('Available Logs') }}
                        </h2>
                        <small>{{ $gettext('Click an available file to inspect its current contents.') }}</small>
                    </div>
                    <div v-if="data" class="log-file-summary">
                        <span class="badge text-bg-light">{{ populatedLogCount }} {{ $gettext('with data') }}</span>
                        <span class="badge text-bg-light">{{ emptyLogCount }} {{ $gettext('empty') }}</span>
                        <span class="badge text-bg-light">{{ unavailableLogCount }} {{ $gettext('unavailable') }}</span>
                    </div>
                </div>

                <loading :loading="isLoading" lazy>
                    <div v-if="data" class="list-group list-group-flush">
                        <div
                            v-for="log in data"
                            :key="log.key"
                            class="list-group-item log-item"
                            :class="{'list-group-item-light': !log.exists}"
                        >
                            <button
                                type="button"
                                class="log-view-button"
                                :disabled="!log.exists"
                                @click="viewLog(log.links.self, log.tail)"
                            >
                                <span class="log-name">{{ log.name }}</span>
                                <br>
                                <small class="text-secondary">{{ log.path }}</small>
                                <div class="log-meta text-secondary">
                                    <span v-if="log.exists">{{ formatBytes(log.size) }}</span>
                                    <span v-if="log.modified_at">{{ $gettext('Updated') }} {{ formatModified(log.modified_at) }}</span>
                                    <span v-if="log.exists && log.size === 0">{{ $gettext('No entries have been written yet') }}</span>
                                    <span v-if="!log.exists">{{ $gettext('File has not been created by this service') }}</span>
                                </div>
                            </button>
                            <div class="log-item-actions">
                                <span class="badge" :class="logStateBadgeClass(log)">
                                    {{ logStateLabel(log) }}
                                </span>
                                <a
                                    v-if="log.exists && log.size > 0"
                                    class="btn btn-outline-secondary log-download-btn"
                                    :href="log.links.self + '?download=1'"
                                    :title="$gettext('Download')"
                                    download
                                >
                                    <icon-ic-download />
                                </a>
                            </div>
                        </div>
                    </div>
                </loading>

                <div class="raw-log-note">
                    <strong>{{ $gettext('Need a complete shareable diagnostic?') }}</strong>
                    <span>{{ $gettext('Use Developer Report on the Diagnostics tab. It is generated from station state, execution history, feature diagnostics and live service checks, so it remains useful even when an individual raw error log is empty.') }}</span>
                </div>
            </section>

            <streaming-log-modal ref="$modal" />
        </div>
        <div class="col-lg-4">
            <section
                class="card"
                role="region"
                aria-labelledby="hdr_need_help"
            >
                <div class="card-header text-bg-primary">
                    <h2
                        id="hdr_need_help"
                        class="card-title"
                    >
                        {{ $gettext('Need Help?') }}
                    </h2>
                </div>
                <div class="card-body">
                    <p class="card-text">
                        {{ $gettext('You can find answers for many common questions in our support documents.') }}
                    </p>
                    <p class="card-text">
                        <a
                            href="/docs/help/troubleshooting/"
                            target="_blank"
                        >
                            {{ $gettext('Support Documents') }}
                        </a>
                    </p>
                    <p class="card-text">
                        {{ $gettext('For a bug report, share the filtered Developer Report or CSV plus any populated raw service log relevant to the issue.') }}
                    </p>
                </div>
                <div class="card-body pt-0">
                    <a
                        class="btn btn-primary"
                        role="button"
                        href="https://github.com/AzuraCast/AzuraCast/issues/new/choose"
                        target="_blank"
                    >
                        <icon-ic-support />
                        <span>{{ $gettext('Add New GitHub Issue') }}</span>
                    </a>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup lang="ts">
import StreamingLogModal from "~/components/Common/StreamingLogModal.vue";
import {computed, useTemplateRef} from "vue";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {useQuery} from "@tanstack/vue-query";
import {ApiLogType} from "~/entities/ApiInterfaces.ts";
import {useAxios} from "~/vendor/axios.ts";
import Loading from "~/components/Common/Loading.vue";
import IconIcSupport from "~icons/ic/baseline-support";
import IconIcDownload from "~icons/ic/baseline-download";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const logsUrl = getStationApiUrl('/logs');
const {axios} = useAxios();

type ApiLogRow = Required<ApiLogType> & {
    exists: boolean,
    size: number,
    modified_at: number | null,
}

const {data, isLoading} = useQuery<ApiLogRow[]>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationLogs
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<ApiLogRow[]>(logsUrl.value, {signal});
        return data;
    },
    placeholderData: () => []
});

const populatedLogCount = computed(() => data.value?.filter((log) => log.exists && log.size > 0).length ?? 0);
const emptyLogCount = computed(() => data.value?.filter((log) => log.exists && log.size === 0).length ?? 0);
const unavailableLogCount = computed(() => data.value?.filter((log) => !log.exists).length ?? 0);

const $modal = useTemplateRef('$modal');

const viewLog = (url: string, isStreaming: boolean) => {
    $modal.value?.show(url, isStreaming);
};

const logStateLabel = (log: ApiLogRow): string => {
    if (!log.exists) return $gettext('Unavailable');
    if (log.size === 0) return $gettext('Empty');
    return $gettext('Has Data');
};

const logStateBadgeClass = (log: ApiLogRow): string => {
    if (!log.exists) return 'text-bg-secondary';
    if (log.size === 0) return 'text-bg-warning';
    return 'text-bg-success';
};

const formatModified = (timestamp: number): string => new Date(timestamp * 1000).toLocaleString();

const formatBytes = (bytes: number): string => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};
</script>

<style lang="scss" scoped>
.log-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.log-view-button {
    display: block;
    flex: 1 1 auto;
    min-width: 0;
    border: 0;
    background: transparent;
    padding: 0;
    color: inherit;
    text-align: left;
}

.log-view-button:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.log-name {
    font-size: 1rem;
    font-weight: 600;
}

.log-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 0.25rem;
    font-size: 0.8rem;
}

.log-item-actions {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.log-download-btn {
    display: inline-flex;
    align-items: center;
}

.log-download-btn :deep(svg) {
    width: 1.2rem;
    height: 1.2rem;
}

.log-file-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.raw-log-note {
    display: grid;
    gap: 0.15rem;
    border-top: 1px solid var(--bs-border-color);
    padding: 0.9rem 1rem;
    background: color-mix(in srgb, var(--bs-info) 5%, var(--bs-body-bg));
}

.raw-log-note strong {
    font-size: 0.85rem;
}

.raw-log-note span {
    color: var(--bs-secondary-color);
    font-size: 0.8rem;
    line-height: 1.4;
}
</style>
