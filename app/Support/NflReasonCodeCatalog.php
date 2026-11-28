<?php

namespace App\Support;

class NflReasonCodeCatalog
{
    /**
     * Historical tokens are kept readable without endorsing their original
     * charting/pace claims. New predictions emit explicitly named diagnostics.
     * These are labels only, not aliases for matching approval rules.
     *
     * @var array<string, string>
     */
    private const LEGACY_PROXY_LABELS = [
        'weak_ol_vs_blitz_heavy_defense' => 'High Combined Sack Rate Proxy (Legacy)',
        'elite_defense_edge' => 'High Defensive Sack Rate Context (Legacy)',
        'explosive_play_prevention_edge' => 'High Defensive Sack Rate Context (Legacy)',
        'poor_secondary_risk' => 'Low Defensive Sack Rate Context (Legacy)',
        'ol_pass_protection_edge' => 'Model Side Sack Matchup Proxy (Legacy)',
        'home_pass_protection_edge' => 'Home Sack Matchup Proxy (Legacy)',
        'away_pass_protection_edge' => 'Away Sack Matchup Proxy (Legacy)',
        'ol_run_blocking_edge' => 'Model Side Rushing Efficiency Matchup Proxy (Legacy)',
        'dl_pressure_edge' => 'Model Side Sack Matchup Proxy (Legacy)',
        'pressure_mismatch_against_qb' => 'Model Side Sack Matchup Proxy (Legacy)',
        'cannot_run_block_risk' => 'Negative Rushing Matchup Proxy (Legacy)',
        'run_game_should_travel' => 'Away Positive Rushing Matchup Proxy (Legacy)',
        'run_heavy_clock_control' => 'Positive Rushing Matchup Proxy (Legacy)',
        'dl_run_stop_edge' => 'Low Defensive Rushing Yards Per Attempt Context (Legacy)',
        'poor_run_defense_risk' => 'High Defensive Rushing Yards Per Attempt Context (Legacy)',
        'trenches_major_home_edge' => 'Home Trench Matchup Proxy (Legacy)',
        'trenches_major_away_edge' => 'Away Trench Matchup Proxy (Legacy)',
        'trench_matchup_home_edge' => 'Home Trench Matchup Proxy (Legacy)',
        'trench_matchup_away_edge' => 'Away Trench Matchup Proxy (Legacy)',
        'pass_heavy_volatility' => 'High Pass Attempt Share Context (Legacy)',
        'fast_pace_over_signal' => 'Positive Model Total Context; Pace Not Measured (Legacy)',
        'slow_pace_under_signal' => 'Negative Model Total Context; Pace Not Measured (Legacy)',
        'explosive_offense_edge' => 'Positive Trench Total Proxy (Legacy)',
        'bend_dont_break_defense' => 'Negative Trench Total Proxy (Legacy)',
    ];

    /** @var array<string, string> */
    private const MODEL_QUALITY_CONTEXT_LABELS = [
        'red_zone_efficiency_edge' => 'Model Quality/Total Context; Red-zone Efficiency Not Measured',
        'third_down_defense_edge' => 'Model Quality Context; Third-down Defense Not Measured',
        'field_position_edge' => 'Model Quality Context; Field Position Not Measured',
        'coaching_aggression_edge' => 'Model Quality Context; Coaching Aggression Not Measured',
        'fourth_down_decision_edge' => 'Model Quality Context; Fourth-down Decisions Not Measured',
    ];

