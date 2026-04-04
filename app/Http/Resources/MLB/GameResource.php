<?php

namespace App\Http\Resources\MLB;

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
        return [
            'id' => $this->id,
            'espn_id' => $this->espn_event_id,
            'espn_event_id' => $this->espn_event_id,
            'espn_uid' => $this->espn_uid,
            'season' => $this->season,
            'week' => $this->week,
            'season_type' => $this->season_type,
            'game_date' => $this->game_date?->toDateString(),
            'game_time' => $this->game_time,
            'venue' => $this->venue_name,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'home_team_id' => $this->home_team_id,
            'away_team_id' => $this->away_team_id,
            'home_score' => $this->home_score,
            'away_score' => $this->away_score,
            'home_linescores' => $this->home_linescores,
            'away_linescores' => $this->away_linescores,
            'status' => $this->status,
            'inning' => $this->inning,
            'inning_half' => $this->inning_half,
            'balls' => $this->balls,
            'strikes' => $this->strikes,
            'outs' => $this->outs,
            'probable_home_pitcher_espn_id' => $this->probable_home_pitcher_espn_id,
            'probable_away_pitcher_espn_id' => $this->probable_away_pitcher_espn_id,
            'venue_name' => $this->venue_name,
            'venue_city' => $this->venue_city,
            'venue_state' => $this->venue_state,
            'broadcast_networks' => $this->broadcast_networks,
            'matchup_context' => $this->resource->getAttribute('matchup_context'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'home_team' => TeamResource::make($this->whenLoaded('homeTeam')),
            'away_team' => TeamResource::make($this->whenLoaded('awayTeam')),
            'home_starting_pitcher' => PlayerResource::make($this->whenLoaded('probableHomePitcher')),
            'away_starting_pitcher' => PlayerResource::make($this->whenLoaded('probableAwayPitcher')),
            'team_stats' => TeamStatResource::collection($this->whenLoaded('teamStats')),
        ];
    }
}
