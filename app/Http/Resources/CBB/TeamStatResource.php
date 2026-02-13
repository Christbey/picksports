<?php

namespace App\Http\Resources\CBB;

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
            'field_goals_made' => $this->field_goals_made,
            'field_goals_attempted' => $this->field_goals_attempted,
            'three_point_made' => $this->three_point_made,
            'three_point_attempted' => $this->three_point_attempted,
            'free_throws_made' => $this->free_throws_made,
            'free_throws_attempted' => $this->free_throws_attempted,
            'rebounds' => $this->rebounds,
            'offensive_rebounds' => $this->offensive_rebounds,
            'defensive_rebounds' => $this->defensive_rebounds,
            'assists' => $this->assists,
            'turnovers' => $this->turnovers,
            'steals' => $this->steals,
            'blocks' => $this->blocks,
            'fouls' => $this->fouls,
            'points' => $this->points,
            'possessions' => $this->possessions,
            'fast_break_points' => $this->fast_break_points,
            'points_in_paint' => $this->points_in_paint,
            'second_chance_points' => $this->second_chance_points,
            'bench_points' => $this->bench_points,
            'biggest_lead' => $this->biggest_lead,
            'times_tied' => $this->times_tied,
            'lead_changes' => $this->lead_changes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
            'game' => GameResource::make($this->whenLoaded('game')),
        ];
    }
}
