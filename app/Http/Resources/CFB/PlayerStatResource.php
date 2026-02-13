<?php

namespace App\Http\Resources\CFB;

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
            'stat_type' => $this->stat_type,
            'completions' => $this->completions,
            'attempts' => $this->attempts,
            'passing_yards' => $this->passing_yards,
            'passing_touchdowns' => $this->passing_touchdowns,
            'interceptions' => $this->interceptions,
            'carries' => $this->carries,
            'rushing_yards' => $this->rushing_yards,
            'rushing_touchdowns' => $this->rushing_touchdowns,
            'receptions' => $this->receptions,
            'receiving_yards' => $this->receiving_yards,
            'receiving_touchdowns' => $this->receiving_touchdowns,
            'targets' => $this->targets,
            'fumbles' => $this->fumbles,
            'fumbles_lost' => $this->fumbles_lost,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'player' => PlayerResource::make($this->whenLoaded('player')),
            'game' => GameResource::make($this->whenLoaded('game')),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
