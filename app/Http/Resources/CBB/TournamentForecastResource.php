<?php

namespace App\Http\Resources\CBB;

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
            'snapshot_id' => $this->snapshot_id,
            'placeholder_key' => $this->placeholder_key !== '' ? $this->placeholder_key : null,
            'season' => $this->season,
            'as_of' => $this->as_of?->toIso8601String(),
            'mode' => $this->mode,
            'region' => $this->region,
            'seed' => $this->seed,
            'team_display_name' => $this->team_display_name,
            'team_abbreviation' => $this->team_abbreviation,
            'is_first_four' => (bool) $this->is_first_four,
            'is_alive' => (bool) $this->is_alive,
            'is_eliminated' => (bool) $this->is_eliminated,
            'reached_round' => $this->reached_round,
            'eliminated_round' => $this->eliminated_round,
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
            'games_final_count' => (int) $this->games_final_count,
            'round_of_32_probability' => (float) $this->round_of_32_probability,
            'sweet_16_probability' => (float) $this->sweet_16_probability,
            'elite_8_probability' => (float) $this->elite_8_probability,
            'simulation_runs' => (int) $this->simulation_runs,
            'team' => $this->team
                ? TeamResource::make($this->team)->resolve($request)
                : $this->placeholderTeamPayload(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function placeholderTeamPayload(): ?array
    {
        if (! $this->team_display_name && ! $this->team_abbreviation) {
            return null;
        }

        return [
            'id' => null,
            'espn_id' => null,
            'abbreviation' => $this->team_abbreviation,
            'location' => $this->team_display_name,
            'school' => $this->team_display_name,
            'mascot' => null,
            'name' => $this->team_display_name,
            'display_name' => $this->team_display_name,
            'short_display_name' => $this->team_abbreviation ?: $this->team_display_name,
            'conference' => null,
            'division' => null,
            'color' => null,
            'logo' => null,
            'logo_url' => null,
        ];
    }
}
