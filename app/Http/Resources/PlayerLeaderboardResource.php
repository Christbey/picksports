<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerLeaderboardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'player_id' => data_get($this->resource, 'player_id'),
            'player' => data_get($this->resource, 'player'),
            'games_played' => data_get($this->resource, 'games_played'),
            'points_per_game' => data_get($this->resource, 'points_per_game'),
            'rebounds_per_game' => data_get($this->resource, 'rebounds_per_game'),
            'assists_per_game' => data_get($this->resource, 'assists_per_game'),
            'turnovers_per_game' => data_get($this->resource, 'turnovers_per_game'),
            'steals_per_game' => data_get($this->resource, 'steals_per_game'),
            'blocks_per_game' => data_get($this->resource, 'blocks_per_game'),
            'minutes_per_game' => data_get($this->resource, 'minutes_per_game'),
            'field_goal_percentage' => data_get($this->resource, 'field_goal_percentage'),
            'three_point_percentage' => data_get($this->resource, 'three_point_percentage'),
            'free_throw_percentage' => data_get($this->resource, 'free_throw_percentage'),
            'estimated_epa_per_game' => data_get($this->resource, 'estimated_epa_per_game'),
            'estimated_epa_per_36' => data_get($this->resource, 'estimated_epa_per_36'),
            'passing_yards_per_game' => data_get($this->resource, 'passing_yards_per_game'),
            'passing_touchdowns_per_game' => data_get($this->resource, 'passing_touchdowns_per_game'),
            'completion_percentage' => data_get($this->resource, 'completion_percentage'),
            'interceptions_thrown_per_game' => data_get($this->resource, 'interceptions_thrown_per_game'),
            'passing_yards_total' => data_get($this->resource, 'passing_yards_total'),
            'passing_touchdowns_total' => data_get($this->resource, 'passing_touchdowns_total'),
            'interceptions_thrown_total' => data_get($this->resource, 'interceptions_thrown_total'),
            'passing_attempts_total' => data_get($this->resource, 'passing_attempts_total'),
            'passing_completions_total' => data_get($this->resource, 'passing_completions_total'),
            'qbr' => data_get($this->resource, 'qbr'),
            'rushing_yards_per_game' => data_get($this->resource, 'rushing_yards_per_game'),
            'rushing_touchdowns_per_game' => data_get($this->resource, 'rushing_touchdowns_per_game'),
            'yards_per_carry' => data_get($this->resource, 'yards_per_carry'),
            'receptions_per_game' => data_get($this->resource, 'receptions_per_game'),
            'receiving_yards_per_game' => data_get($this->resource, 'receiving_yards_per_game'),
            'rushing_yards_total' => data_get($this->resource, 'rushing_yards_total'),
            'rushing_touchdowns_total' => data_get($this->resource, 'rushing_touchdowns_total'),
            'rushing_attempts_total' => data_get($this->resource, 'rushing_attempts_total'),
            'receptions_total' => data_get($this->resource, 'receptions_total'),
            'receiving_yards_total' => data_get($this->resource, 'receiving_yards_total'),
            'tackles_per_game' => data_get($this->resource, 'tackles_per_game'),
            'sacks_per_game' => data_get($this->resource, 'sacks_per_game'),
            'def_interceptions_per_game' => data_get($this->resource, 'def_interceptions_per_game'),
            'passes_defended_per_game' => data_get($this->resource, 'passes_defended_per_game'),
            'fumbles_recovered_per_game' => data_get($this->resource, 'fumbles_recovered_per_game'),
            'tackles_total' => data_get($this->resource, 'tackles_total'),
            'sacks_total' => data_get($this->resource, 'sacks_total'),
            'def_interceptions_total' => data_get($this->resource, 'def_interceptions_total'),
            'passes_defended_total' => data_get($this->resource, 'passes_defended_total'),
            'fumbles_recovered_total' => data_get($this->resource, 'fumbles_recovered_total'),
            'field_goals_made_per_game' => data_get($this->resource, 'field_goals_made_per_game'),
            'extra_points_made_per_game' => data_get($this->resource, 'extra_points_made_per_game'),
            'field_goal_percentage_special' => data_get($this->resource, 'field_goal_percentage_special'),
            'extra_point_percentage' => data_get($this->resource, 'extra_point_percentage'),
            'field_goals_made_total' => data_get($this->resource, 'field_goals_made_total'),
            'field_goals_attempted_total' => data_get($this->resource, 'field_goals_attempted_total'),
            'extra_points_made_total' => data_get($this->resource, 'extra_points_made_total'),
            'extra_points_attempted_total' => data_get($this->resource, 'extra_points_attempted_total'),
        ];
    }
}
