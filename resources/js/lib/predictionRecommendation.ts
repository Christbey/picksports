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

export interface MarketAwareProjection {
    status?: 'tracking_only' | string | null;
    label?: string | null;
    is_bet?: false | boolean | null;
    is_lean?: false | boolean | null;
    model_probability?: number | null;
    market_probability?: number | null;
    blended_probability?: number | null;
    home_model_probability?: number | null;
    away_model_probability?: number | null;
    home_market_probability?: number | null;
    away_market_probability?: number | null;
    home_blended_probability?: number | null;
    away_blended_probability?: number | null;
    blend?: {
        model_weight?: number | null;
        market_weight?: number | null;
        version?: string | null;
        [key: string]: unknown;
    } | null;
    model_pick?: ProjectionPick | null;
    market_pick?: ProjectionPick | null;
    projection_pick?: ProjectionPick | null;
    agreement_status?:
        | 'agrees'
        | 'disagrees'
        | 'market_missing'
        | 'model_missing'
        | 'unknown'
        | string
        | null;
    point_in_time_status?: 'safe' | 'unsafe' | string | null;
    point_in_time_reasons?: string[];
    risk_label?: string | null;
    reason?: string | null;
    market_odds?: {
        home_price?: number | null;
        away_price?: number | null;
        source?: string | null;
        snapshot_id?: number | string | null;
        captured_at?: string | null;
        [key: string]: unknown;
    } | null;
}

interface ProjectionPick {
    side?: 'home' | 'away' | string | null;
    team_id?: number | string | null;
    team_abbreviation?: string | null;
    label?: string | null;
}

export interface PredictionWithRecommendation {
    recommendation?: PredictionRecommendation | null;
    public_recommendation?:
        | PredictionRecommendation
        | Record<string, unknown>
        | null;
    market_aware_projection?: MarketAwareProjection | null;
}

type OptionalPrediction = PredictionWithRecommendation | null | undefined;

export function getPredictionRecommendation(
    prediction: OptionalPrediction,
): PredictionRecommendation | null {
    const recommendation = prediction?.recommendation ?? null;

    return recommendation?.public ?? recommendation;
}

export function pregameRecommendation(
    prediction: OptionalPrediction,
): PredictionRecommendation | null {
    const recommendation = prediction?.recommendation ?? null;

    return (
        recommendation?.public ??
        recommendation?.pregame_recommendation ??
        recommendation
    );
}

export function candidateRecommendation(
    prediction: OptionalPrediction,
): PredictionRecommendation | null {
    const recommendation = prediction?.recommendation ?? null;

    return (
        recommendation?.candidate ??
        recommendation?.candidate_recommendation ??
        recommendation?.pregame_recommendation ??
        null
    );
}

export function promotionStatus(prediction: OptionalPrediction) {
    return prediction?.recommendation?.promotion ?? null;
}

export function isPromotionBlocked(prediction: OptionalPrediction): boolean {
    return promotionStatus(prediction)?.status === 'blocked';
}

export function isBetRecommendation(prediction: OptionalPrediction): boolean {
    const recommendation = getPredictionRecommendation(prediction);

    return (
        recommendation?.recommendation_type === 'bet' &&
        recommendation.is_bet === true &&
        recommendation.prediction_phase === 'pregame'
    );
}

export function isLeanRecommendation(prediction: OptionalPrediction): boolean {
    const recommendation = getPredictionRecommendation(prediction);

    return (
        recommendation?.recommendation_type === 'lean' &&
        recommendation.prediction_phase === 'pregame'
    );
}

export function isLiveMonitor(prediction: OptionalPrediction): boolean {
    const recommendation = getPredictionRecommendation(prediction);

    return recommendation?.recommendation_type === 'monitor';
}
