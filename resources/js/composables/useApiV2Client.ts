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

type RequestOptions = {
    init?: RequestInit;
    query?: ApiV2Query;
};

type ApiV2MutationError = Error & {
    data?: unknown;
    status: number;
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

    const mutate = async <T>(
        url: string,
        method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
        payload?: ApiV2JsonPayload,
        options: RequestOptions = {},
    ): Promise<T | null> => {
        const headers = new Headers(options.init?.headers ?? {});
        headers.set('Content-Type', 'application/json');

        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options.init,
            method,
            headers,
            body: payload === undefined ? undefined : JSON.stringify(payload),
        });

        if (!response.ok) {
            const error = new Error('API v2 request failed') as ApiV2MutationError;
            error.status = response.status;

            try {
                error.data = await response.json();
            } catch {
                error.data = null;
            }

            throw error;
        }

        if (response.status === 204) {
            return null;
        }

        const contentType = response.headers.get('content-type') ?? '';
        if (!contentType.includes('application/json')) {
            return null;
        }

        return (await response.json()) as T;
    };

    return {
        get,

        liveScoreboard: {
            show: (options: RequestOptions = {}) =>
                item<ApiV2LiveScoreboardPayload>(
                    v2.liveScoreboard.show.url(routeOptions(options.query)),
                    options,
                ),
        },

        userBets: {
            index: <T = unknown>(options: RequestOptions = {}) =>
                get<T>(
                    v2.userBets.index.url(routeOptions(options.query)),
                    options,
                ),
            store: <T = unknown>(
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    v2.userBets.store.url(routeOptions(options.query)),
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
                    v2.userBets.update.url(
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
                    v2.userBets.destroy.url(
                        bet,
                        routeOptions(options.query),
                    ),
                    'DELETE',
                    undefined,
                    options,
                ),
            exportUrl: (query?: ApiV2Query) =>
                v2.userBets.export.url(routeOptions(query)),
        },

        cbbBrackets: {
            index: <T = unknown>(options: RequestOptions = {}) =>
                get<T>(
                    v2.cbbBrackets.index.url(routeOptions(options.query)),
                    options,
                ),
            leaderboard: <T = unknown>(options: RequestOptions = {}) =>
                get<T>(
                    v2.cbbBrackets.leaderboard.url(
                        routeOptions(options.query),
                    ),
                    options,
                ),
            show: <T = unknown>(
                publicId: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                get<T>(
                    v2.cbbBrackets.show.url(
                        publicId,
                        routeOptions(options.query),
                    ),
                    options,
                ),
            current: <T = unknown>(options: RequestOptions = {}) =>
                get<T>(
                    v2.cbbBrackets.current.show.url(
                        routeOptions(options.query),
                    ),
                    options,
                ),
            store: <T = unknown>(
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    v2.cbbBrackets.store.url(routeOptions(options.query)),
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
                    v2.cbbBrackets.update.url(
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
                    v2.cbbBrackets.current.upsert.url(
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
                    v2.cbbBrackets.destroy.url(
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
                get<T>(v2.groups.index.url(routeOptions(options.query)), options),
            store: <T = unknown>(
                payload: ApiV2JsonPayload,
                options: RequestOptions = {},
            ) =>
                mutate<T>(
                    v2.groups.store.url(routeOptions(options.query)),
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
                    v2.groups.update.url(publicId, routeOptions(options.query)),
                    'PATCH',
                    payload,
                    options,
                ),
        },

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
            depthCharts: (
                sport: ApiV2SportSlug,
                game: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                item<GameDepthChartsData>(
                    v2.sports.games.depthCharts.show.url(
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
            games: (
                sport: ApiV2SportSlug,
                team: ApiV2Id,
                options: RequestOptions = {},
            ) =>
                collection<ApiV2Game>(
                    v2.sports.teams.games.index.url(
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
                    v2.sports.teams.metrics.show.url(
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
                    v2.sports.teams.trends.show.url(
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
                    v2.sports.teams.stats.seasonAverages.show.url(
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
                    v2.sports.teams.depthCharts.show.url(
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

        forecasts: {
            index: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                collection<T>(
                    v2.sports.forecasts.index.url(
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
                    v2.sports.injuries.index.url(
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
                    v2.sports.signals.index.url(
                        sport,
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
            playerAvailableSeasons: (
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                get<ApiV2ItemResponse<number[]>>(
                    v2.sports.stats.player.availableSeasons.url(
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
                    v2.sports.stats.player.availableDates.url(
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
            teamSeasonAverages: <T = ApiV2Record>(
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                collection<T>(
                    v2.sports.stats.team.seasonAverages.index.url(
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
                    v2.sports.stats.team.availableSeasons.url(
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
                    v2.sports.stats.team.availableDates.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        metrics: {
            teams: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2TeamMetric>(
                    v2.sports.metrics.teams.index.url(
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
                    v2.sports.metrics.teams.availableSeasons.url(
                        sport,
                        routeOptions(options.query),
                    ),
                    options,
                ),
        },

        leaderboards: {
            players: (sport: ApiV2SportSlug, options: RequestOptions = {}) =>
                collection<ApiV2PlayerLeaderboardRow>(
                    v2.sports.leaderboards.players.index.url(
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
                    v2.sports.leaderboards.players.availableSeasons.url(
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
            board: <T = unknown>(
                sport: ApiV2SportSlug,
                options: RequestOptions = {},
            ) =>
                get<T>(
                    v2.sports.playerProps.board.url(
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
                item<ApiV2PayloadInspector>(
                    v2.admin.payloadInspector.url(routeOptions(options.query)),
                    options,
                ),
        },
    };
}
