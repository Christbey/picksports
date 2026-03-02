<?php

namespace App\Http\Resources\WCBB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TournamentForecastResource extends JsonResource
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
            'selection_score' => (float) $this->selection_score,
            'projected_seed' => $this->projected_seed,
            'auto_bid' => (bool) $this->auto_bid,
            'auto_bid_probability' => (float) $this->auto_bid_probability,
            'at_large_probability' => (float) $this->at_large_probability,
            'tournament_make_probability' => (float) $this->tournament_make_probability,
            'first_four_probability' => (float) $this->first_four_probability,
            'first_four_auto_probability' => (float) $this->first_four_auto_probability,
            'first_four_at_large_probability' => (float) $this->first_four_at_large_probability,
            'bid_thief_probability' => (float) $this->bid_thief_probability,
            'champion_probability' => (float) $this->champion_probability,
            'final_four_probability' => (float) $this->final_four_probability,
            'title_game_probability' => (float) $this->title_game_probability,
            'simulation_runs' => (int) $this->simulation_runs,
            'team' => TeamResource::make($this->whenLoaded('team')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
