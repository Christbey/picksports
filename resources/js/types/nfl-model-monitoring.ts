import type { EvidenceLeaderboard } from '@/lib/nflEvidenceLeaderboard';
export type ArtifactOption = {
    id: string;
    model_version: string;
    model_type: string;
    market_type: string;
    status: string;
    created_at: string | null;
    promoted_at: string | null;
};

export type Artifact = ArtifactOption & {
    sport: string;
    feature_version: string;
    artifact_hash: string;
    dataset_hash: string;
    evaluation_report_hash: string | null;
    training_run_id: string;
    config_hash: string | null;
    code_version: string | null;
    run_type: string | null;
    metrics: Record<string, unknown> | null;
    evaluation_summary: Record<string, number>;
    promotion_checks: Record<string, boolean>;
    promotion_markets: Record<string, PromotionMarketDecision>;
    promoted_markets: string[];
    delta_convention: {
        normalized?: string;
        positive_means?: string;
    };
    public_output_changed: boolean;
};

export type PromotionMarketDecision = {
    available: boolean;
    eligible: boolean;
    promotion_ready?: boolean;
    promoted?: boolean;
    window_count: number;
    challenger_better_window_count: number;
    challenger_better_window_rate: number;
    average_primary_improvement: number | null;
    average_secondary_improvement: number | null;
    worst_primary_window_regression: number | null;
    worst_secondary_window_regression: number | null;
    checks: Record<string, boolean>;
};

export type MonitoringSummary = {
    shadow_observations: number;
    decisions: number;
    tracking_bets: number;
    no_bets: number;
    settled_decisions: number;
    pending_decisions: number;
    actual_profit_units: number;
    actual_roi: number | null;
    counterfactual_profit_units: number;
    counterfactual_roi: number | null;
    average_clv: number | null;
    calibration_games: number;
    baseline_brier: number | null;
    challenger_brier: number | null;
    brier_delta: number | null;
};

export type Observation = {
    id: number;
    game_id: number;
    matchup: string;
    game_date: string | null;
    game_time: string | null;
    game_status: string | null;
    market_type: string;
    baseline_output: number;
    challenger_output: number;
    output_delta: number;
    baseline_outputs: {
        win_probability: number | null;
        predicted_spread: number | null;
        predicted_total: number | null;
    };
    challenger_outputs: {
        win_probability: number | null;
        predicted_spread: number | null;
        predicted_total: number | null;
        uncertainty: number | null;
    };
    active_source: string;
    public_output_changed: boolean;
    generated_at: string | null;
    snapshot: {
        id: number | null;
        snapshot_run_id: string | null;
        model_run_id: string | null;
        feature_hash: string | null;
        pregame_safe: boolean;
        availability_status: string | null;
        features_available_at: string | null;
        game_start_at: string | null;
    };
    inference_run_id: string;
    decision: {
        id: number;
        status: string;
        recommendation_label: string | null;
        side: string;
        price: number | null;
        bookmaker: string | null;
        market_probability: number | null;
        model_probability: number | null;
        edge: number | null;
        is_bet: boolean;
        is_public: boolean;
        is_tracking_only: boolean;
        pregame_safe: boolean;
        eligibility_reasons: string[];
        model_uncertainty: number | null;
        maximum_model_uncertainty: number | null;
        uncertainty_gate_enabled: boolean;
        decided_at: string | null;
    } | null;
    settlement: {
        status: string;
        result_value: number | null;
        profit_units: number | null;
        counterfactual_profit_units: number | null;
        clv: number | null;
        settled_at: string | null;
    } | null;
};

export type EvaluationWindow = {
    evaluation_season?: number;
    test_season?: number;
    games?: number;
    baseline_brier?: number;
    challenger_brier?: number;
    brier_delta?: number;
    baseline_log_loss?: number;
    challenger_log_loss?: number;
    log_loss_delta?: number;
    baseline_spread_mae?: number;
    challenger_spread_mae?: number;
    spread_mae_delta?: number;
    baseline_total_mae?: number;
    challenger_total_mae?: number;
    total_mae_delta?: number;
};

export type SignalGradeRow = {
    signal_type: string;
    signal_key: string;
    observation_count: number;
    unique_game_count: number;
    winner_sample: number;
    winner_accuracy: number | null;
    ats_sample: number;
    ats_hit_rate: number | null;
    total_sample: number;
    total_hit_rate: number | null;
    settlement_sample: number;
    roi: number | null;
    shadow_settlement_sample: number;
    shadow_roi: number | null;
    avg_clv: number | null;
    avg_brier_score: number | null;
    avg_calibration_lift: number | null;
    avg_spread_error: number | null;
    avg_spread_error_lift: number | null;
    avg_total_error: number | null;
    avg_total_error_lift: number | null;
    window_count?: number;
    winner_accuracy_range?: number | null;
    positive_roi_windows?: number;
    roi_window_count?: number;
    season?: number;
    window?: string;
};

export type SignalGradeCategory = {
    signal_type: string;
    label: string;
    signals: SignalGradeRow[];
    windows: SignalGradeRow[];
};

export type NflModelMonitoringProps = {
    artifacts: ArtifactOption[];
    artifact: Artifact | null;
    summary: MonitoringSummary;
    observations: Observation[];
    no_bet_reasons: Array<{ reason: string; count: number }>;
    evaluation_windows: EvaluationWindow[];
    signal_grades: SignalGradeCategory[];
    evidence_leaderboard: EvidenceLeaderboard | null;
    report_status?: {
        state: string;
        generated_at: string | null;
        message: string;
    };
    signal_report_generated_at?: string | null;
};
