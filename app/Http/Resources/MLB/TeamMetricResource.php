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
            'offensive_rating' => $this->offensive_rating,
            'pitching_rating' => $this->pitching_rating,
            'defensive_rating' => $this->defensive_rating,
            'runs_per_game' => $this->runs_per_game,
            'runs_allowed_per_game' => $this->runs_allowed_per_game,
            'batting_average' => $this->batting_average,
            'team_era' => $this->team_era,
            'strength_of_schedule' => $this->strength_of_schedule,
            'calculation_date' => $this->calculation_date,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
