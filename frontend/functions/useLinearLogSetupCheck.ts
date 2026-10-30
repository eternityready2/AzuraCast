import {computed, onMounted, ref} from "vue";
import {useAxios} from "~/vendor/axios";
import {useApiRouter} from "~/functions/useApiRouter";

export type LinearLogSetupIssue = {
    id: string,
    message: string,
    fix: string | null,
    link: string | null,
};

/**
 * The 24-hour log's setup check: station setup that makes the log misbehave,
 * in plain words, with a one-click fix where one is safe.
 */
export function useLinearLogSetupCheck() {
    const {axios} = useAxios();
    const {getStationApiUrl} = useApiRouter();
    const listUrl = getStationApiUrl('/reports/linear-log/setup-check');
    const fixUrl = getStationApiUrl('/reports/linear-log/setup-check/fix');

    const issues = ref<LinearLogSetupIssue[]>([]);
    const loaded = ref(false);
    const isFixing = ref(false);
    const fixError = ref<string | null>(null);

    const fixableCount = computed(() => issues.value.filter((issue) => null !== issue.fix).length);

    const load = async () => {
        try {
            const {data} = await axios.get<{issues: LinearLogSetupIssue[]}>(listUrl.value);
            issues.value = data.issues ?? [];
        } finally {
            loaded.value = true;
        }
    };

    const fix = async (body: {id?: string, all?: boolean}) => {
        isFixing.value = true;
        fixError.value = null;
        try {
            const {data} = await axios.post<{issues: LinearLogSetupIssue[]}>(fixUrl.value, body);
            issues.value = data.issues ?? [];
        } catch (e: any) {
            fixError.value = e?.response?.data?.message ?? String(e);
            await load();
        } finally {
            isFixing.value = false;
        }
    };

    onMounted(load);

    return {
        issues,
        loaded,
        isFixing,
        fixError,
        fixableCount,
        reload: load,
        fixOne: (id: string) => fix({id}),
        fixAll: () => fix({all: true}),
    };
}
