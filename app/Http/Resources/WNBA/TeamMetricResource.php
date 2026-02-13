<?php

namespace App\Http\Resources\WNBA;

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
            'pace' => $this->pace,
            'effective_field_goal_percentage' => $this->effective_field_goal_percentage,
            'turnover_percentage' => $this->turnover_percentage,
            'offensive_rebound_percentage' => $this->offensive_rebound_percentage,
            'free_throw_rate' => $this->free_throw_rate,
            'true_shooting_percentage' => $this->true_shooting_percentage,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
