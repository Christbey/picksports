import type { PredictionSummary } from '@/types';

export const normalizeDepthChartContext = (
    rawContext: unknown,
    toOptionalNumber: (value: unknown) => number | null,
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
