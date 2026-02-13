<?php

namespace App\Http\Resources\NFL;

use App\Actions\NFL\CalculateLiveWinProbability;
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
            'home_team_id' => $this->home_team_id,
            'away_team_id' => $this->away_team_id,
            'season' => $this->season,
            'season_type' => $this->season_type,
            'week' => $this->week,
            'game_date' => $this->game_date ? date('Y-m-d', strtotime($this->game_date)) : null,
            'game_time' => $this->game_time,
            'venue' => $this->venue_name,
            'attendance' => $this->attendance,
            'status' => $this->status,
            'period' => $this->period,
            'clock' => $this->game_clock,
            'home_score' => $this->home_score,
            'away_score' => $this->away_score,
            'home_linescores' => $this->home_linescores,
            'away_linescores' => $this->away_linescores,
            'broadcast_networks' => $this->broadcast_networks,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'home_team' => TeamResource::make($this->whenLoaded('homeTeam')),
            'away_team' => TeamResource::make($this->whenLoaded('awayTeam')),
            'prediction' => PredictionResource::make($this->whenLoaded('prediction')),
            'live_win_probability' => app(CalculateLiveWinProbability::class)->execute($this->resource),
        ];
    }
}
