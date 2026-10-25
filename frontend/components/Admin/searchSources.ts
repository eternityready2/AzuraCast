import {useAxios} from "~/vendor/axios.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useTranslate} from "~/vendor/gettext.ts";
import {DataSearchSource, rowText as str, searchRowsOf as rowsOf, SearchRow as Row} from "~/functions/menuSearch.ts";

const MAX_PER_SOURCE = 5;

export function useAdminSearchSources(): DataSearchSource[] {
    const {axios} = useAxios();
    const {getApiUrl} = useApiRouter();
    const {$gettext} = useTranslate();

    const list = async (apiPath: string, query: string): Promise<Row[]> => {
        const {data} = await axios.get(getApiUrl(apiPath).value, {
            params: {searchPhrase: query, rowCount: MAX_PER_SOURCE}
        });
        return rowsOf(data).slice(0, MAX_PER_SOURCE);
    };

    return [
        {
            routeName: 'admin:stations:index',
            search: async (query) => (await list('/admin/stations', query)).map((row) => ({
                key: `admin-station:${str(row.id)}`,
                label: str(row.name),
                trail: $gettext('Stations'),
                url: {name: 'stations:index', params: {station_id: str(row.id)}},
                external: false,
                isPageText: false,
            })),
        },
        {
            routeName: 'admin:users:index',
            search: async (query) => (await list('/admin/users', query)).map((row) => ({
                key: `admin-user:${str(row.id)}`,
                label: str(row.name) || str(row.email),
                trail: $gettext('User Accounts'),
                url: {name: 'admin:users:index', query: {search: str(row.email) || str(row.name)}},
                external: false,
                isPageText: false,
            })),
        },
    ];
}
