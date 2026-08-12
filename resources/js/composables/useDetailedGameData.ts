import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { flattenApiV2Stats } from '@/composables/useApiV2StatsAdapter';
import type {
    ApiEnvelope,
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

    const resolveMetrics =
        options.metricFromResponse ?? defaultMetricFromResponse;

    const load = async () => {
        requestController?.abort();
        requestController = new AbortController();
        const signal = requestController.signal;

        try {
            loading.value = true;
            error.value = null;

            const [gameData, predictionData] = await Promise.all([
                api.games.show(options.sport, options.gameId, {
                    init: { signal },
                }),
                api.predictions.forGame(options.sport, options.gameId, {
                    init: { signal },
                }),
            ]);

            const fullGame = gameData?.data as unknown as Game | undefined;
            if (fullGame) {
                game.value = fullGame;
                homeTeam.value = fullGame.home_team ?? homeTeam.value;
                awayTeam.value = fullGame.away_team ?? awayTeam.value;
            }

            if (predictionData) {
                prediction.value = predictionData?.data ?? null;
            }

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

            const homeTeamId = homeTeam.value?.id ?? game.value?.home_team_id;
            const awayTeamId = awayTeam.value?.id ?? game.value?.away_team_id;

            if (homeTeamId) {
                supplemental.push(
                    api.teams
                        .metrics(options.sport, homeTeamId, {
                            init: { signal },
                        })
                        .then((response) => {
                            if (response) {
                                homeMetrics.value = resolveMetrics(
                                    response as unknown as ApiEnvelope<
                                        TeamMetric | TeamMetric[] | null
                                    >,
                                );
                            }
                        }),
                    api.teams
                        .games(options.sport, homeTeamId, {
                            query: { per_page: 25 },
                            init: { signal },
                        })
                        .then((response) => {
                            homeRecentGames.value = (
                                (response?.data ?? []) as unknown as Game[]
                            )
                                .filter((row) => row.status === 'STATUS_FINAL')
                                .slice(0, 5);
                        }),
                );
            }

            if (awayTeamId) {
                supplemental.push(
                    api.teams
                        .metrics(options.sport, awayTeamId, {
                            init: { signal },
                        })
                        .then((response) => {
                            if (response) {
                                awayMetrics.value = resolveMetrics(
                                    response as unknown as ApiEnvelope<
                                        TeamMetric | TeamMetric[] | null
                                    >,
                                );
                            }
                        }),
                    api.teams
                        .games(options.sport, awayTeamId, {
                            query: { per_page: 25 },
                            init: { signal },
                        })
                        .then((response) => {
                            awayRecentGames.value = (
                                (response?.data ?? []) as unknown as Game[]
                            )
                                .filter((row) => row.status === 'STATUS_FINAL')
                                .slice(0, 5);
                        }),
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
                            awayTrends.value =
                                (response?.data as TeamTrendData | undefined) ??
                                null;
                        }),
                );
            }

            await Promise.allSettled(supplemental);
        } catch (e) {
            if (e instanceof DOMException && e.name === 'AbortError') return;
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            loading.value = false;
            trendsLoading.value = false;
        }
    };

    onMounted(load);
    onBeforeUnmount(() => requestController?.abort());

    return {
        game,
        homeTeam,
        awayTeam,
        prediction,
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
