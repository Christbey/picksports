export type MlbDailyPickTeam = {
    id: number;
    abbreviation?: string | null;
    display_name?: string | null;
} | null;

export type MlbDailyPickPlayer = {
    id: number;
    display_name?: string | null;
} | null;

export type MlbSignalDriver = {
    key: string;
    label: string;
    value?: string | number | null;
    impact: 'positive' | 'warning' | 'risk' | 'neutral' | string;
    source?: string | null;
    source_timestamp?: string | null;
    captured_at?: string | null;
    game_start_at?: string | null;
    is_pregame_safe: boolean;
    pregame_safety_reasons: string[];
};

export type MlbSignalGroup = {
    key: string;
    label: string;
    status: 'positive' | 'warning' | 'risk' | 'neutral' | string;
    summary: string;
    score_delta?: number | null;
    reason_codes?: string[];
    risk_flags?: string[];
    drivers: MlbSignalDriver[];
};

export type MlbSignalLayer = {
    version: string;
    pregame_safe: boolean;
    recommended_angle?: string | null;
    score_delta?: number | null;
    reason_codes: string[];
    risk_flags: string[];
    signal_groups: MlbSignalGroup[];
};

export type MlbPeriodModelDecision = {
    id: number;
    status: string;
    recommendation_label?: string | null;
    side?: string | null;
    is_public: boolean;
    is_tracking_only: boolean;
    is_bet: boolean;
    pregame_safe: boolean;
    eligibility_reasons: string[];
    reason_codes: string[];
    risk_flags: string[];
    model_probability?: number | null;
    market_probability?: number | null;
    no_vig_probability?: number | null;
    edge?: number | null;
    expected_value?: number | null;
    quote?: {
        line?: number | null;
        price?: number | null;
        bookmaker?: string | null;
        captured_at?: string | null;
    } | null;
    decided_at?: string | null;
    settlement?: {
        result_status?: string | null;
        profit_units?: number | null;
        closing_price?: number | null;
        closing_line?: number | null;
        clv?: number | null;
        settled_at?: string | null;
    } | null;
};

export type MlbPeriodModelContext = {
    market_type: 'first_3_moneyline' | 'first_5_moneyline' | string;
    role: 'active_challenger' | 'champion' | 'challenger' | string;
    status: string;
    qualified_for_candidates: boolean;
    active_source: string;
    apply_to_live_output: boolean;
    baseline_probability?: number | null;
    challenger_probability?: number | null;
    probability_delta?: number | null;
    probabilities: {
        home_win?: number | null;
        away_win?: number | null;
        tie?: number | null;
        conditional_home_win?: number | null;
        conditional_away_win?: number | null;
    };
    fair_prices: {
        home?: number | null;
        away?: number | null;
    };
    uncertainty?: number | null;
    model_name?: string | null;
    calibration_method?: string | null;
    lineage: {
        model_run_id?: string | null;
        inference_run_id?: string | null;
        training_run_id?: string | null;
        artifact_id?: string | null;
        artifact_hash?: string | null;
        artifact_uri?: string | null;
        dataset_hash?: string | null;
        config_hash?: string | null;
        code_version?: string | null;
        feature_hash?: string | null;
        snapshot_run_id?: string | null;
    };
    timing: {
        generated_at?: string | null;
        features_available_at?: string | null;
        game_start_at?: string | null;
        pregame_safe: boolean;
        availability_status?: string | null;
    };
    decision?: MlbPeriodModelDecision | null;
};

export type MlbDailyPick = {
    id: number;
    season: number;
    game_id: number;
    prediction_id?: number | null;
    market_type: string;
    market_key: string;
    label: string;
    side: string;
    team: MlbDailyPickTeam;
    player: MlbDailyPickPlayer;
    game?: {
        id: number;
        short_name?: string | null;
        game_date?: string | null;
        status?: string | null;
        home_team?: string | null;
        away_team?: string | null;
    } | null;
    line?: number | null;
    price?: number | null;
    book?: string | null;
    score: number;
    confidence?: number | null;
    model_probability?: number | null;
    model_source?: string | null;
    market_probability?: number | null;
    no_vig_probability?: number | null;
    blend_probability?: number | null;
    edge_raw?: number | null;
    edge_no_vig?: number | null;
    projected_value?: number | null;
    status: string;
    recommendation_label: string;
    internal_candidate_label?: string | null;
    is_public: boolean;
    is_tracking_only: boolean;
    is_bet: boolean;
    reason_codes: string[];
    risk_flags: string[];
    signal_layer?: MlbSignalLayer | null;
    recommended_market_angle?: string | null;
    feature_snapshot?: Record<string, unknown>;
    market_snapshot?: Record<string, unknown>;
    period_models?: MlbPeriodModelContext[];
    explanation?: string | null;
    generated_at?: string | null;
    graded_at?: string | null;
    result_status?: string | null;
    result_profit_units?: number | null;
    closing_price?: number | null;
    closing_line?: number | null;
    clv?: number | null;
};

export type MlbDailyBoardSummary = {
    slate_games: number;
    priced_games: number;
    first_inning_priced_games: number;
    first_3_priced_games: number;
    first_5_priced_games: number;
    candidate_count: number;
    top_candidate_count: number;
    tracking_count: number;
    public_promoted_count: number;
    avg_top_score?: number | null;
    top_candidate_score?: number | null;
    pregame_safe_rate?: number | null;
    market_agreement_rate?: number | null;
};

export type MlbDailyPerformanceRecord = {
    rows: number;
    wins: number;
    losses: number;
    pushes: number;
    hit_rate?: number | null;
    units: number;
    avg_score: number;
};

export type MlbDailyBoardHealth = {
    status:
        | 'no_slate'
        | 'needs_odds'
        | 'pending_scan'
        | 'no_force_picks'
        | 'tracking_ready'
        | string;
    score?: number | null;
    slate_coverage?: number | null;
    pregame_safe_rate?: number | null;
    market_agreement_rate?: number | null;
    message?: string | null;
};

export type MlbDailyPicksPayload = {
    data: {
        date: string;
        mode: string;
        summary: MlbDailyBoardSummary;
        board_health: MlbDailyBoardHealth;
        market_counts: Record<string, number>;
        performance_summary: {
            last_7_days?: MlbDailyPerformanceRecord | null;
            last_30_days?: MlbDailyPerformanceRecord | null;
            by_market: Record<string, MlbDailyPerformanceRecord>;
            sample_warning?: string | null;
            mode_note: string;
        };
        achievements: Array<{
            key: string;
            label: string;
            description: string;
        }>;
        target_count: number;
        public_promoted_count: number;
        candidate_count: number;
        top_picks: MlbDailyPick[];
        candidates: MlbDailyPick[];
        period_models_by_game: Record<string, MlbPeriodModelContext[]>;
        blocked_reasons: string[];
    };
};
