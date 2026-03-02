import { computed } from 'vue';
import { useDetailedGameData } from '@/composables/useDetailedGameData';
import { formatDateLong, useGameStatus } from '@/composables/useFormatters';
import { getRecentForm, parseBroadcastNetworks, parseLinescores } from '@/composables/useGameDataUtils';
import { useTeamTrends } from '@/composables/useTeamTrends';
import type { ApiEnvelope, Game, PredictionSummary, TeamMetric, TeamStatsEntry, TopPerformer } from '@/types';

interface UseBasketballGamePageOptions {
    sport: 'nba' | 'cbb' | 'wnba' | 'wcbb';
    game: Game;
    sortTopPerformers?: (players: TopPerformer[]) => TopPerformer[];
    metricFromResponse?: (payload: ApiEnvelope<TeamMetric | TeamMetric[] | null>) => TeamMetric | null;
    subtitleText?: (sampleSize: number) => string;
}

interface BasketballTeamStats {
    team_type?: 'home' | 'away' | string;
    field_goals_made: number;
    field_goals_attempted: number;
    three_point_made: number;
    three_point_attempted: number;
    free_throws_made: number;
    free_throws_attempted: number;
    rebounds: number;
    assists: number;
    turnovers: number;
    steals: number;
    blocks: number;
    points_in_paint?: number | null;
    fast_break_points?: number | null;
}

