import { onMounted, ref } from 'vue';
import { fetchJson } from '@/composables/useApiClient';
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

    const resolveMetrics =
        options.metricFromResponse ?? defaultMetricFromResponse;

    const load = async () => {
        try {
            loading.value = true;
            error.value = null;

            const [gameData, predictionData, teamStatsData, playerStatsData] =
                await Promise.all([
                    fetchJson<ApiEnvelope<Game>>(
                        `/api/v1/${options.sport}/games/${options.gameId}`,
                    ),
                    fetchJson<
                        ApiEnvelope<Prediction | Record<string, unknown> | null>
                    >(
                        `/api/v1/${options.sport}/games/${options.gameId}/prediction`,
                    ),
                    api.stats.teams(options.sport, {
                        query: { game_id: options.gameId, per_page: 100 },
                    }),
                    api.stats.players(options.sport, {
                        query: { game_id: options.gameId, per_page: 100 },
                    }),
                ]);

            const fullGame = gameData?.data;
            if (fullGame) {
                game.value = fullGame;
                homeTeam.value = fullGame.home_team ?? homeTeam.value;
                awayTeam.value = fullGame.away_team ?? awayTeam.value;
            }

            if (predictionData) {
                prediction.value = predictionData?.data ?? null;
            }

            if (teamStatsData) {
                const stats = flattenApiV2Stats(
                    teamStatsData?.data,
                ) as TeamStatsEntry[];
                homeTeamStats.value =
                    stats.find((s) => s.team_type === 'home') || null;
                awayTeamStats.value =
                    stats.find((s) => s.team_type === 'away') || null;
            }

            if (playerStatsData) {
                const players = flattenApiV2Stats(
                    playerStatsData?.data,
                ) as TopPerformer[];
                topPerformers.value = options.sortTopPerformers
                    ? options.sortTopPerformers(players)
                    : players.slice(0, 10);
            }

            const homeTeamId = homeTeam.value?.id ?? game.value?.home_team_id;
            const awayTeamId = awayTeam.value?.id ?? game.value?.away_team_id;

            const teamFetches: Array<{
                key: string;
                promise: Promise<ApiEnvelope<unknown> | null>;
            }> = [];

            if (homeTeamId) {
                teamFetches.push(
                    {
                        key: 'homeMetrics',
                        promise: fetchJson<
                            ApiEnvelope<TeamMetric | TeamMetric[] | null>
                        >(
                            `/api/v1/${options.sport}/teams/${homeTeamId}/metrics`,
                        ),
                    },
                    {
                        key: 'homeGames',
                        promise: fetchJson<ApiEnvelope<Game[]>>(
                            `/api/v1/${options.sport}/teams/${homeTeamId}/games`,
                        ),
                    },
                );
            }

            if (awayTeamId) {
                teamFetches.push(
                    {
                        key: 'awayMetrics',
                        promise: fetchJson<
                            ApiEnvelope<TeamMetric | TeamMetric[] | null>
                        >(
                            `/api/v1/${options.sport}/teams/${awayTeamId}/metrics`,
                        ),
                    },
                    {
                        key: 'awayGames',
                        promise: fetchJson<ApiEnvelope<Game[]>>(
                            `/api/v1/${options.sport}/teams/${awayTeamId}/games`,
                        ),
                    },
                );
            }

            if (teamFetches.length > 0) {
                for (const entry of teamFetches) {
                    const key = entry.key;
                    const payload = await entry.promise;
                    if (!payload) continue;

                    if (key === 'homeMetrics') {
                        homeMetrics.value = resolveMetrics(
                            payload as ApiEnvelope<
                                TeamMetric | TeamMetric[] | null
                            >,
                        );
                    }

                    if (key === 'awayMetrics') {
                        awayMetrics.value = resolveMetrics(
                            payload as ApiEnvelope<
                                TeamMetric | TeamMetric[] | null
                            >,
                        );
                    }

                    if (key === 'homeGames') {
                        homeRecentGames.value = (
                            (payload as ApiEnvelope<Game[]>).data || []
                        )
                            .filter((g: Game) => g.status === 'STATUS_FINAL')
                            .slice(0, 5);
                    }

                    if (key === 'awayGames') {
                        awayRecentGames.value = (
                            (payload as ApiEnvelope<Game[]>).data || []
                        )
                            .filter((g: Game) => g.status === 'STATUS_FINAL')
                            .slice(0, 5);
                    }
                }
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

                const [homeTrendsData, awayTrendsData] = await Promise.all([
                    fetchJson<TeamTrendData>(
                        `/api/v1/${options.sport}/teams/${homeTeamId}/trends?${trendQuery.toString()}`,
                    ),
                    fetchJson<TeamTrendData>(
                        `/api/v1/${options.sport}/teams/${awayTeamId}/trends?${trendQuery.toString()}`,
                    ),
                ]);

                if (homeTrendsData) {
                    homeTrends.value = homeTrendsData;
                }

                if (awayTrendsData) {
                    awayTrends.value = awayTrendsData;
                }

                trendsLoading.value = false;
            }
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            loading.value = false;
            trendsLoading.value = false;
        }
    };

    onMounted(load);

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
