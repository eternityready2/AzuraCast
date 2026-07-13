import {computed, ref} from 'vue';
import {useAxios} from '~/vendor/axios.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';

export interface SelectOption {
    value: number | null;
    text: string;
}

export function useClockWheelSlotOptions() {
    const {axios} = useAxios();
    const {getStationApiUrl} = useApiRouter();

    const categories = ref<SelectOption[]>([]);
    const loaded = ref(false);

    const categoryOptions = computed(() => [
        {value: null, text: '—'},
        ...categories.value,
    ]);

    const load = async () => {
        if (loaded.value) {
            return;
        }

        const {data} = await axios.get<Array<{id: number; name: string}>>(
            getStationApiUrl('/media-categories').value
        );

        categories.value = data.map((row) => ({
            value: row.id,
            text: row.name,
        }));

        loaded.value = true;
    };

    return {
        categoryOptions,
        load,
        loaded,
    };
}
