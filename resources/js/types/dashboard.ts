import type {
    AiPredictionAnalysisSummary,
    BettingRecommendation,
    PredictionAnalysisSummary,
} from './sports';
import type {
    MarketAwareProjection,
    PredictionRecommendation,
} from '@/lib/predictionRecommendation';

export interface DashboardPrediction {
    spread_convention?: 'home_margin' | 'home_handicap';
    id: number | string;
    sport: string;
    game_id: number;
    season_type?: string | number | null;
    game: string;
    game_time: string | null;
    home_team: string;
    away_team: string;
    win_probability: number | null;
    predicted_spread: number | null;
    predicted_total: number | null;
    actual_total?: number | null;
    winner_correct?: boolean | null;
    dashboard_signal?: 'Model Bet' | null;
    home_logo: string;
    away_logo: string;
    home_color?: string | null;
    away_color?: string | null;
    betting_value?: BettingRecommendation[];
    betting_value_debug?: string | null;
    recommendation?: PredictionRecommendation | null;
    public_recommendation?: PredictionRecommendation | null;
    candidate_recommendation?: PredictionRecommendation | null;
    prediction_analysis?: PredictionAnalysisSummary | null;
    ai_analysis?: AiPredictionAnalysisSummary | null;
    market_aware_projection?: MarketAwareProjection | null;
    updated_at?: string | null;
    // Live game data
    is_live?: boolean;
    is_final?: boolean;
    home_score?: number | null;
    away_score?: number | null;
    period?: number | null;
    inning?: number | null;
    game_clock?: string | null;
    inning_state?: string | null;
    balls?: number | null;
    strikes?: number | null;
    outs?: number | null;
    status?: string;
    // Live prediction data
    live_win_probability?: number | null;
    live_predicted_spread?: number | null;
    live_predicted_total?: number | null;
    live_seconds_remaining?: number | null;
    live_outs_remaining?: number | null;
}

export interface DashboardSport {
    name: string;
    fullName: string;
    color: string;
    predictions: DashboardPrediction[];
}

export interface DashboardStats {
    total_predictions_today: number;
    total_games_today: number;
    healthcheck_status: string;
}
