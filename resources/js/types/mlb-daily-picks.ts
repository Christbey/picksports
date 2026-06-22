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
    feature_snapshot: Record<string, unknown>;
    market_snapshot: Record<string, unknown>;
    explanation: string;
    generated_at?: string | null;
    graded_at?: string | null;
    result_status?: string | null;
    result_profit_units?: number | null;
};

export type MlbDailyBoardSummary = {
    slate_games: number;
    priced_games: number;
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
        blocked_reasons: string[];
    };
};
