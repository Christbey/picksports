<?php

namespace App\Http\Resources\MLB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayoffForecastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'season' => (int) $this->season,
            'league' => $this->league,
            'league_rank' => $this->league_rank !== null ? (int) $this->league_rank : null,
            'projected_seed' => $this->projected_seed !== null ? (int) $this->projected_seed : null,
            'selection_score' => (float) $this->selection_score,
            'playoff_make_probability' => (float) $this->playoff_make_probability,
            'league_championship_probability' => (float) $this->league_championship_probability,
            'world_series_probability' => (float) $this->world_series_probability,
            'champion_probability' => (float) $this->champion_probability,
            'simulation_runs' => (int) $this->simulation_runs,
            'team' => TeamResource::make($this->whenLoaded('team')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
