import { computed, onMounted, ref } from 'vue';
import { fetchJson } from '@/composables/useApiClient';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { flattenApiV2Stats } from '@/composables/useApiV2StatsAdapter';
import { formatDateLong, useGameStatus } from '@/composables/useFormatters';
import {
    calculatePercentage,
    parseBroadcastNetworks,
    parseLinescores,
} from '@/composables/useGameDataUtils';
import { useTeamTrends } from '@/composables/useTeamTrends';
import type {
    LivePredictionData,
    NflPageGame,
    NflPagePrediction,
    NflPageTeam,
    NflTeamStats,
    RecentGameListItem,
    TeamTrendData,
} from '@/types';

const fallbackGame = (gameId: number): NflPageGame => ({
    id: gameId,
    status: 'STATUS_SCHEDULED',
    game_date: null,
    away_score: null,
    home_score: null,
    home_team_id: 0,
    away_team_id: 0,
    season: 0,
    season_type: '',
    week: 0,
    game_time: '',
    venue: '',
});

const toNumber = (value: unknown): number => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const toOptionalNumber = (value: unknown): number | null => {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

const normalizeDepthChartContext = (
    rawContext: unknown,
): NflPagePrediction['depth_chart_context'] => {
    if (!rawContext || typeof rawContext !== 'object') return null;

    const source = rawContext as Record<string, unknown>;
    const type =
        source.type === 'injury_weighting' || source.type === 'starter_fallback'
            ? source.type
            : null;

    if (!type) return null;

    return {
        type,
        applied:
            typeof source.applied === 'boolean' ? source.applied : undefined,
        home_out_weighted: toOptionalNumber(source.home_out_weighted),
        away_out_weighted: toOptionalNumber(source.away_out_weighted),
        home_questionable_weighted: toOptionalNumber(
            source.home_questionable_weighted,
        ),
        away_questionable_weighted: toOptionalNumber(
            source.away_questionable_weighted,
        ),
        spread_adjustment: toOptionalNumber(source.spread_adjustment),
        total_adjustment: toOptionalNumber(source.total_adjustment),
        win_probability_adjustment: toOptionalNumber(
            source.win_probability_adjustment,
        ),
        injury_model_source:
            typeof source.injury_model_source === 'string'
                ? source.injury_model_source
                : null,
        injury_spread_model_source:
            typeof source.injury_spread_model_source === 'string'
                ? source.injury_spread_model_source
                : null,
        injury_total_model_source:
            typeof source.injury_total_model_source === 'string'
                ? source.injury_total_model_source
                : null,
        home_pitcher_source:
            typeof source.home_pitcher_source === 'string'
                ? source.home_pitcher_source
                : null,
        away_pitcher_source:
            typeof source.away_pitcher_source === 'string'
                ? source.away_pitcher_source
                : null,
        home_depth_chart_fallback_used:
            typeof source.home_depth_chart_fallback_used === 'boolean'
                ? source.home_depth_chart_fallback_used
                : undefined,
        away_depth_chart_fallback_used:
            typeof source.away_depth_chart_fallback_used === 'boolean'
                ? source.away_depth_chart_fallback_used
                : undefined,
        probable_pitcher_injury_applied:
            typeof source.probable_pitcher_injury_applied === 'boolean'
                ? source.probable_pitcher_injury_applied
                : undefined,
    };
};

const normalizeNarrative = (
    rawNarrative: unknown,
): NflPagePrediction['narrative'] => {
    if (!rawNarrative || typeof rawNarrative !== 'object') return null;

    const source = rawNarrative as Record<string, unknown>;
    const plan = source.betting_plan;

    return {
        summary: typeof source.summary === 'string' ? source.summary : '',
        key_points: Array.isArray(source.key_points)
            ? source.key_points
                  .map((point) => String(point))
                  .filter((point) => point.length > 0)
            : [],
        risk_note: typeof source.risk_note === 'string' ? source.risk_note : '',
        generated_by:
            typeof source.generated_by === 'string' ? source.generated_by : '',
        social_caption:
            typeof source.social_caption === 'string'
                ? source.social_caption
                : null,
        betting_plan:
            plan && typeof plan === 'object'
                ? {
                      bet_pick:
                          typeof (plan as Record<string, unknown>).bet_pick ===
                          'string'
                              ? ((plan as Record<string, unknown>)
                                    .bet_pick as string)
                              : '',
                      reasoning:
                          typeof (plan as Record<string, unknown>).reasoning ===
                          'string'
                              ? ((plan as Record<string, unknown>)
                                    .reasoning as string)
                              : '',
                  }
                : null,
    };
};

const normalizePrediction = (
    rawPrediction: unknown,
): NflPagePrediction | null => {
    if (!rawPrediction || typeof rawPrediction !== 'object') return null;

    const source = rawPrediction as Record<string, unknown>;

    return {
        id: toNumber(source.id),
        game_id: toNumber(source.game_id),
        home_elo: toNumber(source.home_elo),
        away_elo: toNumber(source.away_elo),
        predicted_spread: toNumber(source.predicted_spread),
        predicted_total: toOptionalNumber(source.predicted_total) ?? 0,
        win_probability: toNumber(source.win_probability),
        confidence_score: toNumber(source.confidence_score),
        betting_value: Array.isArray(source.betting_value)
            ? (source.betting_value as NflPagePrediction['betting_value'])
            : undefined,
        winner_correct:
            typeof source.winner_correct === 'boolean'
                ? source.winner_correct
                : null,
        actual_total: toOptionalNumber(source.actual_total),
        live_predicted_spread: toOptionalNumber(source.live_predicted_spread),
        live_win_probability: toOptionalNumber(source.live_win_probability),
        live_predicted_total: toOptionalNumber(source.live_predicted_total),
        live_seconds_remaining: toOptionalNumber(source.live_seconds_remaining),
        live_updated_at:
            typeof source.live_updated_at === 'string'
                ? source.live_updated_at
                : null,
        narrative: normalizeNarrative(source.narrative),
        depth_chart_context: normalizeDepthChartContext(
            source.depth_chart_context,
        ),
    };
};

export function useNflGamePage(gameId: number) {
    const api = useApiV2Client();
    const currentGame = ref<NflPageGame>(fallbackGame(gameId));
    const homeTeam = ref<NflPageTeam | null>(null);
    const awayTeam = ref<NflPageTeam | null>(null);
    const prediction = ref<NflPagePrediction | null>(null);
    const homeTeamStats = ref<NflTeamStats | null>(null);
    const awayTeamStats = ref<NflTeamStats | null>(null);
    const homeRecentGames = ref<RecentGameListItem[]>([]);
    const awayRecentGames = ref<RecentGameListItem[]>([]);
    const homeTrends = ref<TeamTrendData | null>(null);
    const awayTrends = ref<TeamTrendData | null>(null);
    const loading = ref(true);
    const error = ref<string | null>(null);

    const gameStatus = useGameStatus(() => currentGame.value.status);
    const formatDate = (dateString: string, timeString?: string): string => {
        void timeString;
        return formatDateLong(dateString);
    };
    const homeLinescores = computed(() =>
        parseLinescores(currentGame.value.home_linescores),
    );
    const awayLinescores = computed(() =>
        parseLinescores(currentGame.value.away_linescores),
    );
    const broadcastNetworks = computed(() =>
        parseBroadcastNetworks(currentGame.value.broadcast_networks),
    );
    const weekLabel = computed(() => {
        if (!currentGame.value.week || !currentGame.value.season_type)
            return '';

        const seasonType = String(currentGame.value.season_type);

        if (seasonType === 'Regular Season' || seasonType === '2') {
            return `Week ${currentGame.value.week}`;
        }

        if (seasonType === 'Preseason' || seasonType === '1') {
            return `Preseason Week ${currentGame.value.week}`;
        }

        const playoffRounds: Record<number, string> = {
            1: 'Wild Card',
            2: 'Divisional',
            3: 'Conference Championship',
            5: 'Super Bowl',
        };

        return (
            playoffRounds[currentGame.value.week] ||
            `Playoff Week ${currentGame.value.week}`
        );
    });

    const hasLivePrediction = computed(
        () =>
            prediction.value?.live_win_probability !== null &&
            prediction.value?.live_win_probability !== undefined,
    );
    const livePredictionData = computed((): LivePredictionData | undefined => {
        if (!hasLivePrediction.value || !prediction.value) return undefined;
        return {
            isLive: true,
            homeScore: currentGame.value.home_score,
            awayScore: currentGame.value.away_score,
            status: currentGame.value.status,
            liveWinProbability: prediction.value.live_win_probability as
                | number
                | null,
            livePredictedSpread: prediction.value.live_predicted_spread as
                | number
                | null,
            livePredictedTotal: prediction.value.live_predicted_total as
                | number
                | null,
            liveSecondsRemaining: prediction.value.live_seconds_remaining,
            preGameWinProbability: Number(prediction.value.win_probability),
            preGamePredictedSpread: Number(prediction.value.predicted_spread),
            preGamePredictedTotal: Number(prediction.value.predicted_total),
        };
    });

    const trendsSubtitle = computed(
        () =>
            `${currentGame.value.season} Season (${homeTrends.value?.sample_size || awayTrends.value?.sample_size || 0} games)`,
    );

    const {
        topMatchupEdges,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatTrendCategoryName: formatCategoryName,
    } = useTeamTrends(homeTrends, awayTrends);

    const getNumericRecord = (
        games: RecentGameListItem[],
        teamId: number,
    ): string => {
        const wins = games.filter((g) => {
            const isHome = g.home_team_id === teamId;
            const teamScore = isHome ? g.home_score : g.away_score;
            const oppScore = isHome ? g.away_score : g.home_score;
            return teamScore && oppScore && teamScore > oppScore;
        }).length;
        const losses = games.length - wins;
        return `${wins}-${losses}`;
    };

    const load = async () => {
        try {
            loading.value = true;
            error.value = null;

            const [gameData, predictionData, teamStatsData] = await Promise.all(
                [
                    fetchJson<{ data: NflPageGame }>(
                        `/api/v1/nfl/games/${gameId}`,
                    ),
                    fetchJson<{
                        data: NflPagePrediction | NflPagePrediction[];
                    }>(`/api/v1/nfl/games/${gameId}/prediction`),
                    api.stats.teams('nfl', {
                        query: { game_id: gameId, per_page: 100 },
                    }),
                ],
            );

            if (gameData?.data) {
                const fullGame = gameData.data;
                currentGame.value = fullGame;
                const fallbackGame = fullGame as NflPageGame & {
                    homeTeam?: NflPageGame['home_team'];
                    awayTeam?: NflPageGame['away_team'];
                };
                homeTeam.value =
                    fullGame.home_team || fallbackGame.homeTeam || null;
                awayTeam.value =
                    fullGame.away_team || fallbackGame.awayTeam || null;

                if (fullGame.home_team?.id || fallbackGame.homeTeam?.id) {
                    const homeTeamId =
                        fullGame.home_team?.id || fallbackGame.homeTeam?.id;
                    const [homeGamesData, homeTrendsData] = await Promise.all([
                        api.teams.games('nfl', homeTeamId),
                        api.teams.trends('nfl', homeTeamId, {
                            query: {
                                games: 'season',
                                season: currentGame.value.season,
                                before_date: currentGame.value.game_date,
                            },
                        }),
                    ]);
                    if (homeGamesData?.data) {
                        homeRecentGames.value = (homeGamesData.data || [])
                            .filter(
                                (g) =>
                                    g.status === 'STATUS_FINAL' &&
                                    g.id !== currentGame.value.id,
                            )
                            .slice(0, 5);
                    }
                    if (homeTrendsData?.data) {
                        homeTrends.value = homeTrendsData.data as TeamTrendData;
                    }
                }

                if (fullGame.away_team?.id || fallbackGame.awayTeam?.id) {
                    const awayTeamId =
                        fullGame.away_team?.id || fallbackGame.awayTeam?.id;
                    const [awayGamesData, awayTrendsData] = await Promise.all([
                        api.teams.games('nfl', awayTeamId),
                        api.teams.trends('nfl', awayTeamId, {
                            query: {
                                games: 'season',
                                season: currentGame.value.season,
                                before_date: currentGame.value.game_date,
                            },
                        }),
                    ]);
                    if (awayGamesData?.data) {
                        awayRecentGames.value = (awayGamesData.data || [])
                            .filter(
                                (g) =>
                                    g.status === 'STATUS_FINAL' &&
                                    g.id !== currentGame.value.id,
                            )
                            .slice(0, 5);
                    }
                    if (awayTrendsData?.data) {
                        awayTrends.value = awayTrendsData.data as TeamTrendData;
                    }
                }
            }

            if (predictionData?.data) {
                const raw = Array.isArray(predictionData.data)
                    ? (predictionData.data[0] ?? null)
                    : predictionData.data;
                prediction.value = normalizePrediction(raw);
            }

            if (teamStatsData?.data) {
                const stats = flattenApiV2Stats(
                    teamStatsData.data,
                ) as NflTeamStats[];
                homeTeamStats.value =
                    stats.find((s) => s.team_type === 'home') || null;
                awayTeamStats.value =
                    stats.find((s) => s.team_type === 'away') || null;
            }
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            loading.value = false;
        }
    };

    onMounted(load);

    return {
        game: currentGame,
        homeTeam,
        awayTeam,
        prediction,
        homeTeamStats,
        awayTeamStats,
        homeRecentGames,
        awayRecentGames,
        homeTrends,
        awayTrends,
        loading,
        error,
        gameStatus,
        formatDate,
        homeLinescores,
        awayLinescores,
        broadcastNetworks,
        weekLabel,
        hasLivePrediction,
        livePredictionData,
        trendsSubtitle,
        topMatchupEdges,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatCategoryName,
        getNumericRecord,
        calculatePercentage,
    };
}
