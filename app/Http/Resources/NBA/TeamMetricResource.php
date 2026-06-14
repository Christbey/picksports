<?php

namespace App\Http\Resources\NBA;

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
            'season_type' => $this->season_type,
            'offensive_efficiency' => $this->offensive_efficiency,
            'defensive_efficiency' => $this->defensive_efficiency,
            'offensive_rating' => $this->offensive_efficiency,
            'defensive_rating' => $this->defensive_efficiency,
            'net_rating' => $this->net_rating,
            'tempo' => $this->tempo,
            'pace' => $this->tempo,
            'strength_of_schedule' => $this->strength_of_schedule,
            'recent_form_rating' => $this->recent_form_rating,
            'injury_adjusted_team_rating' => $this->injury_adjusted_team_rating,
            'injury_total_adjustment' => $this->injury_total_adjustment,
            'rest_travel_fatigue' => $this->rest_travel_fatigue,
            'offensive_true_epa_per_play' => $this->offensive_true_epa_per_play,
            'defensive_true_epa_per_play' => $this->defensive_true_epa_per_play,
            'net_true_epa_per_play' => $this->net_true_epa_per_play,
            'wins' => $this->wins ?? null,
            'losses' => $this->losses ?? null,
            'calculation_date' => $this->calculation_date,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
