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
    const playlists = ref<SelectOption[]>([]);
    const loaded = ref(false);

    const categoryOptions = computed(() => [
        {value: null, text: '—'},
        ...categories.value,
    ]);

    const playlistOptions = computed(() => [
        {value: null, text: '—'},
        ...playlists.value,
    ]);

    const load = async () => {
        if (loaded.value) {
            return;
        }

        const [categoriesRes, playlistsRes] = await Promise.all([
            axios.get<Array<{id: number; name: string}>>(getStationApiUrl('/media-categories').value),
            axios.get<Array<{id: number; name: string}>>(getStationApiUrl('/playlists').value),
        ]);

        categories.value = categoriesRes.data.map((row) => ({
            value: row.id,
            text: row.name,
        }));

        playlists.value = playlistsRes.data.map((row) => ({
            value: row.id,
            text: row.name,
        }));

        loaded.value = true;
    };

    return {
        categoryOptions,
        playlistOptions,
        load,
        loaded,
    };
}
