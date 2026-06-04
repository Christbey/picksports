import { fetchJson } from '@/composables/useApiClient';
import v2 from '@/routes/v2';
import type { RouteQueryOptions } from '@/wayfinder';
import type {
    ApiV2CollectionResponse,
    ApiV2FuturesOdd,
    ApiV2Game,
    ApiV2Id,
    ApiV2ItemResponse,
    ApiV2PayloadInspector,
    ApiV2Player,
    ApiV2PlayerProp,
    ApiV2Prediction,
    ApiV2Query,
    ApiV2Sport,
    ApiV2SportSlug,
    ApiV2Stat,
    ApiV2Team,
} from '@/types';

type RequestOptions = {
    init?: RequestInit;
    query?: ApiV2Query;
};

const routeOptions = (query?: ApiV2Query): RouteQueryOptions | undefined =>
    query ? { query } : undefined;

const request = <T>(url: string, init?: RequestInit): Promise<T | null> =>
    fetchJson<T>(url, init);

export function useApiV2Client() {
    const get = <T>(url: string, options: RequestOptions = {}) =>
        request<T>(url, options.init);

    const collection = <T>(url: string, options: RequestOptions = {}) =>
        get<ApiV2CollectionResponse<T>>(url, options);

    const item = <T>(url: string, options: RequestOptions = {}) =>
        get<ApiV2ItemResponse<T>>(url, options);

    return {
        get,

        sports: {
            index: (options: RequestOptions = {}) =>
                collection<ApiV2Sport>(
                    v2.sports.index.url(routeOptions(options.query)),
                    options,
                ),
            show: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                item<ApiV2Sport>(
                    v2.sports.show.url(sport, routeOptions(options.query)),
                    options,
                ),
        },

        games: {
            index: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Game>(
                    v2.sports.games.index.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            show: (
                sport: ApiV2SportSlug,
                game: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<ApiV2Game>(
                    v2.sports.games.show.url(
                        { sport, game },
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        teams: {
            index: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Team>(
                    v2.sports.teams.index.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            show: (
                sport: ApiV2SportSlug,
                team: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<ApiV2Team>(
                    v2.sports.teams.show.url(
                        { sport, team },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            players: (
                sport: ApiV2SportSlug,
                team: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                collection<ApiV2Player>(
                    v2.sports.teams.players.index.url(
                        { sport, team },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            futures: (
                sport: ApiV2SportSlug,
                team: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                collection<ApiV2FuturesOdd>(
                    v2.sports.teams.futures.index.url(
                        { sport, team },
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        players: {
            index: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Player>(
                    v2.sports.players.index.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            show: (
                sport: ApiV2SportSlug,
                player: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<ApiV2Player>(
                    v2.sports.players.show.url(
                        { sport, player },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            playerProps: (
                sport: ApiV2SportSlug,
                player: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                collection<ApiV2PlayerProp>(
                    v2.sports.players.playerProps.index.url(
                        { sport, player },
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        predictions: {
            index: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Prediction>(
                    v2.sports.predictions.index.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            availableSeasons: (
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                get<ApiV2ItemResponse<number[]>>(
                    v2.sports.predictions.availableSeasons.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            availableDates: (
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                get<ApiV2ItemResponse<string[]>>(
                    v2.sports.predictions.availableDates.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            show: (
                sport: ApiV2SportSlug,
                prediction: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<ApiV2Prediction>(
                    v2.sports.predictions.show.url(
                        { sport, prediction },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            forGame: (
                sport: ApiV2SportSlug,
                game: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<ApiV2Prediction>(
                    v2.sports.games.prediction.show.url(
                        { sport, game },
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        stats: {
            players: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Stat>(
                    v2.sports.stats.player.index.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            teams: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Stat>(
                    v2.sports.stats.team.index.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        markets: {
            playerProps: (
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                collection<ApiV2PlayerProp>(
                    v2.sports.markets.playerProps.index.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            futures: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2FuturesOdd>(
                    v2.sports.markets.futures.index.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        playerProps: {
            index: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2PlayerProp>(
                    v2.sports.playerProps.index.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            forGame: (
                sport: ApiV2SportSlug,
                game: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                collection<ApiV2PlayerProp>(
                    v2.sports.games.playerProps.index.url(
                        { sport, game },
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        admin: {
            payloadInspector: (options: RequestOptions = {}) =>
                collection<ApiV2PayloadInspector>(
                    v2.admin.payloadInspector.url(routeOptions(options.query)),
                    options,
                ),
        },
    };
}
