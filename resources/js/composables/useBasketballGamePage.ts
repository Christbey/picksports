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

const normalizeDepthChartContext = (
    rawContext: unknown,
): PredictionSummary['depth_chart_context'] => {
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

const normalizeBasketballStats = (
    stats: TeamStatsEntry | null,
): BasketballTeamStats | null => {
    if (!stats) return null;
    const source = stats as Record<string, unknown>;
    return {
        team_type:
            typeof source.team_type === 'string' ? source.team_type : undefined,
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

const normalizePrediction = (
    rawPrediction: unknown,
): PredictionSummary | null => {
    if (!rawPrediction || typeof rawPrediction !== 'object') return null;
    const source = rawPrediction as Record<string, unknown>;
    if (
        source.home_win_probability === undefined ||
        source.away_win_probability === undefined
    )
        return null;

    const confidenceScore = toOptionalNumber(source.confidence_score);
    const confidenceLevel =
        typeof source.confidence_level === 'string'
            ? source.confidence_level
            : confidenceScore === null
              ? 'unavailable'
              : confidenceScore >= 75
                ? 'high'
                : confidenceScore >= 60
                  ? 'medium'
                  : 'low';

    const rawNarrative = source.narrative;
    const narrative =
        rawNarrative && typeof rawNarrative === 'object'
            ? {
                  summary:
                      typeof (rawNarrative as Record<string, unknown>)
                          .summary === 'string'
                          ? ((rawNarrative as Record<string, unknown>)
                                .summary as string)
                          : '',
                  key_points: Array.isArray(
                      (rawNarrative as Record<string, unknown>).key_points,
                  )
                      ? (
                            (rawNarrative as Record<string, unknown>)
                                .key_points as unknown[]
                        )
                            .map((point) => String(point))
                            .filter((point) => point.length > 0)
                      : [],
                  risk_note:
                      typeof (rawNarrative as Record<string, unknown>)
                          .risk_note === 'string'
                          ? ((rawNarrative as Record<string, unknown>)
                                .risk_note as string)
                          : '',
                  generated_by:
                      typeof (rawNarrative as Record<string, unknown>)
                          .generated_by === 'string'
                          ? ((rawNarrative as Record<string, unknown>)
                                .generated_by as string)
                          : '',
                  social_caption:
                      typeof (rawNarrative as Record<string, unknown>)
                          .social_caption === 'string'
                          ? ((rawNarrative as Record<string, unknown>)
                                .social_caption as string)
                          : null,
                  betting_plan: (() => {
                      const plan = (rawNarrative as Record<string, unknown>)
                          .betting_plan;
                      if (!plan || typeof plan !== 'object') return null;

                      const betPick =
                          typeof (plan as Record<string, unknown>).bet_pick ===
                          'string'
                              ? ((plan as Record<string, unknown>)
                                    .bet_pick as string)
                              : '';
                      const reasoning =
                          typeof (plan as Record<string, unknown>).reasoning ===
                          'string'
                              ? ((plan as Record<string, unknown>)
                                    .reasoning as string)
                              : '';

                      if (betPick !== '' && reasoning !== '') {
                          return {
                              bet_pick: betPick,
                              reasoning,
                              classification:
                                  typeof (plan as Record<string, unknown>)
                                      .classification === 'string'
                                      ? ((plan as Record<string, unknown>)
                                            .classification as string)
                                      : null,
                              for_bet: Array.isArray(
                                  (plan as Record<string, unknown>).for_bet,
                              )
                                  ? (
                                        (plan as Record<string, unknown>)
                                            .for_bet as unknown[]
                                    )
                                        .map((item) => String(item))
                                        .filter((item) => item.length > 0)
                                  : [],
                              against_bet: Array.isArray(
                                  (plan as Record<string, unknown>).against_bet,
                              )
                                  ? (
                                        (plan as Record<string, unknown>)
                                            .against_bet as unknown[]
                                    )
                                        .map((item) => String(item))
                                        .filter((item) => item.length > 0)
                                  : [],
                              pass_reasons: Array.isArray(
                                  (plan as Record<string, unknown>)
                                      .pass_reasons,
                              )
                                  ? (
                                        (plan as Record<string, unknown>)
                                            .pass_reasons as unknown[]
                                    )
                                        .map((item) => String(item))
                                        .filter((item) => item.length > 0)
                                  : [],
                              reason_codes: Array.isArray(
                                  (plan as Record<string, unknown>)
                                      .reason_codes,
                              )
                                  ? (
                                        (plan as Record<string, unknown>)
                                            .reason_codes as unknown[]
                                    )
                                        .map((item) => String(item))
                                        .filter((item) => item.length > 0)
                                  : [],
                          };
                      }

                      const legacySpreadLean =
                          typeof (plan as Record<string, unknown>)
                              .spread_lean === 'string'
                              ? ((plan as Record<string, unknown>)
                                    .spread_lean as string)
                              : '';
                      const legacyMoneylineHedge =
                          typeof (plan as Record<string, unknown>)
                              .moneyline_hedge === 'string'
                              ? ((plan as Record<string, unknown>)
                                    .moneyline_hedge as string)
                              : '';

                      if (
                          legacySpreadLean === '' ||
                          legacyMoneylineHedge === ''
                      ) {
                          return null;
                      }

                      return {
                          bet_pick: legacySpreadLean,
                          reasoning: legacyMoneylineHedge,
                      };
                  })(),
                  context_layer:
                      (rawNarrative as Record<string, unknown>).context_layer &&
                      typeof (rawNarrative as Record<string, unknown>)
                          .context_layer === 'object'
                          ? ((rawNarrative as Record<string, unknown>)
                                .context_layer as Record<string, unknown>)
                          : null,
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
        depth_chart_context: normalizeDepthChartContext(
            source.depth_chart_context,
        ),
    };
};

export function useBasketballGamePage(options: UseBasketballGamePageOptions) {
    const {
        game,
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
        const sampleSize =
            homeTrends.value?.sample_size ||
            awayTrends.value?.sample_size ||
            20;
        return options.subtitleText
            ? options.subtitleText(sampleSize)
            : `Based on current season form (${sampleSize} games before this matchup)`;
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
