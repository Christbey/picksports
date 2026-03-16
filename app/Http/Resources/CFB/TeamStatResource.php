<?php

namespace App\Http\Resources\CFB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamStatResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'game_id' => $this->game_id,
            'team_type' => $this->team_type,
            'total_yards' => $this->total_yards,
            'passing_yards' => $this->passing_yards,
            'passing_completions' => $this->passing_completions,
            'passing_attempts' => $this->passing_attempts,
            'passing_touchdowns' => $this->passing_touchdowns,
            'interceptions' => $this->interceptions,
            'rushing_yards' => $this->rushing_yards,
            'rushing_attempts' => $this->rushing_attempts,
            'rushing_touchdowns' => $this->rushing_touchdowns,
            'fumbles' => $this->fumbles,
            'fumbles_lost' => $this->fumbles_lost,
            'sacks_allowed' => $this->sacks_allowed,
            'first_downs' => $this->first_downs,
            'third_down_conversions' => $this->third_down_conversions,
            'third_down_attempts' => $this->third_down_attempts,
            'fourth_down_conversions' => $this->fourth_down_conversions,
            'fourth_down_attempts' => $this->fourth_down_attempts,
            'turnovers' => ($this->interceptions ?? 0) + ($this->fumbles_lost ?? 0),
            'red_zone_attempts' => $this->red_zone_attempts,
            'red_zone_scores' => $this->red_zone_scores,
            'penalties' => $this->penalties,
            'penalty_yards' => $this->penalty_yards,
            'time_of_possession' => $this->time_of_possession,
            'possession_time' => $this->time_of_possession,
            'sacks' => $this->sacks_allowed,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
            'game' => GameResource::make($this->whenLoaded('game')),
        ];
    }
}