const toNumber = (value: unknown): number => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const toOptionalNumber = (value: unknown): number | null => {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

const normalizeBasketballStats = (stats: TeamStatsEntry | null): BasketballTeamStats | null => {
    if (!stats) return null;
    const source = stats as Record<string, unknown>;
    return {
        team_type: typeof source.team_type === 'string' ? source.team_type : undefined,
        field_goals_made: toNumber(source.field_goals_made),
        field_goals_attempted: toNumber(source.field_goals_attempted),
        three_point_made: toNumber(source.three_point_made),
        three_point_attempted: toNumber(source.three_point_attempted),
        free_throws_made: toNumber(source.free_throws_made),
        free_throws_attempted: toNumber(source.free_throws_attempted),
        rebounds: toNumber(source.rebounds),
        assists: toNumber(source.assists),
        turnovers: toNumber(source.turnovers),
        steals: toNumber(source.steals),
        blocks: toNumber(source.blocks),
        points_in_paint: toOptionalNumber(source.points_in_paint),
        fast_break_points: toOptionalNumber(source.fast_break_points),
    };
};

const normalizePrediction = (rawPrediction: unknown): PredictionSummary | null => {
    if (!rawPrediction || typeof rawPrediction !== 'object') return null;
    const source = rawPrediction as Record<string, unknown>;
    if (source.home_win_probability === undefined || source.away_win_probability === undefined) return null;

    const confidenceScore = toOptionalNumber(source.confidence_score);
    const confidenceLevel = typeof source.confidence_level === 'string'
        ? source.confidence_level
        : confidenceScore === null
            ? 'unavailable'
            : confidenceScore >= 75
                ? 'high'
                : confidenceScore >= 60
                    ? 'medium'
                    : 'low';

    const rawNarrative = source.narrative;
    const narrative = rawNarrative && typeof rawNarrative === 'object'
        ? {
            summary: typeof (rawNarrative as Record<string, unknown>).summary === 'string'
                ? (rawNarrative as Record<string, unknown>).summary as string
                : '',
            key_points: Array.isArray((rawNarrative as Record<string, unknown>).key_points)
                ? ((rawNarrative as Record<string, unknown>).key_points as unknown[])
                    .map((point) => String(point))
                    .filter((point) => point.length > 0)
                : [],
            risk_note: typeof (rawNarrative as Record<string, unknown>).risk_note === 'string'
                ? (rawNarrative as Record<string, unknown>).risk_note as string
                : '',
            generated_by: typeof (rawNarrative as Record<string, unknown>).generated_by === 'string'
                ? (rawNarrative as Record<string, unknown>).generated_by as string
                : '',
            social_caption: typeof (rawNarrative as Record<string, unknown>).social_caption === 'string'
                ? (rawNarrative as Record<string, unknown>).social_caption as string
                : null,
            betting_plan: (() => {
                const plan = (rawNarrative as Record<string, unknown>).betting_plan;
                if (!plan || typeof plan !== 'object') return null;

                const betPick = typeof (plan as Record<string, unknown>).bet_pick === 'string'
                    ? (plan as Record<string, unknown>).bet_pick as string
                    : '';
                const reasoning = typeof (plan as Record<string, unknown>).reasoning === 'string'
                    ? (plan as Record<string, unknown>).reasoning as string
                    : '';

                if (betPick !== '' && reasoning !== '') {
                    return {
                        bet_pick: betPick,
                        reasoning,
                    };
                }

                const legacySpreadLean = typeof (plan as Record<string, unknown>).spread_lean === 'string'
                    ? (plan as Record<string, unknown>).spread_lean as string
                    : '';
                const legacyMoneylineHedge = typeof (plan as Record<string, unknown>).moneyline_hedge === 'string'
                    ? (plan as Record<string, unknown>).moneyline_hedge as string
                    : '';

                if (legacySpreadLean === '' || legacyMoneylineHedge === '') {
                    return null;
                }

                return {
                    bet_pick: legacySpreadLean,
                    reasoning: legacyMoneylineHedge,
                };
            })(),
        }
        : null;

    return {
        home_win_probability: toNumber(source.home_win_probability),
        away_win_probability: toNumber(source.away_win_probability),
        predicted_spread: toNumber(source.predicted_spread),
        predicted_total: toNumber(source.predicted_total),
        confidence_level: confidenceLevel,
        confidence_score: confidenceScore,
        narrative,
    };
};

export function useBasketballGamePage(options: UseBasketballGamePageOptions) {
    const {
        homeTeam,
        awayTeam,
        prediction: rawPrediction,
        homeMetrics,
        awayMetrics,
        homeTeamStats: rawHomeTeamStats,
        awayTeamStats: rawAwayTeamStats,
        topPerformers,
        homeRecentGames,
        awayRecentGames,
        homeTrends,
        awayTrends,
        trendsLoading,
        loading,
        error,
    } = useDetailedGameData({
        sport: options.sport,
        game: options.game,
        sortTopPerformers: options.sortTopPerformers,
        metricFromResponse: options.metricFromResponse,
    });

    const {
        topMatchupEdges,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatTrendCategoryName: formatCategoryName,
    } = useTeamTrends(homeTrends, awayTrends);

    const gameStatus = useGameStatus(() => options.game.status);
    const prediction = computed(() => normalizePrediction(rawPrediction.value));
    const homeTeamStats = computed(() => normalizeBasketballStats(rawHomeTeamStats.value));
    const awayTeamStats = computed(() => normalizeBasketballStats(rawAwayTeamStats.value));
    const formatDate = (dateString: string | null): string => formatDateLong(dateString);
    const broadcastNetworks = computed(() => parseBroadcastNetworks(options.game.broadcast_networks));
    const homeLinescores = computed(() => parseLinescores(options.game.home_linescores));
    const awayLinescores = computed(() => parseLinescores(options.game.away_linescores));
    const homeRecentForm = computed(() => (homeTeam.value ? getRecentForm(homeRecentGames.value, homeTeam.value.id) : ''));
    const awayRecentForm = computed(() => (awayTeam.value ? getRecentForm(awayRecentGames.value, awayTeam.value.id) : ''));
    const trendsSubtitle = computed(() => {
        const sampleSize = homeTrends.value?.sample_size || awayTrends.value?.sample_size || 20;
        return options.subtitleText ? options.subtitleText(sampleSize) : `Based on last ${sampleSize} games before this matchup`;
    });

    return {
        homeTeam,
        awayTeam,
        prediction,
        homeMetrics,
        awayMetrics,
        homeTeamStats,
        awayTeamStats,
        topPerformers,
        homeTrends,
        awayTrends,
        trendsLoading,
        loading,
        error,
        topMatchupEdges,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatCategoryName,
        gameStatus,
        formatDate,
        broadcastNetworks,
        homeLinescores,
        awayLinescores,
        homeRecentForm,
        awayRecentForm,
        trendsSubtitle,
    };
}
