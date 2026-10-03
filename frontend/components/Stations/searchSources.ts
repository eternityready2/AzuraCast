import {useAxios} from "~/vendor/axios.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useTranslate} from "~/vendor/gettext.ts";
import {DataSearchSource, MenuSearchResult, rowText as str, searchRowsOf as rowsOf, SearchRow as Row} from "~/functions/menuSearch.ts";
import {MenuRouteBasedUrl} from "~/functions/filterMenu.ts";

const MAX_PER_SOURCE = 5;

export function useStationSearchSources(): DataSearchSource[] {
    const {axios} = useAxios();
    const {getStationApiUrl} = useApiRouter();
    const {$gettext} = useTranslate();

    const result = (key: string, label: string, trail: string, url: MenuRouteBasedUrl): MenuSearchResult => ({
        key,
        label,
        trail,
        url,
        external: false,
        isPageText: false,
    });

    // List APIs that support `searchPhrase`; the result opens the list page filtered to that record.
    const searchableList = (
        apiPath: string,
        routeName: string,
        trail: string,
        labelOf: (row: Row) => string,
        params: Record<string, string> = {}
    ): DataSearchSource => ({
        routeName,
        search: async (query) => {
            const {data} = await axios.get(getStationApiUrl(apiPath).value, {
                params: {searchPhrase: query, rowCount: MAX_PER_SOURCE, ...params}
            });

            return rowsOf(data).slice(0, MAX_PER_SOURCE).map((row) => {
                const label = labelOf(row);
                return result(`${routeName}:${str(row.id) || label}`, label, trail, {
                    name: routeName,
                    query: {search: label},
                });
            }).filter((row) => row.label !== '');
        },
    });

    // The shows API has no search, but the list is small: load it once per sidebar.
    let showsPromise: Promise<Row[]> | null = null;

    const shows: DataSearchSource = {
        routeName: 'stations:shows:index',
        search: async (query) => {
            showsPromise ??= axios.get(getStationApiUrl('/shows').value).then(({data}) => rowsOf(data));
            const needle = query.toLowerCase();

            return (await showsPromise)
                .filter((row) => str(row.name).toLowerCase().includes(needle))
                .slice(0, MAX_PER_SOURCE)
                .map((row) => result(`show:${str(row.id)}`, str(row.name), $gettext('Shows'), {
                    name: 'stations:shows:edit',
                    params: {show_id: str(row.id)},
                }));
        },
    };

    const podcasts: DataSearchSource = {
        routeName: 'stations:podcasts:index',
        search: async (query) => {
            const {data} = await axios.get(getStationApiUrl('/podcasts').value, {
                params: {searchPhrase: query, rowCount: MAX_PER_SOURCE}
            });

            return rowsOf(data).slice(0, MAX_PER_SOURCE).map((row) => result(
                `podcast:${str(row.id)}`,
                str(row.title),
                $gettext('Podcasts'),
                {name: 'stations:podcast:episodes', params: {podcast_id: str(row.id)}}
            ));
        },
    };

    return [
        searchableList('/files/list', 'stations:files:index', $gettext('Music Files'), (row) => {
            const media = row.media as Row | null;
            return str(media?.title) || str(row.path_short) || str(row.text);
        }, {currentDirectory: ''}),
        searchableList('/playlists', 'stations:playlists:index', $gettext('Playlists'), (row) => str(row.name)),
        shows,
        podcasts,
        searchableList('/streamers', 'stations:streamers:index', $gettext('Streamer/DJ Accounts'),
            (row) => str(row.display_name) || str(row.streamer_username)),
        searchableList('/mounts', 'stations:mounts:index', $gettext('Mount Points'),
            (row) => str(row.display_name) || str(row.name)),
        searchableList('/remotes', 'stations:remotes:index', $gettext('Remote Relays'), (row) => str(row.display_name)),
        searchableList('/webhooks', 'stations:webhooks:index', $gettext('Web Hooks'), (row) => str(row.name)),
    ];
}
