<?php

namespace App\Http\Resources\WNBA;

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
            'points' => $this->points,
            'field_goals_made' => $this->field_goals_made,
            'field_goals_attempted' => $this->field_goals_attempted,
            'field_goal_percentage' => $this->field_goal_percentage,
            'three_pointers_made' => $this->three_pointers_made,
            'three_pointers_attempted' => $this->three_pointers_attempted,
            'three_point_percentage' => $this->three_point_percentage,
            'free_throws_made' => $this->free_throws_made,
            'free_throws_attempted' => $this->free_throws_attempted,
            'free_throw_percentage' => $this->free_throw_percentage,
            'rebounds' => $this->rebounds,
            'offensive_rebounds' => $this->offensive_rebounds,
            'defensive_rebounds' => $this->defensive_rebounds,
            'assists' => $this->assists,
            'steals' => $this->steals,
            'blocks' => $this->blocks,
            'turnovers' => $this->turnovers,
            'fouls' => $this->fouls,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
            'game' => GameResource::make($this->whenLoaded('game')),
        ];
    }
}
