export interface Team {
    id: number;
    espn_id: string | null;
    abbreviation: string | null;
    location: string | null;
    name: string;
    display_name: string | null;
    short_display_name: string | null;
    conference: string | null;
    division: string | null;
    color: string | null;
    alternate_color: string | null;
    logo: string | null;
    active_injuries_count?: number;
    active_injuries?: PlayerInjury[];
    created_at: string | null;
    updated_at: string | null;
}

export interface DepthChartMetric {
    key: string;
    label: string;
    value: string;
}

export interface TeamDepthChartEntry {
    id: number;
    player_id: number | null;
    espn_athlete_id: string | null;
    full_name: string | null;
    jersey_number: string | null;
    headshot_url: string | null;
    player_position: string | null;
    position_slot_key: string;
    position_code: string | null;
    position_name: string | null;
    depth_chart_name: string | null;
    slot_order: number | null;
    depth_rank: number;
    is_starter: boolean;
    stats: {
        games_played: number;
        metrics: DepthChartMetric[];
    };
}

export interface GameDepthChartTeam {
    team: {
        id: number;
        espn_id: string | null;
        abbreviation: string | null;
        display_name: string | null;
        logo: string | null;
    };
    season: number;
    season_type: number | string | null;
    before_date: string | null;
    entries: TeamDepthChartEntry[];
}

export interface GameDepthChartsData {
    game_id: number;
    season: number;
    season_type: number | string | null;
    game_date: string | null;
    away_team: GameDepthChartTeam | null;
    home_team: GameDepthChartTeam | null;
}

export interface PlayerInjury {
    id: number;
    player_id: number;
    player_name?: string | null;
    player_headshot?: string | null;
    status: string | null;
    detail: string | null;
    type: string | null;
    impact_score?: number | null;
    impact_label?: string | null;
    impact_spread?: number | null;
    impact_total?: number | null;
    impact_multiplier?: number | null;
    return_date: string | null;
    source_updated_at: string | null;
    is_active: boolean;
    updated_at: string | null;
}

export interface Game {
    id: number;
    espn_id: string | null;
    home_team_id: number;
    away_team_id: number;
    season: number;
    season_type: number | string | null;
    week: number | null;
    postseason_round?: number | null;
    game_date: string | null;
    game_time: string | null;
    venue?: string | null;
    venue_name?: string | null;
    venue_city?: string | null;
    venue_state?: string | null;
    status: string;
    period: number | null;
    clock?: string | null;
    game_clock?: string | null;
    home_score: number | null;
    away_score: number | null;
    home_linescores: Array<{ period: number; value: number }> | null;
    away_linescores: Array<{ period: number; value: number }> | null;
    broadcast_networks: string[] | null;
    matchup_context?: MatchupContextData | null;
    created_at: string | null;
    updated_at: string | null;
    home_team?: Team;
    away_team?: Team;
    team_stats?: Array<Record<string, unknown>>;
    prediction?: Prediction;
}

export interface MatchupContextRecord {
    wins: number;
    losses: number;
    ties: number;
    games: number;
    display: string;
}

export interface MatchupContextRow {
    key: string;
    label: string;
    subtitle?: string;
    away: MatchupContextRecord;
    home: MatchupContextRecord;
}

export interface MatchupContextData {
    rows: MatchupContextRow[];
}

export interface TeamMetric {
    id: number;
    team_id: number;
    season: number;
    season_type?: string | null;
    games_played: number;
    offensive_rating: number;
    defensive_rating: number;
    net_rating: number;
    pace: number;
    true_shooting_percentage: number;
    effective_field_goal_percentage: number;
    turnover_percentage: number;
    offensive_rebound_percentage: number;
    free_throw_rate: number;
    opponent_effective_field_goal_percentage: number;
    opponent_turnover_percentage: number;
    defensive_rebound_percentage: number;
    opponent_free_throw_rate: number;
    strength_of_schedule: number | null;
    simple_rating_system: number | null;
    created_at: string | null;
    updated_at: string | null;
    team?: Team;
}

export interface Prediction {
    id: number;
    game_id: number;
    home_team_id: number;
    away_team_id: number;
    home_win_probability: number;
    away_win_probability: number;
    predicted_spread: number;
    predicted_total: number;
    home_expected_score: number;
    away_expected_score: number;
    confidence_level: string;
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
        home_pitcher_source?: string | null;
        away_pitcher_source?: string | null;
        home_depth_chart_fallback_used?: boolean;
        away_depth_chart_fallback_used?: boolean;
        probable_pitcher_injury_applied?: boolean;
    } | null;
    model_version: string | null;
    created_at: string | null;
    updated_at: string | null;
    game?: Game;
}
