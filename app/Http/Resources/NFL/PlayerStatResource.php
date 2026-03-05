<?php

namespace App\Http\Resources\NFL;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerStatResource extends JsonResource
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
            'player_id' => $this->player_id,
            'game_id' => $this->game_id,
            'team_id' => $this->team_id,
            'passing_completions' => $this->passing_completions,
            'passing_attempts' => $this->passing_attempts,
            'passing_yards' => $this->passing_yards,
            'passing_touchdowns' => $this->passing_touchdowns,
            'interceptions_thrown' => $this->interceptions_thrown,
            'rushing_attempts' => $this->rushing_attempts,
            'rushing_yards' => $this->rushing_yards,
            'rushing_touchdowns' => $this->rushing_touchdowns,
            'receptions' => $this->receptions,
            'receiving_yards' => $this->receiving_yards,
            'receiving_touchdowns' => $this->receiving_touchdowns,
            'receiving_targets' => $this->receiving_targets,
            'tackles_total' => $this->tackles_total,
            'sacks' => $this->sacks,
            'interceptions' => $this->interceptions,
            'passes_defended' => $this->passes_defended,
            'fumbles_recovered' => $this->fumbles_recovered,
            'field_goals_made' => $this->field_goals_made,
            'field_goals_attempted' => $this->field_goals_attempted,
            'extra_points_made' => $this->extra_points_made,
            'extra_points_attempted' => $this->extra_points_attempted,
            'total_yards' => (int) (($this->passing_yards ?? 0) + ($this->rushing_yards ?? 0) + ($this->receiving_yards ?? 0)),
            'total_touchdowns' => (int) (($this->passing_touchdowns ?? 0) + ($this->rushing_touchdowns ?? 0) + ($this->receiving_touchdowns ?? 0)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'player' => PlayerResource::make($this->whenLoaded('player')),
            'game' => GameResource::make($this->whenLoaded('game')),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
