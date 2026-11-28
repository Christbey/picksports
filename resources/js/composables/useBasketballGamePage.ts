import { finiteValue as toOptionalNumber } from '@/lib/gameOutcome';
import type { BasketballTeamStats } from '@/lib/basketballBoxScore';
import {
    normalizePredictionConfidence,
    normalizeNarrativeContent,
    normalizeBasketballBettingPlan,
} from '@/lib/predictionNormalization';
import { predictionHomeMargin } from '@/lib/predictionSpread';
import { normalizeDepthChartContext } from '@/lib/depthChartContext';
import { computed } from 'vue';
import { useDetailedGameData } from '@/composables/useDetailedGameData';
import { formatDateLong, useGameStatus } from '@/composables/useFormatters';
import {
    getRecentForm,
    parseBroadcastNetworks,
    parseLinescores,
} from '@/composables/useGameDataUtils';
import { useTeamTrends } from '@/composables/useTeamTrends';
import type {
    ApiEnvelope,
    PredictionSummary,
    TeamMetric,
    TeamStatsEntry,
    TopPerformer,
    ValueSignalSummary,
} from '@/types';

interface UseBasketballGamePageOptions {
    sport: 'nba' | 'cbb' | 'wnba' | 'wcbb';
    gameId: number;
    sortTopPerformers?: (players: TopPerformer[]) => TopPerformer[];
    metricFromResponse?: (
        payload: ApiEnvelope<TeamMetric | TeamMetric[] | null>,
    ) => TeamMetric | null;
    subtitleText?: (sampleSize: number) => string;
}

const normalizeBasketballStats = (
    stats: TeamStatsEntry | null,
): BasketballTeamStats | null => {
    if (!stats) return null;
    const source = stats as Record<string, unknown>;
    return {
        team_type:
            typeof source.team_type === 'string' ? source.team_type : undefined,
        field_goals_made: toOptionalNumber(source.field_goals_made),
        field_goals_attempted: toOptionalNumber(source.field_goals_attempted),
        three_point_made: toOptionalNumber(source.three_point_made),
        three_point_attempted: toOptionalNumber(source.three_point_attempted),
        free_throws_made: toOptionalNumber(source.free_throws_made),
        free_throws_attempted: toOptionalNumber(source.free_throws_attempted),
        rebounds: toOptionalNumber(source.rebounds),
        assists: toOptionalNumber(source.assists),
        turnovers: toOptionalNumber(source.turnovers),
        steals: toOptionalNumber(source.steals),
        blocks: toOptionalNumber(source.blocks),
        points_in_paint: toOptionalNumber(source.points_in_paint),
        fast_break_points: toOptionalNumber(source.fast_break_points),
    };
};

export const normalizePrediction = (
    rawPrediction: unknown,
): PredictionSummary | null => {
    if (!rawPrediction || typeof rawPrediction !== 'object') return null;
    const source = rawPrediction as Record<string, unknown>;
    if (
        ![
            'home_win_probability',
            'away_win_probability',
            'predicted_spread',
            'predicted_total',
        ].some((key) => key in source)
    )
        return null;

    const { confidenceScore, confidenceLevel, confidenceContext } =
        normalizePredictionConfidence(source);
    const rawNarrative = source.narrative;
    const content = normalizeNarrativeContent(rawNarrative);
    const narrativeSource = rawNarrative as Record<string, unknown> | null;
    const narrative = content
        ? {
              ...content,
              betting_plan: normalizeBasketballBettingPlan(
                  narrativeSource?.betting_plan,
              ),
              context_layer:
                  narrativeSource?.context_layer &&
                  typeof narrativeSource.context_layer === 'object'
                      ? (narrativeSource.context_layer as Record<
                            string,
                            unknown
                        >)
                      : null,
          }
        : null;

    const valueSignal = source.value_signal as ValueSignalSummary | null;
    return {
        sport: typeof source.sport === 'string' ? source.sport : undefined,
        spread_convention: 'home_margin',
        audit_context:
            source.audit_context && typeof source.audit_context === 'object'
                ? (source.audit_context as PredictionSummary['audit_context'])
                : null,
        evidence:
            source.evidence && typeof source.evidence === 'object'
                ? (source.evidence as PredictionSummary['evidence'])
                : null,
        value_signal:
            valueSignal?.decision_policy_version === 'wcbb-canonical-spread-v1'
                ? valueSignal
                : null,
        home_win_probability: toOptionalNumber(source.home_win_probability),
        away_win_probability: toOptionalNumber(source.away_win_probability),
        predicted_spread: predictionHomeMargin(source),
        predicted_total: toOptionalNumber(source.predicted_total),
        confidence_level: confidenceLevel,
        confidence_context: confidenceContext,
        confidence_score: confidenceScore,
        narrative,
        depth_chart_context: normalizeDepthChartContext(
            source.depth_chart_context,
            toOptionalNumber,
        ),
    };
};

export function useBasketballGamePage(options: UseBasketballGamePageOptions) {
    const {
        game,
        homeTeam,
        awayTeam,
        prediction: rawPrediction,
        historicalPrediction,
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
        gameId: options.gameId,
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

    const gameStatus = useGameStatus(
        () => game.value?.status ?? 'STATUS_SCHEDULED',
    );
    const prediction = computed(() => normalizePrediction(rawPrediction.value));
    const homeTeamStats = computed(() =>
        normalizeBasketballStats(rawHomeTeamStats.value),
    );
    const awayTeamStats = computed(() =>
        normalizeBasketballStats(rawAwayTeamStats.value),
    );
    const formatDate = (dateString: string | null): string =>
        formatDateLong(dateString);
    const broadcastNetworks = computed(() =>
        parseBroadcastNetworks(game.value?.broadcast_networks ?? null),
    );
    const homeLinescores = computed(() =>
        parseLinescores(game.value?.home_linescores ?? null),
    );
    const awayLinescores = computed(() =>
        parseLinescores(game.value?.away_linescores ?? null),
    );
    const homeRecentForm = computed(() =>
        homeTeam.value
            ? getRecentForm(homeRecentGames.value, homeTeam.value.id)
            : '',
    );
    const awayRecentForm = computed(() =>
        awayTeam.value
            ? getRecentForm(awayRecentGames.value, awayTeam.value.id)
            : '',
    );
    const trendsSubtitle = computed(() => {
        const home = homeTrends.value?.sample_size ?? null;
        const away = awayTrends.value?.sample_size ?? null;
        if (home === null || away === null)
            return `Current-season trend evidence: home ${home ?? 'unavailable'} / away ${away ?? 'unavailable'} games before this matchup`;
        if (options.subtitleText && home === away)
            return options.subtitleText(home);
        return `Current-season trend evidence: home ${home} / away ${away} games before this matchup`;
    });

    return {
        homeTeam,
        awayTeam,
        prediction,
        historicalPrediction,
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
        game,
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
