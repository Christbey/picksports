import type { UrlMethodPair } from '@inertiajs/core';

export type GamePageHrefLike = string | UrlMethodPair;

export interface BettingRecommendation {
    type: 'spread' | 'total' | 'moneyline';
    recommendation: string;
    bet_team?: string;
    model_line?: number;
    market_line?: number;
    model_probability?: number;
    implied_probability?: number;
    edge: number;
    odds: number;
    kelly_bet_size_percent?: number;
    confidence: number;
    reasoning: string;
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
    week?: number;
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
    confidence_score?: number;
    actual_spread?: number;
    actual_total?: number;
    spread_error?: number;
    total_error?: number;
    winner_correct?: boolean;
    graded_at?: string;
    betting_value?: BettingRecommendation[];
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
    cardVariant?: 'default' | 'nfl';
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
}

export interface GamePageGame {
    id: number;
    status: string;
    game_date: string | null;
    away_score?: number | null;
    home_score?: number | null;
}

export interface PredictionSummary {
    away_win_probability: number;
    home_win_probability: number;
    predicted_spread: number;
    predicted_total: number;
    confidence_level: string;
    confidence_score?: number | null;
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
    locked_trends: Record<string, string>;
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
    live_predicted_spread?: number | string | null;
    live_win_probability?: number | string | null;
    live_predicted_total?: number | string | null;
    live_seconds_remaining?: number | null;
    live_updated_at?: string | null;
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
}

export type MlbPagePrediction = PredictionSummary;
