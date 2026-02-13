<?php

namespace App\Http\Resources\CBB;

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
            'minutes_played' => $this->minutes_played,
            'field_goals_made' => $this->field_goals_made,
            'field_goals_attempted' => $this->field_goals_attempted,
            'three_point_made' => $this->three_point_made,
            'three_point_attempted' => $this->three_point_attempted,
            'free_throws_made' => $this->free_throws_made,
            'free_throws_attempted' => $this->free_throws_attempted,
            'rebounds_offensive' => $this->rebounds_offensive,
            'rebounds_defensive' => $this->rebounds_defensive,
            'rebounds' => $this->rebounds_total,
            'rebounds_total' => $this->rebounds_total,
            'assists' => $this->assists,
            'turnovers' => $this->turnovers,
            'steals' => $this->steals,
            'blocks' => $this->blocks,
            'fouls' => $this->fouls,
            'points' => $this->points,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'player' => PlayerResource::make($this->whenLoaded('player')),
            'game' => GameResource::make($this->whenLoaded('game')),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
