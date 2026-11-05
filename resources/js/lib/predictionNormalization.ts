/** These coercions retain the legacy prediction payload contract, including booleans and whitespace. */
export const optionalPredictionNumber = (value: unknown): number | null => {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};
export const predictionNumber = (value: unknown): number => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};
const stringList = (value: unknown): string[] =>
    Array.isArray(value)
        ? value.map((item) => String(item)).filter((item) => item.length > 0)
        : [];
const object = (value: unknown): Record<string, unknown> | null =>
    value && typeof value === 'object'
        ? (value as Record<string, unknown>)
        : null;
export function normalizePredictionConfidence(source: Record<string, unknown>) {
    const confidenceScore = optionalPredictionNumber(source.confidence_score);
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
    const context = object(source.confidence_context);
    const confidenceContext = context
        ? {
              label: typeof context.label === 'string' ? context.label : null,
              tier: typeof context.tier === 'string' ? context.tier : null,
              model_level:
                  typeof context.model_level === 'string'
                      ? context.model_level
                      : typeof context.raw_level === 'string'
                        ? context.raw_level
                        : null,
              reason_codes: stringList(context.reason_codes),
              sample_games: optionalPredictionNumber(context.sample_games),
          }
        : null;
    return { confidenceScore, confidenceLevel, confidenceContext };
}
export function normalizeNarrativeContent(value: unknown) {
    const source = object(value);
    if (!source) return null;
    return {
        summary: typeof source.summary === 'string' ? source.summary : '',
        key_points: stringList(source.key_points),
        risk_note: typeof source.risk_note === 'string' ? source.risk_note : '',
        generated_by:
            typeof source.generated_by === 'string' ? source.generated_by : '',
        social_caption:
            typeof source.social_caption === 'string'
                ? source.social_caption
                : null,
    };
}
export function normalizeModernBettingPlan(value: unknown) {
    const plan = object(value);
    if (
        !plan ||
        typeof plan.bet_pick !== 'string' ||
        plan.bet_pick === '' ||
        typeof plan.reasoning !== 'string' ||
        plan.reasoning === ''
    )
        return null;
    return {
        bet_pick: plan.bet_pick,
        reasoning: plan.reasoning,
        classification:
            typeof plan.classification === 'string'
                ? plan.classification
                : null,
        for_bet: stringList(plan.for_bet),
        against_bet: stringList(plan.against_bet),
        pass_reasons: stringList(plan.pass_reasons),
        reason_codes: stringList(plan.reason_codes),
    };
}
export function normalizeBasketballBettingPlan(value: unknown) {
    const modern = normalizeModernBettingPlan(value);
    if (modern) return modern;
    const plan = object(value);
    return plan &&
        typeof plan.spread_lean === 'string' &&
        plan.spread_lean !== '' &&
        typeof plan.moneyline_hedge === 'string' &&
        plan.moneyline_hedge !== ''
        ? { bet_pick: plan.spread_lean, reasoning: plan.moneyline_hedge }
        : null;
}