    /** Predicate-level labels preserve opaque historical tokens and raw rule matching. */
    private const EVIDENCE_CONTEXT_LABELS = [
        'primetime_home_edge' => 'Stored UTC Evening Home Model Context',
        'primetime_total_watch' => 'Stored UTC Evening Total Watch Context',
        'primetime_kickoff_window' => 'Stored UTC Evening Kickoff Window',
        'home_rookie_qb_primetime_start' => 'Home Rookie QB in Stored UTC Evening Window',
        'away_rookie_qb_primetime_start' => 'Away Rookie QB in Stored UTC Evening Window',
        'rookie_qb_primetime_split' => 'Rookie QB Stored UTC Evening Split Context',
        'early_kickoff_window' => 'Stored UTC Early Kickoff Window',
        'late_afternoon_kickoff_window' => 'Stored UTC Middle Kickoff Window',
        'must_win_motivation_edge' => 'Late-season Week Context; Motivation Not Measured',
        'late_season_motivation_risk' => 'Late-season Week Context; Motivation Not Measured',
        'lookahead_spot_risk' => 'Late-season Week Context; Lookahead/Letdown Not Measured',
        'letdown_spot_risk' => 'Late-season Week Context; Lookahead/Letdown Not Measured',
        'sandwich_spot_risk' => 'Late-season Week Context; Lookahead/Letdown Not Measured',
        'poor_special_teams_risk' => 'Low Model Trust Context; Special Teams Not Measured',
        'playoff_motivation_edge' => 'Non-regular Phase Context; Motivation/Rest Not Confirmed',
        'resting_starters_risk' => 'Non-regular Phase Context; Motivation/Rest Not Confirmed',
        'coach_vs_opponent_history_edge' => 'Stored Team Head-to-head Context; Coach History Not Verified',
        'recent_h2h_matchup_edge' => 'Stored Recent Team Head-to-head Context',
        'division_rematch_total_context' => 'Stored Team Head-to-head Total Context',
        'rivalry_under_signal' => 'Same-division Context; Under Direction Not Measured',
        'home_field_edge' => 'Stored Home/Away Split Context',
        'bye_week_edge' => 'Stored Long-rest Interval Context',
        'road_team_travel_risk' => 'Consecutive Away-game Schedule Context',
        'west_to_east_early_kickoff' => 'Stored UTC Early-window Location Proxy',
        'road_short_rest_early_kickoff' => 'Stored Rest/Away-schedule and UTC-window Context',
        'road_trip_early_kickoff' => 'Stored Rest/Away-schedule and UTC-window Context',
        'rest_travel_kickoff_stress' => 'Stored Rest/Away-schedule and UTC-window Context',
        'rest_kickoff_window_home_edge' => 'Stored Rest/Away-schedule and UTC-window Context',
        'dome_scoring_boost' => 'Stored Indoor Venue Context',
        'backup_qb_starting' => 'Limited/First-year QB Experience Context; Backup Not Confirmed',
        'elite_qb_vs_weak_secondary' => 'Veteran QB Passing Profile; Opponent Secondary Not Measured',
        'qb_upgrade' => 'Model-side QB Profile Score Advantage',
        'qb_downgrade' => 'Opponent QB Profile Score Advantage',
        'qb_form_improving' => 'Positive Model-side QB Profile Score',
        'qb_form_declining' => 'Negative Model-side QB Profile Score',
        'explosive_pass_edge' => 'High Prior Passing Yards-per-attempt Context',
        'home_qb_profile_supports_pick' => 'Home QB Experience Profile Available',
        'away_qb_profile_supports_pick' => 'Away QB Experience Profile Available',
        'rookie_qb_vs_pressure_defense' => 'Limited-experience QB Sack-matchup Proxy',
        'key_offensive_weapon_out' => 'Weighted Unavailability Threshold; Unit/Key Role Not Established',
        'key_defender_out' => 'Weighted Unavailability Threshold; Unit/Key Role Not Established',
        'wr1_out_risk' => 'Scoped WR Unavailability Context; WR1 Not Established',
        'rb1_out_risk' => 'Scoped Skill-position Unavailability Cluster; RB1 Not Established',
        'wr_depth_risk' => 'Scoped Skill-position Unavailability Cluster',
        'te_red_zone_edge' => 'Scoped TE Unavailability Context; Red-zone Impact Not Measured',
        'snow_under_signal' => 'Cold Precipitation Under Hypothesis; Snow Not Confirmed',
        'rain_under_signal' => 'Warmer Precipitation Under Hypothesis; Rain Not Confirmed',
        'weather_increases_turnover_risk' => 'Precipitation Turnover-risk Hypothesis',
        'market_overreaction' => 'Model/Market Spread Disagreement Context',
        'sharp_line_move_signal' => 'Stored Line Movement Threshold; Sharp Flow Not Verified',
        'reverse_line_movement' => 'Stored Line Movement Opposes Model Direction; Public Flow Unknown',
        'public_fade_signal' => 'Stored Line Movement Opposes Model Direction; Public Flow Unknown',
        'steam_freshness' => 'Stored Line-change/Span Heuristic; Steam Not Verified',
        'market_setter_slow_book_gap' => 'Stored Cross-book Spread Range Context',
        'buyback_resistance' => 'Stored Spread Reversal Pattern Context',
        'pace_total_pressure' => 'Model Total Above Market; Pace Not Measured',
        'weather_total_suppression' => 'Total Suppression Context; Weather Attribution Not Established',
        'qb_status_edge' => 'QB Form Model-signal Context; Status Not Established',
        'trenches_pressure_edge' => 'Line-matchup Model-signal Context; Charted Pressure Not Measured',
        'division_dog_key_number' => 'Same-division Model Context; Underdog/Key Not Established',
        'qb_replacement_value_edge' => 'Aggregate Injury Adjustment Context; QB Replacement Not Established',
        'injury_overreaction_risk' => 'Large Aggregate Injury Adjustment Context; Market Reaction Not Measured',
        'rest_travel_edge' => 'Applied Rest/Away-schedule Model Context',
        'efficiency_mismatch_edge' => 'Combined Model Efficiency-signal Context',
        'turnover_luck_fade' => 'Turnover-differential Heuristic Context',
        'turnover_regression_risk' => 'Turnover-differential Contrast; Regression Not Verified',
        'one_score_regression_risk' => 'Stored Luck-rating/One-score Heuristic Context',
        'record_overperformance_fade' => 'Stored Record-contrast Heuristic Context',
        'negative_clv_profile' => 'Stored CLV/Staleness Caution; Negative Closing Value Not Always Observed',
        'dead_number_tax' => 'Stored Spread-price/Number-discipline Caution',
    ];

