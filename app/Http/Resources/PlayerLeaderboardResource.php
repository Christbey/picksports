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
            'steals_per_game' => data_get($this->resource, 'steals_per_game'),
            'blocks_per_game' => data_get($this->resource, 'blocks_per_game'),
            'minutes_per_game' => data_get($this->resource, 'minutes_per_game'),
            'field_goal_percentage' => data_get($this->resource, 'field_goal_percentage'),
            'three_point_percentage' => data_get($this->resource, 'three_point_percentage'),
            'free_throw_percentage' => data_get($this->resource, 'free_throw_percentage'),
        ];
    }
}
