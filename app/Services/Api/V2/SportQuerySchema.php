<?php

namespace App\Services\Api\V2;

/** Explicit optional columns used by shared sport filters, search and ordering. */
final class SportQuerySchema
{
    public const array SUPPORTED_COLUMNS = ['id', 'game_id', 'team_id', 'player_id', 'season', 'season_type', 'game_date', 'updated_at', 'fetched_at', 'graded_at', 'market', 'bookmaker', 'recommended_side', 'conference', 'division', 'league', 'abbreviation', 'location', 'name', 'nickname', 'school', 'mascot', 'display_name', 'short_display_name', 'short_name', 'full_name', 'first_name', 'last_name', 'jersey_number', 'position', 'status', 'net_rating', 'offensive_rating', 'offensive_efficiency', 'fpi', 'calculation_date', 'stat_type', 'team_type'];

    public const array COLUMNS = [
        'cbb_games' => ['id', 'season', 'season_type', 'game_date', 'name', 'short_name', 'status', 'updated_at'],
        'cbb_player_injuries' => ['id', 'player_id', 'team_id', 'status', 'updated_at'],
        'cbb_player_props' => ['id', 'game_id', 'player_id', 'market', 'bookmaker', 'fetched_at', 'graded_at', 'updated_at', 'recommended_side'],
        'cbb_player_stats' => ['id', 'player_id', 'game_id', 'team_id', 'updated_at'],
        'cbb_players' => ['id', 'team_id', 'first_name', 'last_name', 'full_name', 'jersey_number', 'position', 'updated_at'],
        'cbb_predictions' => ['id', 'game_id', 'updated_at', 'graded_at'],
        'cbb_team_metrics' => ['id', 'team_id', 'season', 'offensive_efficiency', 'net_rating', 'calculation_date', 'updated_at'],
        'cbb_team_stats' => ['id', 'team_id', 'game_id', 'team_type', 'updated_at'],
        'cbb_teams' => ['id', 'abbreviation', 'school', 'mascot', 'conference', 'division', 'updated_at'],
        'cfb_games' => ['id', 'season', 'season_type', 'game_date', 'name', 'short_name', 'status', 'updated_at'],
        'cfb_player_injuries' => ['id', 'player_id', 'team_id', 'status', 'updated_at'],
        'cfb_player_props' => ['id', 'game_id', 'player_id', 'market', 'bookmaker', 'recommended_side', 'fetched_at', 'graded_at', 'updated_at'],
        'cfb_player_stats' => ['id', 'player_id', 'game_id', 'team_id', 'updated_at'],
        'cfb_players' => ['id', 'team_id', 'first_name', 'last_name', 'full_name', 'jersey_number', 'position', 'updated_at'],
        'cfb_predictions' => ['id', 'game_id', 'updated_at', 'graded_at'],
        'cfb_team_metrics' => ['id', 'team_id', 'season', 'offensive_rating', 'net_rating', 'calculation_date', 'updated_at', 'fpi'],
        'cfb_team_stats' => ['id', 'team_id', 'game_id', 'team_type', 'updated_at'],
        'cfb_teams' => ['id', 'abbreviation', 'school', 'mascot', 'conference', 'division', 'updated_at'],
        'mlb_games' => ['id', 'season', 'season_type', 'game_date', 'name', 'short_name', 'status', 'updated_at'],
        'mlb_player_injuries' => ['id', 'player_id', 'team_id', 'status', 'updated_at'],
        'mlb_player_props' => ['id', 'game_id', 'player_id', 'market', 'bookmaker', 'fetched_at', 'graded_at', 'updated_at', 'recommended_side'],
        'mlb_player_stats' => ['id', 'player_id', 'game_id', 'team_id', 'stat_type', 'updated_at'],
        'mlb_players' => ['id', 'team_id', 'first_name', 'last_name', 'full_name', 'jersey_number', 'position', 'updated_at'],
        'mlb_predictions' => ['id', 'game_id', 'updated_at', 'graded_at', 'season', 'season_type'],
        'mlb_team_metrics' => ['id', 'team_id', 'season', 'offensive_rating', 'calculation_date', 'updated_at', 'season_type'],
        'mlb_team_stats' => ['id', 'team_id', 'game_id', 'team_type', 'updated_at'],
        'mlb_teams' => ['id', 'abbreviation', 'location', 'name', 'nickname', 'league', 'division', 'updated_at'],
        'nba_games' => ['id', 'season', 'season_type', 'game_date', 'name', 'short_name', 'status', 'updated_at'],
        'nba_player_injuries' => ['id', 'player_id', 'team_id', 'status', 'updated_at'],
        'nba_player_props' => ['id', 'game_id', 'player_id', 'market', 'bookmaker', 'fetched_at', 'graded_at', 'updated_at', 'recommended_side'],
        'nba_player_stats' => ['id', 'player_id', 'game_id', 'team_id', 'updated_at'],
        'nba_players' => ['id', 'team_id', 'first_name', 'last_name', 'full_name', 'jersey_number', 'position', 'updated_at'],
        'nba_predictions' => ['id', 'game_id', 'updated_at', 'graded_at'],
        'nba_team_metrics' => ['id', 'team_id', 'season', 'offensive_efficiency', 'net_rating', 'calculation_date', 'updated_at', 'season_type'],
        'nba_team_stats' => ['id', 'team_id', 'game_id', 'team_type', 'updated_at'],
        'nba_teams' => ['id', 'abbreviation', 'location', 'name', 'conference', 'division', 'updated_at'],
        'nfl_games' => ['id', 'season', 'season_type', 'game_date', 'name', 'short_name', 'status', 'updated_at'],
        'nfl_player_injuries' => ['id', 'player_id', 'team_id', 'status', 'updated_at'],
        'nfl_player_props' => ['id', 'game_id', 'player_id', 'market', 'bookmaker', 'fetched_at', 'graded_at', 'updated_at', 'recommended_side'],
        'nfl_player_stats' => ['id', 'player_id', 'game_id', 'team_id', 'updated_at'],
        'nfl_players' => ['id', 'team_id', 'first_name', 'last_name', 'full_name', 'jersey_number', 'position', 'status', 'updated_at'],
        'nfl_predictions' => ['id', 'game_id', 'updated_at', 'graded_at'],
        'nfl_team_metrics' => ['id', 'team_id', 'season', 'offensive_rating', 'net_rating', 'calculation_date', 'updated_at', 'season_type'],
        'nfl_team_stats' => ['id', 'team_id', 'game_id', 'team_type', 'updated_at'],
        'nfl_teams' => ['id', 'abbreviation', 'location', 'name', 'conference', 'division', 'updated_at'],
        'wcbb_games' => ['id', 'season', 'season_type', 'game_date', 'name', 'short_name', 'status', 'updated_at'],
        'wcbb_player_injuries' => ['id', 'player_id', 'team_id', 'status', 'updated_at'],
        'wcbb_player_stats' => ['id', 'player_id', 'game_id', 'team_id', 'updated_at'],
        'wcbb_players' => ['id', 'team_id', 'first_name', 'last_name', 'full_name', 'jersey_number', 'position', 'updated_at'],
        'wcbb_predictions' => ['id', 'game_id', 'updated_at', 'graded_at'],
        'wcbb_team_metrics' => ['id', 'team_id', 'season', 'offensive_efficiency', 'net_rating', 'calculation_date', 'updated_at'],
        'wcbb_team_stats' => ['id', 'team_id', 'game_id', 'team_type', 'updated_at'],
        'wcbb_teams' => ['id', 'abbreviation', 'school', 'mascot', 'conference', 'division', 'updated_at'],
        'wnba_games' => ['id', 'season', 'season_type', 'game_date', 'name', 'short_name', 'status', 'updated_at'],
        'wnba_player_injuries' => ['id', 'player_id', 'team_id', 'status', 'updated_at'],
        'wnba_player_props' => ['id', 'game_id', 'player_id', 'market', 'bookmaker', 'recommended_side', 'fetched_at', 'graded_at', 'updated_at'],
        'wnba_player_stats' => ['id', 'player_id', 'game_id', 'team_id', 'updated_at'],
        'wnba_players' => ['id', 'team_id', 'first_name', 'last_name', 'full_name', 'jersey_number', 'position', 'updated_at', 'status'],
        'wnba_predictions' => ['id', 'game_id', 'updated_at', 'graded_at'],
        'wnba_team_metrics' => ['id', 'team_id', 'season', 'offensive_efficiency', 'net_rating', 'calculation_date', 'updated_at', 'season_type'],
        'wnba_team_stats' => ['id', 'team_id', 'game_id', 'team_type', 'updated_at'],
        'wnba_teams' => ['id', 'abbreviation', 'location', 'name', 'conference', 'division', 'updated_at'],
    ];

    public static function columns(string $table): array
    {
        return self::COLUMNS[$table] ?? [];
    }

    public static function hasColumn(string $table, string $column): bool
    {
        if (! in_array($column, self::SUPPORTED_COLUMNS, true)) {
            throw new \LogicException('Undeclared optional sport query column: '.$column);
        }

        return in_array($column, self::columns($table), true);
    }
}
