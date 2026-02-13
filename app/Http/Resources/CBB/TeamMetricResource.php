<?php

namespace App\Http\Resources\CBB;

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
            'offensive_efficiency' => $this->offensive_efficiency,
            'defensive_efficiency' => $this->defensive_efficiency,
            'offensive_rating' => $this->offensive_efficiency,
            'defensive_rating' => $this->defensive_efficiency,
            'net_rating' => $this->net_rating,
            'tempo' => $this->tempo,
            'pace' => $this->tempo,
            'strength_of_schedule' => $this->strength_of_schedule,
            'calculation_date' => $this->calculation_date,
            // Minimum games tracking
            'games_played' => $this->games_played,
            'meets_minimum' => $this->meets_minimum,
            // Adjusted metrics
            'adj_offensive_efficiency' => $this->adj_offensive_efficiency,
            'adj_defensive_efficiency' => $this->adj_defensive_efficiency,
            'adj_net_rating' => $this->adj_net_rating,
            'adj_tempo' => $this->adj_tempo,
            // Rolling window metrics
            'rolling_offensive_efficiency' => $this->rolling_offensive_efficiency,
            'rolling_defensive_efficiency' => $this->rolling_defensive_efficiency,
            'rolling_net_rating' => $this->rolling_net_rating,
            'rolling_tempo' => $this->rolling_tempo,
            'rolling_games_count' => $this->rolling_games_count,
            // Home/Away splits
            'home_offensive_efficiency' => $this->home_offensive_efficiency,
            'home_defensive_efficiency' => $this->home_defensive_efficiency,
            'away_offensive_efficiency' => $this->away_offensive_efficiency,
            'away_defensive_efficiency' => $this->away_defensive_efficiency,
            'home_games' => $this->home_games,
            'away_games' => $this->away_games,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
