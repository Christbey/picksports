export interface MarketEdge {
    edge_percent_points?: number | null;
    fair_odds?: number | null;
    market_implied_probability?: number | null;
}

export interface SignalRow {
    type: string;
    game_id?: number;
    team_id?: number;
    team_name?: string;
    matchup?: string;
    signal?: string;
    status?: string;
    inning?: number | null;
    inning_state?: string | null;
    home_score?: number | null;
    away_score?: number | null;
    pick_side?: string;
    win_probability?: number;
    pregame_win_probability?: number;
    live_win_probability?: number;
    live_probability_delta?: number;
    live_predicted_spread?: number;
    live_predicted_total?: number;
    live_outs_remaining?: number;
    live_updated_at?: string | null;
    is_stale?: boolean;
    model_probability?: number;
    confidence_score?: number;
    predicted_spread?: number;
    vegas_spread?: number;
    predicted_total?: number;
    market_total?: number;
    market_line?: number;
    edge_runs?: number;
    champion_probability?: number;
    playoff_make_probability?: number;
    market_edge?: MarketEdge | null;
    classification?: string;
    score?: number;
    market_price?: number | null;
    market_implied_probability?: number | null;
    probability_edge?: number | null;
    venue_name?: string;
    run_environment?: string;
    runs_signal?: string;
    home_run_signal?: string;
    win_signal?: string;
    weather_signal?: string;
    total_adjustment?: number;
    streak?: string;
    length?: number;
    label?: string;
    reason_codes?: string[];
    risk_flags?: string[];
}

export interface BackendReview {
    model: string;
    strengths: string[];
    watch_items: string[];
}

export interface BetFilter {
    model: string;
    mode?: string;
    primary_market?: string;
    philosophy: string;
    risk_controls: string[];
    promotion?: {
        status?: string;
        public_recommendations_enabled?: boolean;
        shadow_research_enabled?: boolean;
        calibration_guard_enabled?: boolean;
        promotions_validated?: boolean;
        block_reasons?: string[];
    };
}

export interface OddsHealth {
    status: string;
    primary_market?: string;
    moneyline_ready?: boolean;
    slate_games: number;
    moneyline_coverage: number;
    run_line_coverage: number;
    total_coverage: number;
    stale_games: number;
    missing_markets: Array<{
        game_id: number;
        matchup: string;
        missing: string[];
    }>;
}

export interface PassSummary {
    candidates: number;
    passes: number;
    pass_rate: number;
    top_reasons: Array<{
        reason: string;
        count: number;
    }>;
}

export interface MoneylineReadiness {
    mode: string;
    slate_games: number;
    candidate_count: number;
    priced_count: number;
    priced_rate: number;
    bet_count: number;
    lean_count: number;
    pass_count: number;
    public_bet_count?: number;
    public_lean_count?: number;
    shadow_bet_count?: number;
    shadow_lean_count?: number;
    positive_market_edge_count: number;
    usable_count: number;
    public_usable_count?: number;
    shadow_usable_count?: number;
    top_pass_reasons: Array<{
        reason: string;
        count: number;
    }>;
}

export interface SignalsPayload {
    season: number;
    as_of_date: string;
    slate_date?: string | null;
    backend_review: BackendReview;
    odds_health?: OddsHealth;
    bet_filter?: BetFilter;
    moneyline_readiness?: MoneylineReadiness;
    pass_summary?: PassSummary;
    recommended_bets: SignalRow[];
    shadow_recommended_bets?: SignalRow[];
    world_series: SignalRow[];
    moneyline: SignalRow[];
    run_line: SignalRow[];
    totals: SignalRow[];
    ballpark: SignalRow[];
    live: SignalRow[];
    streaks: SignalRow[];
}
