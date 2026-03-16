<?php

namespace App\Http\Resources\CFB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayResource extends JsonResource
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
            'game_id' => $this->game_id,
            'possession_team_id' => $this->possession_team_id,
            'espn_play_id' => $this->espn_play_id,
            'sequence_number' => $this->sequence_number,
            'period' => $this->period,
            'clock' => $this->clock,
            'down' => $this->down,
            'distance' => $this->distance,
            'yards_to_endzone' => $this->yards_to_endzone,
            'play_type' => $this->play_type,
            'play_text' => $this->play_text,
            'yards_gained' => $this->yards_gained,
            'is_scoring_play' => $this->is_scoring_play,
            'is_turnover' => $this->is_turnover,
            'is_penalty' => $this->is_penalty,
            'home_score' => $this->home_score,
            'away_score' => $this->away_score,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'possession_team' => TeamResource::make($this->whenLoaded('possessionTeam')),
        ];
    }
}
