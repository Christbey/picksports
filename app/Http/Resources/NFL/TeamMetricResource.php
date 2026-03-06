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
            'recent_form_rating' => $this->recent_form_rating,
            'injury_adjusted_team_rating' => $this->injury_adjusted_team_rating,
            'rest_travel_fatigue' => $this->rest_travel_fatigue,
            'predictive_rating' => $this->predictive_rating,
            'home_rating' => $this->home_rating,
            'away_rating' => $this->away_rating,
            'home_advantage_rating' => $this->home_advantage_rating,
            'future_strength_of_schedule' => $this->future_strength_of_schedule,
            'season_strength_of_schedule' => $this->season_strength_of_schedule,
            'strength_of_schedule_basic' => $this->strength_of_schedule_basic,
            'in_division_strength_of_schedule' => $this->in_division_strength_of_schedule,
            'non_division_strength_of_schedule' => $this->non_division_strength_of_schedule,
            'last_5_rating' => $this->last_5_rating,
            'last_10_rating' => $this->last_10_rating,
            'in_division_rating' => $this->in_division_rating,
            'non_division_rating' => $this->non_division_rating,
            'luck_rating' => $this->luck_rating,
            'consistency_rating' => $this->consistency_rating,
            'vs_1_to_5_rating' => $this->vs_1_to_5_rating,
            'vs_6_to_10_rating' => $this->vs_6_to_10_rating,
            'vs_11_to_16_rating' => $this->vs_11_to_16_rating,
            'vs_17_to_22_rating' => $this->vs_17_to_22_rating,
            'vs_23_to_32_rating' => $this->vs_23_to_32_rating,
            'first_half_rating' => $this->first_half_rating,
            'second_half_rating' => $this->second_half_rating,
            'offensive_true_epa_per_play' => $this->offensive_true_epa_per_play,
            'defensive_true_epa_per_play' => $this->defensive_true_epa_per_play,
            'net_true_epa_per_play' => $this->net_true_epa_per_play,
            'calculation_date' => $this->calculation_date,
            'wins' => $this->wins ?? null,
            'losses' => $this->losses ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
