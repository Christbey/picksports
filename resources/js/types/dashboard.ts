import type { BettingRecommendation } from './sports';

export interface DashboardPrediction {
    sport: string;
    game_id: number;
    game: string;
    game_time: string;
    home_team: string;
    away_team: string;
    win_probability: number;
    predicted_spread: number;
    predicted_total: number;
    home_logo: string;
    away_logo: string;
    betting_value?: BettingRecommendation[];
    // Live game data
    is_live?: boolean;
    is_final?: boolean;
    home_score?: number;
    away_score?: number;
    period?: number;
    inning?: number;
    game_clock?: string;
    inning_state?: string;
    status?: string;
    // Live prediction data
    live_win_probability?: number;
    live_predicted_spread?: number;
    live_predicted_total?: number;
    live_seconds_remaining?: number;
    live_outs_remaining?: number;
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
