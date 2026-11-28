import { recentGamesBefore, recentGameQuery } from '@/lib/recentGameEvidence';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { ApiError } from '@/composables/useApiClient';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { flattenApiV2Stats } from '@/composables/useApiV2StatsAdapter';
import type {
    ApiEnvelope,
    BasketballHistoricalPrediction,
    Game,
    Prediction,
    Team,
    TeamMetric,
    TeamStatsEntry,
    TeamTrendData,
    TopPerformer,
} from '@/types';

interface UseDetailedGameDataOptions {
    sport: string;
    gameId: number;
    sortTopPerformers?: (players: TopPerformer[]) => TopPerformer[];
    metricFromResponse?: (
        payload: ApiEnvelope<TeamMetric | TeamMetric[] | null>,
    ) => TeamMetric | null;
}

const defaultMetricFromResponse = (
    payload: ApiEnvelope<TeamMetric | TeamMetric[] | null>,
): TeamMetric | null => {
    if (Array.isArray(payload?.data)) return payload.data[0] ?? null;
    return payload?.data ?? null;
};

const hasFinalBoxScore = (status: string | null | undefined): boolean =>
    ['STATUS_FINAL', 'STATUS_FULL_TIME'].includes(status ?? '');

export function useDetailedGameData(options: UseDetailedGameDataOptions) {
    const api = useApiV2Client();
    const game = ref<Game | null>(null);
    const homeTeam = ref<Team | null>(null);
    const awayTeam = ref<Team | null>(null);
    const prediction = ref<Prediction | Record<string, unknown> | null>(null);
    const historicalPrediction = ref<BasketballHistoricalPrediction | null>(
        null,
    );
    const homeMetrics = ref<TeamMetric | null>(null);
    const awayMetrics = ref<TeamMetric | null>(null);
    const homeTeamStats = ref<TeamStatsEntry | null>(null);
    const awayTeamStats = ref<TeamStatsEntry | null>(null);
    const topPerformers = ref<TopPerformer[]>([]);
    const homeRecentGames = ref<Game[]>([]);
    const awayRecentGames = ref<Game[]>([]);
    const homeTrends = ref<TeamTrendData | null>(null);
    const awayTrends = ref<TeamTrendData | null>(null);
    const trendsLoading = ref(false);
    const loading = ref(true);
    const error = ref<string | null>(null);
    let requestController: AbortController | null = null;
    let generation = 0;
    let disposed = false;

    const resolveMetrics =
        options.metricFromResponse ?? defaultMetricFromResponse;

    const load = async () => {
        if (disposed) return;
        const request = ++generation;
        requestController?.abort();
        requestController = new AbortController();
        const signal = requestController.signal;
        const isCurrent = () =>
            !disposed && generation === request && !signal.aborted;

        try {
            loading.value = true;
            error.value = null;

            const [gameData, predictionData] = await Promise.all([
                api.games.show(options.sport, options.gameId, {
                    init: { signal },
                }),
                api.predictions
                    .forGame(options.sport, options.gameId, {
                        init: { signal },
                    })
                    .catch((error: unknown) => {
                        if (error instanceof ApiError && error.status === 404)
                            return null;
                        throw error;
                    }),
            ]);

            if (!isCurrent()) return;
            homeMetrics.value = awayMetrics.value = null;
            homeTrends.value = awayTrends.value = null;
            homeTeamStats.value = awayTeamStats.value = null;
            topPerformers.value = [];
            homeRecentGames.value = [];
            awayRecentGames.value = [];
            const archive = (
                gameData as unknown as {
                    historical_prediction?: BasketballHistoricalPrediction | null;
                }
            )?.historical_prediction;
            historicalPrediction.value =
                ['nba', 'cbb', 'wcbb', 'wnba'].includes(options.sport) &&
                archive &&
                ['legacy_archive', 'legacy_history'].includes(archive.source)
                    ? archive
                    : null;
            const fullGame = gameData?.data as unknown as Game | undefined;
            if (fullGame) {
                game.value = fullGame;
                homeTeam.value = fullGame.home_team ?? null;
                awayTeam.value = fullGame.away_team ?? null;
            }

            prediction.value = predictionData?.data ?? null;

            loading.value = false;
            const supplemental: Promise<unknown>[] = [];

            if (hasFinalBoxScore(game.value?.status)) {
                supplemental.push(
                    api.stats
                        .teams(options.sport, {
                            query: { game_id: options.gameId, per_page: 100 },
                            init: { signal },
                        })
                        .then((response) => {
                            if (!isCurrent()) return;
                            const stats = flattenApiV2Stats(
                                response?.data,
                            ) as TeamStatsEntry[];
                            homeTeamStats.value =
                                stats.find((row) => row.team_type === 'home') ??
                                null;
                            awayTeamStats.value =
                                stats.find((row) => row.team_type === 'away') ??
                                null;
                        }),
                    api.stats
                        .players(options.sport, {
                            query: { game_id: options.gameId, per_page: 100 },
                            init: { signal },
                        })
                        .then((response) => {
                            if (!isCurrent()) return;
                            const players = flattenApiV2Stats(
                                response?.data,
                            ) as TopPerformer[];
                            topPerformers.value = options.sortTopPerformers
                                ? options.sortTopPerformers(players)
                                : players.slice(0, 10);
                        }),
                );
            } else {
                homeTeamStats.value = null;
                awayTeamStats.value = null;
                topPerformers.value = [];
            }

            const recentQuery = game.value ? recentGameQuery(game.value) : null;
            const metricQuery = {
                ...(game.value?.season != null
                    ? { season: game.value.season }
                    : {}),
                ...(game.value?.season_type != null
                    ? { season_type: String(game.value.season_type) }
                    : {}),
            };
            const homeTeamId = homeTeam.value?.id ?? game.value?.home_team_id;
            const awayTeamId = awayTeam.value?.id ?? game.value?.away_team_id;

            if (homeTeamId) {
                supplemental.push(
                    api.teams
                        .metrics(options.sport, homeTeamId, {
                            query: metricQuery,
                            init: { signal },
                        })
                        .then((response) => {
                            if (!isCurrent()) return;
                            if (response) {
                                homeMetrics.value = resolveMetrics(
                                    response as unknown as ApiEnvelope<
                                        TeamMetric | TeamMetric[] | null
                                    >,
                                );
                            }
                        }),
                    recentQuery
                        ? api.teams
                              .games(options.sport, homeTeamId, {
                                  query: recentQuery ?? {
                                      per_page: 1,
                                      exclude_game_id: options.gameId,
                                  },
                                  init: { signal },
                              })
                              .then((response) => {
                                  if (!isCurrent()) return;
                                  homeRecentGames.value = game.value
                                      ? recentGamesBefore(
                                            (response?.data ??
                                                []) as unknown as Game[],
                                            game.value,
                                            homeTeamId,
                                        )
                                      : [];
                              })
                        : Promise.resolve(),
                );
            }

            if (awayTeamId) {
                supplemental.push(
                    api.teams
                        .metrics(options.sport, awayTeamId, {
                            query: metricQuery,
                            init: { signal },
                        })
                        .then((response) => {
                            if (!isCurrent()) return;
                            if (response) {
                                awayMetrics.value = resolveMetrics(
                                    response as unknown as ApiEnvelope<
                                        TeamMetric | TeamMetric[] | null
                                    >,
                                );
                            }
                        }),
                    recentQuery
                        ? api.teams
                              .games(options.sport, awayTeamId, {
                                  query: recentQuery ?? {
                                      per_page: 1,
                                      exclude_game_id: options.gameId,
                                  },
                                  init: { signal },
                              })
                              .then((response) => {
                                  if (!isCurrent()) return;
                                  awayRecentGames.value = game.value
                                      ? recentGamesBefore(
                                            (response?.data ??
                                                []) as unknown as Game[],
                                            game.value,
                                            awayTeamId,
                                        )
                                      : [];
                              })
                        : Promise.resolve(),
                );
            }

            if (homeTeamId && awayTeamId) {
                trendsLoading.value = true;
                const beforeDate = game.value?.game_date || '';
                const trendQuery = new URLSearchParams({
                    games: 'season',
                    before_date: beforeDate,
                });

                if (game.value?.season) {
                    trendQuery.set('season', String(game.value.season));
                }

                if (game.value?.season_type) {
                    trendQuery.set(
                        'season_type',
                        String(game.value.season_type),
                    );
                }

                const query = Object.fromEntries(trendQuery.entries());
                supplemental.push(
                    api.teams
                        .trends(options.sport, homeTeamId, {
                            query,
                            init: { signal },
                        })
                        .then((response) => {
                            if (!isCurrent()) return;
                            homeTrends.value =
                                (response?.data as TeamTrendData | undefined) ??
                                null;
                        }),
                    api.teams
                        .trends(options.sport, awayTeamId, {
                            query,
                            init: { signal },
                        })
                        .then((response) => {
                            if (!isCurrent()) return;
                            awayTrends.value =
                                (response?.data as TeamTrendData | undefined) ??
                                null;
                        }),
                );
            }

            const results = await Promise.allSettled(supplemental);
            if (
                isCurrent() &&
                results.some((result) => result.status === 'rejected')
            ) {
                error.value =
                    'Some supplemental game context is unavailable. Retry the game page.';
            }
        } catch (e) {
            if (!isCurrent()) return;
            if (e instanceof DOMException && e.name === 'AbortError') return;
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            if (isCurrent()) {
                loading.value = false;
                trendsLoading.value = false;
            }
        }
    };

    onMounted(load);
    onBeforeUnmount(() => {
        disposed = true;
        generation++;
        requestController?.abort();
    });

    return {
        game,
        homeTeam,
        awayTeam,
        prediction,
        historicalPrediction,
        homeMetrics,
        awayMetrics,
        homeTeamStats,
        awayTeamStats,
        topPerformers,
        homeRecentGames,
        awayRecentGames,
        homeTrends,
        awayTrends,
        trendsLoading,
        loading,
        error,
        reload: load,
    };
}
