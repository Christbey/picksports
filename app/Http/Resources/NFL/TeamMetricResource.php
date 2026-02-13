<?php

namespace App\Http\Resources\NFL;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMetricResource extends JsonResource
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
            'season' => $this->season,
            'offensive_rating' => $this->offensive_rating,
            'defensive_rating' => $this->defensive_rating,
            'net_rating' => $this->net_rating,
            'points_per_game' => $this->points_per_game,
            'points_allowed_per_game' => $this->points_allowed_per_game,
            'yards_per_game' => $this->yards_per_game,
            'yards_allowed_per_game' => $this->yards_allowed_per_game,
            'passing_yards_per_game' => $this->passing_yards_per_game,
            'rushing_yards_per_game' => $this->rushing_yards_per_game,
            'turnover_differential' => $this->turnover_differential,
            'strength_of_schedule' => $this->strength_of_schedule,
            'calculation_date' => $this->calculation_date,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