    /**
     * @return list<string>
     */
    public function backgroundCodes(): array
    {
        return [
            'adaptive_calibration_signal',
            'adaptive_point_calibration_signal',
            'bend_dont_break_defense',
            'contextual_adjustments',
            'division_rematch_total_context',
            'division_rematch_under_watch',
            'division_rematch_over_watch',
            'high_trust_no_market_edge',
            'home_away_split_signal',
            'high_market_total',
            'high_defensive_sack_rate_context',
            'low_defensive_sack_rate_context',
            'high_defensive_rushing_yards_per_attempt_context',
            'low_defensive_rushing_yards_per_attempt_context',
            'high_pass_attempt_share_context',
            'model_total_above_market_context',
            'model_total_below_market_context',
            'early_kickoff_window',
            'late_afternoon_kickoff_window',
            'low_market_total',
            'multi_factor_confluence',
            'new_head_coach_context',
            'new_head_coach_qb_transition_context',
            'ol_dl_matchup_signal',
            'primetime_total_watch',
            'primetime_kickoff_window',
            'rookie_qb_early_season_split',
            'rookie_qb_division_split',
            'rookie_qb_primetime_split',
            'rookie_qb_short_rest_split',
            'rookie_qb_extra_rest_split',
            'early_season_prior_pedigree_context',
            'recent_matchup_record_context',
            'rolling_efficiency_mature_sample',
            'rolling_efficiency_signal',
            'same_week_record_context',
            'slow_pace_under_signal',
            'total_key_number_context',
            'total_weather_suppression',
            'week_1_total_uncertainty',
            'prior_playoff_teams_both',
            'prior_season_pedigree_context',
            'prior_season_record_neutral',
            'week_1_prior_pedigree_context',
            'week_1_defending_super_bowl_champion_context',
            'weather_total_context',
        ];
    }

