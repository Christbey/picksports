import {
    fetchJson,
    mutateJson,
    type ApiMutationOptions,
} from '@/composables/useApiClient';
import { apiRoute, type RouteQueryOptions } from '@/lib/apiV2Routes';
import type {
    ApiV2CollectionResponse,
    ApiV2FuturesOdd,
    ApiV2Game,
    ApiV2Id,
    ApiV2ItemResponse,
    ApiV2PayloadInspector,
    ApiV2Player,
    ApiV2PlayerLeaderboardRow,
    ApiV2PlayerProp,
    ApiV2Prediction,
    ApiV2Query,
    ApiV2Record,
    ApiV2Sport,
    ApiV2SportSlug,
    ApiV2Stat,
    ApiV2Team,
    ApiV2TeamMetric,
    DashboardPrediction,
    GameDepthChartTeam,
    GameDepthChartsData,
} from '@/types';

type ApiV2LiveScoreboardPayload = {
    games: DashboardPrediction[];
    updated_at: string;
};

type ApiV2JsonPayload = Record<string, unknown>;

type RequestOptions = ApiMutationOptions & {
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

    const mutate = mutateJson;

    return {
        get,

        liveScoreboard: {
            show: (options: RequestOptions = {}) =>
                item<ApiV2LiveScoreboardPayload>(
                    apiRoute(
                        'liveScoreboard.show',
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        userBets: {
            index: <T = unknown>(options: RequestOptions = {}) =>
                get<T>(
                    apiRoute('userBets.index', routeOptions(options.query)),
                    options,
                ),
            store: <T = unknown>(
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    apiRoute('userBets.store', routeOptions(options.query)),
                    'POST',
                    payload,
                    options,
                ),
            update: <T = unknown>(
                bet: ApiV2Id,
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    apiRoute(
                        'userBets.update',
                        bet,
                        routeOptions(options.query),
                    ),
                    'PUT',
                    payload,
                    options,
                ),
            destroy: <T = unknown>(
                bet: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    apiRoute(
                        'userBets.destroy',
                        bet,
                        routeOptions(options.query),
                    ),
                    'DELETE',
                    undefined,
                    options,
                ),
            exportUrl: (query?: ApiV2Query) =>
                apiRoute('userBets.export', routeOptions(query)),
        },

        cbbBrackets: {
            index: <T = unknown>(options: RequestOptions = {}) =>
                get<T>(
                    apiRoute('cbbBrackets.index', routeOptions(options.query)),
                    options,
                ),
            leaderboard: <T = unknown>(options: RequestOptions = {}) =>
                get<T>(
                    apiRoute(
                        'cbbBrackets.leaderboard',
                        routeOptions(options.query),
                    ),
                    options,
                ),
            show: <T = unknown>(
                publicId: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                get<T>(
                    apiRoute(
                        'cbbBrackets.show',
                        publicId,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            current: <T = unknown>(options: RequestOptions = {}) =>
                get<T>(
                    apiRoute(
                        'cbbBrackets.current.show',
                        routeOptions(options.query),
                    ),
                    options,
                ),
            store: <T = unknown>(
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    apiRoute('cbbBrackets.store', routeOptions(options.query)),
                    'POST',
                    payload,
                    options,
                ),
            update: <T = unknown>(
                publicId: ApiV2Id,
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    apiRoute(
                        'cbbBrackets.update',
                        publicId,
                        routeOptions(options.query),
                    ),
                    'PATCH',
                    payload,
                    options,
                ),
            upsertCurrent: <T = unknown>(
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    apiRoute(
                        'cbbBrackets.current.upsert',
                        routeOptions(options.query),
                    ),
                    'PUT',
                    payload,
                    options,
                ),
            destroy: <T = unknown>(
                publicId: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    apiRoute(
                        'cbbBrackets.destroy',
                        publicId,
                        routeOptions(options.query),
                    ),
                    'DELETE',
                    undefined,
                    options,
                ),
        },

        groups: {
            index: <T = unknown>(options: RequestOptions = {}) =>
                get<T>(
                    apiRoute('groups.index', routeOptions(options.query)),
                    options,
                ),
            store: <T = unknown>(
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    apiRoute('groups.store', routeOptions(options.query)),
                    'POST',
                    payload,
                    options,
                ),
            update: <T = unknown>(
                publicId: ApiV2Id,
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    apiRoute(
                        'groups.update',
                        publicId,
                        routeOptions(options.query),
                    ),
                    'PATCH',
                    payload,
                    options,
                ),
        },

        alertPreferences: {
            show: <T = unknown>(options: RequestOptions = {}) =>
                get<T>(
                    apiRoute(
                        'alertPreferences.show',
                        routeOptions(options.query),
                    ),
                    options,
                ),
            store: <T = unknown>(
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    apiRoute(
                        'alertPreferences.store',
                        routeOptions(options.query),
                    ),
                    'POST',
                    payload,
                    options,
                ),
            update: <T = unknown>(
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    apiRoute(
                        'alertPreferences.update',
                        routeOptions(options.query),
                    ),
                    'PUT',
                    payload,
                    options,
                ),
        },

        sports: {
            index: (options: RequestOptions = {}) =>
                collection<ApiV2Sport>(
                    apiRoute('sports.index', routeOptions(options.query)),
                    options,
                ),
            show: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                item<ApiV2Sport>(
                    apiRoute('sports.show', sport, routeOptions(options.query)),
                    options,
                ),
        },

        nflResearchRetry: {
            show: (game: ApiV2Id, options: RequestOptions = {}) =>
                item<ApiV2Record>(
                    apiRoute('admin.nfl.researchRetry.show', { game }),
                    options,
                ),
            store: (game: ApiV2Id) =>
                mutate<ApiV2ItemResponse<ApiV2Record>>(
                    apiRoute('admin.nfl.researchRetry.store', { game }),
                    'POST',
                    {},
                ),
        },

        games: {
            matchupSignals: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                game: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<T>(
                    apiRoute(
                        'sports.games.matchupSignals.show',
                        { sport, game },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            marketHistory: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                game: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<T>(
                    apiRoute(
                        'sports.games.marketHistory.show',
                        { sport, game },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            research: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                game: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<T>(
                    apiRoute('sports.games.research.show', { sport, game }),
                    options,
                ),
            liveSnapshot: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                game: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<T>(
                    apiRoute('sports.games.liveSnapshot.show', { sport, game }),
                    options,
                ),
            index: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Game>(
                    apiRoute(
                        'sports.games.index',
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
                    apiRoute(
                        'sports.games.show',
                        { sport, game },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            page: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                game: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<T>(
                    apiRoute(
                        'sports.games.page.show',
                        { sport, game },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            trends: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                game: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<T>(
                    apiRoute(
                        'sports.games.trends.show',
                        { sport, game },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            depthCharts: (
                sport: ApiV2SportSlug,
                game: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<GameDepthChartsData>(
                    apiRoute(
                        'sports.games.depthCharts.show',
                        { sport, game },
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        teams: {
            index: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Team>(
                    apiRoute(
                        'sports.teams.index',
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
                    apiRoute(
                        'sports.teams.show',
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
                    apiRoute(
                        'sports.teams.players.index',
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
                    apiRoute(
                        'sports.teams.futures.index',
                        { sport, team },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            games: (
                sport: ApiV2SportSlug,
                team: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                collection<ApiV2Game>(
                    apiRoute(
                        'sports.teams.games.index',
                        { sport, team },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            metrics: (
                sport: ApiV2SportSlug,
                team: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<ApiV2TeamMetric>(
                    apiRoute(
                        'sports.teams.metrics.show',
                        { sport, team },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            trends: (
                sport: ApiV2SportSlug,
                team: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<ApiV2Record>(
                    apiRoute(
                        'sports.teams.trends.show',
                        { sport, team },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            statSeasonAverages: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                team: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<T>(
                    apiRoute(
                        'sports.teams.stats.seasonAverages.show',
                        { sport, team },
                        routeOptions(options.query),
                    ),
                    options,
                ),
            depthCharts: (
                sport: ApiV2SportSlug,
                team: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<GameDepthChartTeam>(
                    apiRoute(
                        'sports.teams.depthCharts.show',
                        { sport, team },
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        players: {
            index: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Player>(
                    apiRoute(
                        'sports.players.index',
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
                    apiRoute(
                        'sports.players.show',
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
                    apiRoute(
                        'sports.players.playerProps.index',
                        { sport, player },
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        predictions: {
            index: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Prediction>(
                    apiRoute(
                        'sports.predictions.index',
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
                    apiRoute(
                        'sports.predictions.availableSeasons',
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
                    apiRoute(
                        'sports.predictions.availableDates',
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
                    apiRoute(
                        'sports.predictions.show',
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
                    apiRoute(
                        'sports.games.prediction.show',
                        { sport, game },
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        forecasts: {
            index: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                collection<T>(
                    apiRoute(
                        'sports.forecasts.index',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        injuries: {
            index: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                collection<T>(
                    apiRoute(
                        'sports.injuries.index',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        signals: {
            index: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                item<T>(
                    apiRoute(
                        'sports.signals.index',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        dailyPicks: {
            index: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) => {
                const params = new URLSearchParams();
                Object.entries(options.query ?? {}).forEach(([key, value]) => {
                    if (value !== undefined && value !== null && value !== '') {
                        params.set(key, String(value));
                    }
                });

                const query = params.toString();

                return get<T>(
                    `/api/v2/sports/${sport}/daily-picks${query ? `?${query}` : ''}`,
                    options,
                );
            },
        },

        stats: {
            players: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Stat>(
                    apiRoute(
                        'sports.stats.player.index',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            playerAvailableSeasons: (
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                get<ApiV2ItemResponse<number[]>>(
                    apiRoute(
                        'sports.stats.player.availableSeasons',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            playerAvailableDates: (
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                get<ApiV2ItemResponse<string[]>>(
                    apiRoute(
                        'sports.stats.player.availableDates',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            teams: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2Stat>(
                    apiRoute(
                        'sports.stats.team.index',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            teamSeasonAverages: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                collection<T>(
                    apiRoute(
                        'sports.stats.team.seasonAverages.index',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            teamAvailableSeasons: (
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                get<ApiV2ItemResponse<number[]>>(
                    apiRoute(
                        'sports.stats.team.availableSeasons',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            teamAvailableDates: (
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                get<ApiV2ItemResponse<string[]>>(
                    apiRoute(
                        'sports.stats.team.availableDates',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        metrics: {
            teams: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2TeamMetric>(
                    apiRoute(
                        'sports.metrics.teams.index',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            teamAvailableSeasons: (
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                get<ApiV2ItemResponse<number[]>>(
                    apiRoute(
                        'sports.metrics.teams.availableSeasons',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        leaderboards: {
            players: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2PlayerLeaderboardRow>(
                    apiRoute(
                        'sports.leaderboards.players.index',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            playerAvailableSeasons: (
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                get<ApiV2ItemResponse<number[]>>(
                    apiRoute(
                        'sports.leaderboards.players.availableSeasons',
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
                    apiRoute(
                        'sports.markets.playerProps.index',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            futures: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2FuturesOdd>(
                    apiRoute(
                        'sports.markets.futures.index',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        playerProps: {
            index: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2PlayerProp>(
                    apiRoute(
                        'sports.playerProps.index',
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            board: <T = unknown>(
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                get<T>(
                    apiRoute(
                        'sports.playerProps.board',
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
                    apiRoute(
                        'sports.games.playerProps.index',
                        { sport, game },
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        admin: {
            payloadInspector: (options: RequestOptions = {}) =>
                item<ApiV2PayloadInspector>(
                    apiRoute(
                        'admin.payloadInspector',
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },
    };
}
