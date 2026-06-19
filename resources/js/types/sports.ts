import type { UrlMethodPair } from '@inertiajs/core';
import type { PredictionRecommendation } from '@/lib/predictionRecommendation';
import type { GameDepthChartsData, MatchupContextData } from './models';

export type GamePageHrefLike = string | UrlMethodPair;

export interface BettingRecommendation {
    type: 'spread' | 'total' | 'moneyline';
    recommendation: string;
    bet_team?: string;
    model_line?: number;
    market_line?: number;
    model_home_line?: number;
    market_home_line?: number;
    home_team?: string;
    away_team?: string;
    model_probability?: number;
    implied_probability?: number;
    edge: number;
    odds: number;
    kelly_bet_size_percent?: number;
    confidence: number;
    reasoning: string;
}

export interface BettingValueSummary {
    has_playable_value: boolean;
    play_count: number;
    best_grade?: string | null;
    best_recommendation?: string | null;
    best_type?: string | null;
    best_edge?: number | null;
    best_units?: number | null;
    risk_flags: string[];
}

export interface PredictionAnalysisSummary {
    trust_score?: number | null;
    bet_classification?: string | null;
    model_signal_classification?: string | null;
    risk_flags: string[];
    reason_codes: string[];
    validated_signals?: Array<{
        name: string;
        label: string;
        market: string;
        tier: string;
        sample_size: number;
        winner_hit_rate?: number | null;
        spread_mae?: number | null;
        codes: string[];
        note?: string | null;
    }>;
    best_validated_signal?: {
        name: string;
        label: string;
        market: string;
        tier: string;
        sample_size: number;
        winner_hit_rate?: number | null;
        spread_mae?: number | null;
        codes: string[];
        note?: string | null;
    } | null;
    bet_rule_evaluation?: {
        enabled: boolean;
        action: string;
        matched_rules: Array<{
            name: string;
            action: string;
            market?: string | null;
        }>;
        pass_rules: string[];
    } | null;
    calculated_edge?: {
        spread_points?: number | null;
        total_points?: number | null;
        moneyline_probability?: number | null;
    } | null;
    analysis_confidence?: {
        score?: number | null;
        label?: string | null;
    } | null;
}

export interface AiPredictionAnalysisSummary {
    id: number;
    as_of_date?: string | null;
    market: string;
    recommendation: string;
    bet_classification: string;
    ai_confidence: number;
    analysis_confidence: number;
    summary: string;
    key_factors: string[];
    risk_flags: string[];
    reason_codes: string[];
    market_notes?: {
        moneyline?: string | null;
        spread?: string | null;
        total?: string | null;
        props?: string | null;
    };
    calculated_edge?: {
        predicted_spread?: number | null;
        predicted_total?: number | null;
        home_win_probability?: number | null;
        pick_win_probability?: number | null;
        confidence_score?: number | null;
        vegas_spread?: number | null;
        spread_edge?: number | null;
    };
    provider?: string | null;
    model?: string | null;
    created_at?: string | null;
}

export interface LivePredictionData {
    isLive: boolean;
    homeScore?: number | null;
    awayScore?: number | null;
    period?: number | null;
    inning?: number | null;
    gameClock?: string | null;
    inningState?: string | null;
    status?: string | null;
    liveWinProbability?: number | null;
    livePredictedSpread?: number | null;
    livePredictedTotal?: number | null;
    liveSecondsRemaining?: number | null;
    liveOutsRemaining?: number | null;
    preGameWinProbability: number;
    preGamePredictedSpread: number;
    preGamePredictedTotal: number;
}

export interface PredictionListGameTeam {
    abbreviation: string;
    school?: string;
    mascot?: string;
    location?: string;
    name?: string;
    logo?: string;
    color?: string;
}

export interface PredictionListGame {
    id: number;
    game_date: string;
    game_time?: string;
    status: string;
    period?: number;
    clock?: string;
    inning?: number | null;
    inning_half?: string | null;
    balls?: number | null;
    strikes?: number | null;
    outs?: number | null;
    week?: number;
    postseason_round?: number;
    season_type?: string;
    home_score?: number;
    away_score?: number;
    live_win_probability?: {
        home_win_probability: number;
        away_win_probability: number;
        is_live: boolean;
        seconds_remaining: number;
        margin: number;
    };
    home_team: PredictionListGameTeam;
    away_team: PredictionListGameTeam;
}

