<?php

namespace App\Http\Resources\MLB;

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
            'offensive_rating' => $this->offensive_rating,
            'pitching_rating' => $this->pitching_rating,
            'defensive_rating' => $this->defensive_rating,
            'runs_per_game' => $this->runs_per_game,
            'runs_allowed_per_game' => $this->runs_allowed_per_game,
            'run_differential_per_game' => $this->run_differential_per_game,
            'home_runs_per_game' => $this->home_runs_per_game,
            'batting_average' => $this->batting_average,
            'on_base_percentage' => $this->on_base_percentage,
            'slugging_percentage' => $this->slugging_percentage,
            'ops' => $this->ops,
            'team_era' => $this->team_era,
            'strikeouts_pitched_per_game' => $this->strikeouts_pitched_per_game,
            'whip' => $this->whip,
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
