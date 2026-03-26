<?php

namespace App\Http\Resources\CBB;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Convert game datetime from UTC to Eastern Time
        $utcDatetime = Carbon::parse($this->game_date->toDateString().' '.$this->game_time, 'UTC');
        $etDatetime = $utcDatetime->setTimezone('America/New_York');

        return [
            'id' => $this->id,
            'espn_id' => $this->espn_event_id ?? $this->espn_id,
            'home_team_id' => $this->home_team_id,
            'away_team_id' => $this->away_team_id,
            'home_team_display_name' => $this->home_team_display_name,
            'away_team_display_name' => $this->away_team_display_name,
            'home_team_abbreviation' => $this->home_team_abbreviation,
            'away_team_abbreviation' => $this->away_team_abbreviation,
            'season' => $this->season,
            'season_type' => $this->season_type,
            'week' => $this->week,
            'is_ncaa_tournament' => (bool) ($this->is_ncaa_tournament ?? false),
            'tournament_id' => $this->tournament_id,
            'tournament_note' => $this->tournament_note,
            'tournament_round' => $this->tournament_round,
            'tournament_region' => $this->tournament_region,
            'home_seed' => $this->home_seed,
            'away_seed' => $this->away_seed,
            'play_in_target_seed' => $this->play_in_target_seed,
            'game_date' => $etDatetime->toDateString(),
            'game_time' => $etDatetime->toTimeString(),
            'venue' => $this->venue_name ?? $this->venue,
            'venue_name' => $this->venue_name ?? $this->venue,
            'attendance' => $this->attendance,
            'status' => $this->status,
            'period' => $this->period,
            'clock' => $this->game_clock ?? $this->clock,
            'game_clock' => $this->game_clock ?? $this->clock,
            'home_score' => $this->home_score,
            'away_score' => $this->away_score,
            'home_linescores' => $this->home_linescores,
            'away_linescores' => $this->away_linescores,
            'broadcast_networks' => $this->broadcast_networks,
            'matchup_context' => $this->resource->getAttribute('matchup_context'),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'home_team' => TeamResource::make($this->whenLoaded('homeTeam')),
            'away_team' => TeamResource::make($this->whenLoaded('awayTeam')),
            'team_stats' => TeamStatResource::collection($this->whenLoaded('teamStats')),
        ];
    }
}