export interface PredictionListItem {
    id: number;
    game_id?: number;
    predicted_spread?: number;
    predicted_total?: number;
    win_probability?: number;
    home_win_probability?: number;
    away_win_probability?: number;
    live_predicted_spread?: number | null;
    live_predicted_total?: number | null;
    live_win_probability?: number | null;
    live_seconds_remaining?: number | null;
    live_outs_remaining?: number | null;
    live_updated_at?: string | null;
    confidence_score?: number;
    actual_spread?: number;
    actual_total?: number;
    spread_error?: number;
    total_error?: number;
    winner_correct?: boolean;
    graded_at?: string;
    betting_value?: BettingRecommendation[];
    betting_value_summary?: BettingValueSummary;
    prediction_analysis?: PredictionAnalysisSummary | null;
    ai_analysis?: AiPredictionAnalysisSummary | null;
    recommendation?: PredictionRecommendation | null;
    created_at?: string | null;
    updated_at?: string | null;
    home_elo?: number;
    away_elo?: number;
    home_off_eff?: number;
    home_def_eff?: number;
    away_off_eff?: number;
    away_def_eff?: number;
    home_team_elo?: number;
    away_team_elo?: number;
    home_pitcher_elo?: number;
    away_pitcher_elo?: number;
    home_combined_elo?: number;
    away_combined_elo?: number;
    game: PredictionListGame;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface SeasonWeekOption {
    value: string;
    label: string;
}

export interface SeasonWeekConfig {
    regularSeasonWeeks: number;
    postseasonOptions: SeasonWeekOption[];
}

export interface SportPredictionsConfig {
    sport: string;
    title: string;
    subtitle: string;
    useEasternTime: boolean;
    showGameTime: boolean;
    confidenceIsDecimal: boolean;
    confidenceDecimals: number;
    filterMode?: 'date' | 'seasonWeek' | 'none';
    seasonWeekConfig?: SeasonWeekConfig;
}

export interface ApiEnvelope<T> {
    data: T;
    meta?: PaginationMeta;
}

export interface LineScoreEntry {
    period?: number;
    value: number | string;
}

export interface GamePageTeam {
    id: number;
    abbreviation?: string | null;
    name?: string | null;
    display_name?: string | null;
    location?: string | null;
    logo?: string | null;
    color?: string | null;
    active_injuries_count?: number;
    active_injuries?: Array<{
        id: number;
        player_id: number;
        player_name?: string | null;
        player_headshot?: string | null;
        status?: string | null;
        detail?: string | null;
        type?: string | null;
        impact_score?: number | null;
        impact_label?: string | null;
        impact_spread?: number | null;
        impact_total?: number | null;
        impact_multiplier?: number | null;
        injury_date?: string | null;
        return_date?: string | null;
        source_updated_at?: string | null;
        is_active?: boolean;
        updated_at?: string | null;
    }>;
}

export interface GamePageGame {
    id: number;
    status: string;
    game_date: string | null;
    away_score?: number | null;
    home_score?: number | null;
    matchup_context?: MatchupContextData | null;
    depth_charts?: GameDepthChartsData | null;
}

export interface PredictionSummary {
    away_win_probability: number;
    home_win_probability: number;
    predicted_spread: number;
    predicted_total: number;
    confidence_level: string;
    confidence_context?: {
        label?: string | null;
        tier?: string | null;
        raw_level?: string | null;
        reason_codes?: string[];
        sample_games?: number | null;
    } | null;
    confidence_score?: number | null;
    actual_total?: number | null;
    winner_correct?: boolean | null;
    betting_value?: BettingRecommendation[];
    prediction_analysis?: PredictionAnalysisSummary | null;
    narrative?: {
        summary: string;
        key_points: string[];
        risk_note: string;
        generated_by: string;
        social_caption?: string | null;
        betting_plan?: {
            bet_pick: string;
            reasoning: string;
            classification?: string | null;
            for_bet?: string[];
            against_bet?: string[];
            pass_reasons?: string[];
            reason_codes?: string[];
        } | null;
        context_layer?: Record<string, unknown> | null;
    } | null;
    depth_chart_context?: {
        type: 'injury_weighting' | 'starter_fallback';
        applied?: boolean;
        home_out_weighted?: number | null;
        away_out_weighted?: number | null;
        home_questionable_weighted?: number | null;
        away_questionable_weighted?: number | null;
        spread_adjustment?: number | null;
        total_adjustment?: number | null;
        win_probability_adjustment?: number | null;
        injury_model_source?: string | null;
        injury_spread_model_source?: string | null;
        injury_total_model_source?: string | null;
        home_pitcher_source?: string | null;
        away_pitcher_source?: string | null;
        home_depth_chart_fallback_used?: boolean;
        away_depth_chart_fallback_used?: boolean;
        probable_pitcher_injury_applied?: boolean;
    } | null;
}

export interface TeamMetricsData {
    offensive_rating: number;
    defensive_rating: number;
    net_rating: number;
    pace: number;
}

export interface TeamStatsEntry extends Record<string, unknown> {
    team_type?: 'home' | 'away' | string;
}

export interface TopPerformer extends Record<string, unknown> {
    id: number;
    team_id?: number;
    points?: number;
    rebounds?: number;
    rebounds_total?: number;
    assists?: number;
    field_goals_made?: number;
    field_goals_attempted?: number;
    player_id?: number;
    player?: {
        name?: string;
    } | null;
    team?: {
        abbreviation?: string;
    } | null;
}

export interface RecentGameListItem {
    id: number;
    status?: string;
    home_team_id: number;
    away_team_id: number;
    home_score: number | null;
    away_score: number | null;
    home_team?: { abbreviation?: string };
    away_team?: { abbreviation?: string };
}

export interface TeamTrendData {
    team_id?: number;
    team_abbreviation?: string;
    team_name?: string;
    sample_size?: number;
    user_tier?: string;
    trends: Record<string, string[]>;
    scored_signals?: TeamTrendSignal[];
    trend_signal_summary?: {
        counts?: Record<string, number>;
        top_signals?: TeamTrendSignal[];
        primary_signal?: TeamTrendSignal | null;
    };
    locked_trends: Record<string, string>;
}

export interface TeamTrendSignal {
    id: string;
    category: string;
    message: string;
    quality: 'actionable' | 'contextual' | 'volatile' | string;
    direction: string;
    tone: 'team' | 'total' | 'risk' | string;
    score: number;
    confidence: 'strong' | 'medium' | 'low' | 'thin_sample' | string;
    sample_size?: number | null;
    percentage?: number | null;
    reason_codes?: string[];
}

export interface SportGamePageConfig {
    sport: string;
    sportLabel: string;
    predictionsHref: string;
    gameHrefPrefix: string;
    teamLink: (teamId: number) => GamePageHrefLike;
    gradientClass?: string;
    awayBarClass?: string;
    homeBarClass?: string;
    projectedLabel?: string;
    metricsTitle?: string;
    topPerformersMode?: 'list' | 'table';
    trendsTitle?: string;
    linescoreTitle?: string;
    linescoreUsePeriodNumbers?: boolean;
    linescorePeriodPrefix?: string;
    trendsEmptyText?: string;
}

export type NflPageTeam = GamePageTeam;

export interface NflPagePrediction {
    id: number;
    game_id: number;
    home_elo: number | string;
    away_elo: number | string;
    predicted_spread: number | string;
    predicted_total?: number | string;
    win_probability: number | string;
    confidence_score: number | string;
    betting_value?: BettingRecommendation[];
    winner_correct?: boolean | null;
    actual_total?: number | string | null;
    live_predicted_spread?: number | string | null;
    live_win_probability?: number | string | null;
    live_predicted_total?: number | string | null;
    live_seconds_remaining?: number | null;
    live_updated_at?: string | null;
    narrative?: PredictionSummary['narrative'];
    depth_chart_context?: PredictionSummary['depth_chart_context'];
}

export interface NflTeamStats {
    team_type?: 'home' | 'away' | string;
    total_yards: number;
    passing_yards: number;
    passing_completions: number;
    passing_attempts: number;
    rushing_yards: number;
    rushing_attempts: number;
    first_downs: number;
    third_down_conversions: number;
    third_down_attempts: number;
    fourth_down_conversions: number;
    fourth_down_attempts: number;
    red_zone_scores: number;
    red_zone_attempts: number;
    interceptions: number;
    fumbles_lost: number;
    sacks_allowed: number;
    penalties: number;
    penalty_yards: number;
    time_of_possession: number;
}

export interface NflPageGame extends GamePageGame {
    home_team_id: number;
    away_team_id: number;
    season: number;
    season_type: string;
    week: number;
    postseason_round?: number | null;
    game_time: string;
    venue: string;
    home_linescores?: Array<{ period?: number; value?: number }> | string;
    away_linescores?: Array<{ period?: number; value?: number }> | string;
    broadcast_networks?: string[] | string;
    home_team?: NflPageTeam;
    away_team?: NflPageTeam;
    prediction?: NflPagePrediction;
}

export interface MlbPageTeam {
    id: number;
    name: string;
    location: string;
    abbreviation: string;
    logo_url: string | null;
    league: string;
    division: string;
    active_injuries_count?: number;
    active_injuries?: Array<{
        id: number;
        player_id: number;
        player_name?: string | null;
        player_headshot?: string | null;
        status?: string | null;
        detail?: string | null;
        type?: string | null;
        impact_score?: number | null;
        impact_label?: string | null;
        impact_spread?: number | null;
        impact_total?: number | null;
        impact_multiplier?: number | null;
        injury_date?: string | null;
        return_date?: string | null;
        source_updated_at?: string | null;
        is_active?: boolean;
        updated_at?: string | null;
    }>;
}

export interface MlbPageGame extends GamePageGame {
    home_team_id: number;
    away_team_id: number;
    home_score: number | null;
    away_score: number | null;
    home_linescores: unknown[] | null;
    away_linescores: unknown[] | null;
    inning: number | null;
    inning_half: string | null;
    venue_name: string | null;
    venue_city: string | null;
    venue_state: string | null;
    broadcast_networks: string[] | null;
    season: number;
    season_type: string;
    probable_home_pitcher_espn_id?: string | null;
    probable_away_pitcher_espn_id?: string | null;
    home_starting_pitcher?: {
        id: number;
        espn_id?: string | null;
        full_name?: string | null;
        headshot_url?: string | null;
        elo_rating?: number | null;
    } | null;
    away_starting_pitcher?: {
        id: number;
        espn_id?: string | null;
        full_name?: string | null;
        headshot_url?: string | null;
        elo_rating?: number | null;
    } | null;
}

export type MlbPagePrediction = PredictionSummary;
