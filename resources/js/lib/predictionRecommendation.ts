export interface PredictionRecommendation {
    recommendation_type?: string | null;
    market_type?: string | null;
    recommendation_strength?: string | null;
    is_bet?: boolean | null;
    is_visible?: boolean | null;
    prediction_phase?: string | null;
    pick_side?: string | null;
    team_id?: number | string | null;
    team_name?: string | null;
    model_probability?: number | null;
    market_price?: number | null;
    raw_implied_probability?: number | null;
    no_vig_implied_probability?: number | null;
    raw_edge?: number | null;
    no_vig_edge?: number | null;
    score?: number | null;
    reason_codes?: string[];
    risk_flags?: string[];
    no_bet_reason?: string | null;
    odds_updated_at?: string | null;
    odds_fresh?: boolean | null;
    block_reasons?: string[];
    public?: PredictionRecommendation | null;
    candidate?: PredictionRecommendation | null;
    candidate_recommendation?: PredictionRecommendation | null;
    promotion?: {
        status?: string | null;
        public_recommendation_type?: string | null;
        candidate_recommendation_type?: string | null;
        promotions_validated?: boolean | null;
        calibration_guard_enabled?: boolean | null;
        validated_for_promotion?: boolean | null;
        block_reasons?: string[];
        game_phase?: string | null;
    } | null;
    pregame_recommendation?: PredictionRecommendation | null;
    all_candidates?: PredictionRecommendation[];
}

export interface PredictionWithRecommendation {
    recommendation?: PredictionRecommendation | null;
}

export function getPredictionRecommendation(
    prediction: PredictionWithRecommendation,
): PredictionRecommendation | null {
    const recommendation = prediction.recommendation ?? null;

    return recommendation?.public ?? recommendation;
}

export function pregameRecommendation(
    prediction: PredictionWithRecommendation,
): PredictionRecommendation | null {
    const recommendation = prediction.recommendation ?? null;

    return recommendation?.public ?? recommendation?.pregame_recommendation ?? recommendation;
}

export function candidateRecommendation(
    prediction: PredictionWithRecommendation,
): PredictionRecommendation | null {
    const recommendation = prediction.recommendation ?? null;

    return (
        recommendation?.candidate ??
        recommendation?.candidate_recommendation ??
        recommendation?.pregame_recommendation ??
        null
    );
}

export function promotionStatus(prediction: PredictionWithRecommendation) {
    return prediction.recommendation?.promotion ?? null;
}

export function isPromotionBlocked(
    prediction: PredictionWithRecommendation,
): boolean {
    return promotionStatus(prediction)?.status === 'blocked';
}

export function isBetRecommendation(
    prediction: PredictionWithRecommendation,
): boolean {
    const recommendation = getPredictionRecommendation(prediction);

    return (
        recommendation?.recommendation_type === 'bet' &&
        recommendation.is_bet === true &&
        recommendation.prediction_phase === 'pregame'
    );
}

export function isLeanRecommendation(
    prediction: PredictionWithRecommendation,
): boolean {
    const recommendation = getPredictionRecommendation(prediction);

    return (
        recommendation?.recommendation_type === 'lean' &&
        recommendation.prediction_phase === 'pregame'
    );
}

export function isLiveMonitor(
    prediction: PredictionWithRecommendation,
): boolean {
    const recommendation = getPredictionRecommendation(prediction);

    return recommendation?.recommendation_type === 'monitor';
}