    /**
     * @param  list<string>  $codes
     * @return array<string,array<string,mixed>>
     */
    public function metadataForCodes(array $codes): array
    {
        $metadata = [];

        foreach (array_values(array_unique($codes)) as $code) {
            $metadata[$code] = $this->metadata($code);
        }

        return $metadata;
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(string $code): array
    {
        $source = $this->source($code);
        $marketType = $this->marketType($code);
        $direction = $this->direction($code);
        $diagnostic = $this->isDiagnostic($code);
        $actionable = $this->isActionable($code, $source, $diagnostic);

        return [
            'code' => $code,
            'label' => $this->label($code),
            'source' => $source,
            'market_type' => $marketType,
            'direction' => $direction,
            'is_actionable' => $actionable,
            'is_diagnostic' => $diagnostic,
            'requires_market' => $this->requiresMarket($code),
        ];
    }

    public function isBackground(string $code): bool
    {
        return in_array($code, $this->backgroundCodes(), true);
    }

    protected function source(string $code): string
    {
        if (in_array($code, ['weather_total_suppression', 'qb_replacement_value_edge'], true)) {
            return 'model_context';
        }

        if ($code === 'coach_vs_opponent_history_edge') {
            return 'schedule_context';
        }

        if (isset(self::MODEL_QUALITY_CONTEXT_LABELS[$code])) {
            return 'model_context';
        }

        if (
            str_contains($code, 'market')
            || str_contains($code, 'line_move')
            || str_contains($code, 'key_number')
            || str_contains($code, 'clv')
            || str_contains($code, 'teaser')
            || str_contains($code, 'steam')
            || str_contains($code, 'slow_book')
            || str_contains($code, 'dead_number')
            || str_contains($code, 'buyback')
            || str_contains($code, 'teaser_corridor')
        ) {
            return 'market';
        }

        if (str_contains($code, 'turnover_luck') || str_contains($code, 'overperformance') || str_contains($code, 'one_score_regression')) {
            return 'regression';
        }

        if (str_contains($code, 'efficiency_mismatch') || str_contains($code, 'success_rate')) {
            return 'model';
        }

        if (str_contains($code, 'weather') || str_contains($code, 'outdoor_total_proxy') || str_contains($code, 'wind_') || str_contains($code, 'rain_') || str_contains($code, 'snow_')) {
            return str_contains($code, '_proxy') ? 'weather_proxy' : 'actual_weather';
        }

        if (str_contains($code, 'qb_') || str_contains($code, 'passing_game') || str_contains($code, 'explosive_pass')) {
            return 'quarterback';
        }

        if (str_contains($code, 'trench') || str_starts_with($code, 'ol_') || str_starts_with($code, 'dl_') || str_contains($code, 'pressure')) {
            return 'trenches';
        }

        if (str_contains($code, 'injury') || str_contains($code, '_out_') || str_contains($code, 'depth') || str_contains($code, 'replacement_value')) {
            return 'injury';
        }

        if (str_contains($code, 'coach')) {
            return 'coaching';
        }

        if (str_contains($code, 'rest') || str_contains($code, 'travel') || str_contains($code, 'division') || str_contains($code, 'conference') || str_contains($code, 'primetime') || str_contains($code, 'kickoff') || str_contains($code, 'matchup_record') || str_contains($code, 'same_week') || str_contains($code, 'h2h_record') || str_contains($code, 'prior_season') || str_contains($code, 'prior_playoff') || str_contains($code, 'pedigree')) {
            return 'schedule_context';
        }

        if (str_contains($code, 'calibration')) {
            return 'calibration';
        }

        if (str_contains($code, 'rolling_efficiency') || str_contains($code, 'opponent_adjusted') || str_contains($code, 'model_signal')) {
            return 'model';
        }

        return 'model_context';
    }

    protected function marketType(string $code): ?string
    {
        if (str_contains($code, 'total') || str_contains($code, '_under_') || str_contains($code, '_over_')) {
            return 'total';
        }

        if (str_contains($code, 'spread') || str_contains($code, 'cover') || str_contains($code, 'key_number') || str_contains($code, 'teaser')) {
            return 'spread';
        }

        if (str_contains($code, 'moneyline') || str_contains($code, 'winner') || str_contains($code, 'model_side')) {
            return 'moneyline';
        }

        if (str_contains($code, 'futures') || str_contains($code, 'super_bowl')) {
            return 'futures';
        }

        return null;
    }

    protected function direction(string $code): ?string
    {
        if (str_contains($code, '_home') || str_ends_with($code, '_home_edge') || str_ends_with($code, '_home')) {
            return 'home';
        }

        if (str_contains($code, '_away') || str_ends_with($code, '_away_edge') || str_ends_with($code, '_away')) {
            return 'away';
        }

        if (str_contains($code, '_under') || str_ends_with($code, '_under_signal')) {
            return 'under';
        }

        if (str_contains($code, '_over') || str_ends_with($code, '_over_signal')) {
            return 'over';
        }

        return null;
    }

    protected function isDiagnostic(string $code): bool
    {
        if (in_array($code, ['weather_total_suppression', 'qb_replacement_value_edge'], true)) {
            return true;
        }

        if (isset(self::MODEL_QUALITY_CONTEXT_LABELS[$code]) || isset(self::LEGACY_PROXY_LABELS[$code]) || $this->isBackground($code)) {
            return true;
        }

        return str_contains($code, 'risk')
            || str_contains($code, 'uncertainty')
            || str_contains($code, 'small_sample')
            || str_contains($code, 'missing_')
            || str_contains($code, 'no_market')
            || str_contains($code, 'proxy')
            || str_contains($code, 'stale_')
            || str_contains($code, 'negative_clv')
            || str_contains($code, 'dead_number_tax')
            || str_contains($code, 'overreaction');
    }

    protected function isActionable(string $code, string $source, bool $diagnostic): bool
    {
        if ($diagnostic) {
            return false;
        }

        if (in_array($source, ['market', 'quarterback', 'trenches', 'injury', 'actual_weather', 'schedule_context', 'coaching'], true)) {
            return true;
        }

        return in_array($code, ['strong_model_signal', 'lean_model_signal', 'bettable_confluence'], true);
    }

    protected function requiresMarket(string $code): bool
    {
        return str_contains($code, 'market')
            || str_contains($code, 'key_number')
            || str_contains($code, 'line_move')
            || str_contains($code, 'teaser')
            || str_contains($code, 'clv')
            || str_contains($code, 'steam')
            || str_contains($code, 'dead_number')
            || str_contains($code, 'buyback')
            || str_contains($code, 'slow_book')
            || str_contains($code, 'bettable');
    }

    protected function label(string $code): string
    {
        if (isset(self::EVIDENCE_CONTEXT_LABELS[$code])) {
            return self::EVIDENCE_CONTEXT_LABELS[$code];
        }

        if (isset(self::MODEL_QUALITY_CONTEXT_LABELS[$code])) {
            return self::MODEL_QUALITY_CONTEXT_LABELS[$code];
        }

        if (isset(self::LEGACY_PROXY_LABELS[$code])) {
            return self::LEGACY_PROXY_LABELS[$code];
        }

        return str($code)
            ->replace('_', ' ')
            ->headline()
            ->toString();
    }
}
