<?php

namespace App\Http\Resources\NBA;

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
            'conference' => $this->conference,
            'conference_rank' => $this->conference_rank !== null ? (int) $this->conference_rank : null,
            'projected_seed' => $this->projected_seed !== null ? (int) $this->projected_seed : null,
            'selection_score' => (float) $this->selection_score,
            'playoff_make_probability' => (float) $this->playoff_make_probability,
            'direct_playoff_probability' => (float) $this->direct_playoff_probability,
            'play_in_tournament_probability' => (float) $this->play_in_tournament_probability,
            'division_win_probability' => (float) $this->division_win_probability,
            'conference_finals_probability' => (float) $this->conference_finals_probability,
            'nba_finals_probability' => (float) $this->nba_finals_probability,
            'champion_probability' => (float) $this->champion_probability,
            'simulation_runs' => (int) $this->simulation_runs,
            'team' => TeamResource::make($this->whenLoaded('team')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
