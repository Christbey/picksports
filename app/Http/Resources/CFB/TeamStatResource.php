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
            'total_yards' => $this->total_yards,
            'passing_yards' => $this->passing_yards,
            'rushing_yards' => $this->rushing_yards,
            'first_downs' => $this->first_downs,
            'third_down_conversions' => $this->third_down_conversions,
            'third_down_attempts' => $this->third_down_attempts,
            'fourth_down_conversions' => $this->fourth_down_conversions,
            'fourth_down_attempts' => $this->fourth_down_attempts,
            'turnovers' => $this->turnovers,
            'penalties' => $this->penalties,
            'penalty_yards' => $this->penalty_yards,
            'possession_time' => $this->possession_time,
            'sacks' => $this->sacks,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
            'game' => GameResource::make($this->whenLoaded('game')),
        ];
    }
}
