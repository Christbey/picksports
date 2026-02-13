export interface Team {
    id: number
    espn_id: string | null
    abbreviation: string | null
    location: string | null
    name: string
    display_name: string | null
    short_display_name: string | null
    conference: string | null
    division: string | null
    color: string | null
    alternate_color: string | null
    logo: string | null
    created_at: string | null
    updated_at: string | null
}

export interface Game {
    id: number
    espn_id: string | null
    home_team_id: number
    away_team_id: number
    season: number
    season_type: number
    week: number | null
    game_date: string | null
    game_time: string | null
    venue: string | null
    attendance: number | null
    status: string
    period: number | null
    clock: string | null
    home_score: number | null
    away_score: number | null
    home_linescores: string | null
    away_linescores: string | null
    broadcast_networks: string | null
    completed_at: string | null
    created_at: string | null
    updated_at: string | null
    home_team?: Team
    away_team?: Team
}

export interface TeamMetric {
    id: number
    team_id: number
    season: number
    games_played: number
    offensive_rating: number
    defensive_rating: number
    net_rating: number
    pace: number
    true_shooting_percentage: number
    effective_field_goal_percentage: number
    turnover_percentage: number
    offensive_rebound_percentage: number
    free_throw_rate: number
    opponent_effective_field_goal_percentage: number
    opponent_turnover_percentage: number
    defensive_rebound_percentage: number
    opponent_free_throw_rate: number
    strength_of_schedule: number | null
    simple_rating_system: number | null
    created_at: string | null
    updated_at: string | null
    team?: Team
}

export interface Prediction {
    id: number
    game_id: number
    home_team_id: number
    away_team_id: number
    home_win_probability: number
    away_win_probability: number
    predicted_spread: number
    predicted_total: number
    home_expected_score: number
    away_expected_score: number
    confidence_level: string
    model_version: string | null
    created_at: string | null
    updated_at: string | null
    game?: Game
}
