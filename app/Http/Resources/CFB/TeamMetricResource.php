<?php

namespace App\Http\Resources\CFB;

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
            'offensive_efficiency' => $this->offensive_rating,
            'defensive_efficiency' => $this->defensive_rating,
            'offensive_rating' => $this->offensive_rating,
            'defensive_rating' => $this->defensive_rating,
            'net_rating' => $this->net_rating,
            'cfp_rating' => $this->cfp_rating,
            'power_rating' => $this->power_rating,
            'resume_rating' => $this->resume_rating,
            'tempo' => $this->tempo,
            'pace' => $this->tempo,
            'strength_of_schedule' => $this->strength_of_schedule,
            'recent_form_rating' => $this->recent_form_rating,
            'injury_adjusted_team_rating' => $this->injury_adjusted_team_rating,
            'rest_travel_fatigue' => $this->rest_travel_fatigue,
            'calculation_date' => $this->calculation_date,
            'wins' => $this->wins ?? null,
            'losses' => $this->losses ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
