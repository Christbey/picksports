<?php

namespace App\Http\Resources\NBA;

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
            'espn_id' => $this->espn_play_id ?? $this->espn_id,
            'sequence_number' => $this->sequence_number,
            'period' => $this->period,
            'clock' => $this->clock,
            'play_type' => $this->play_type,
            'play_text' => $this->play_text,
            'score_value' => $this->score_value,
            'shooting_play' => (bool) $this->shooting_play,
            'made_shot' => (bool) $this->made_shot,
            'assist' => (bool) $this->assist,
            'is_turnover' => (bool) $this->is_turnover,
            'is_foul' => (bool) $this->is_foul,
            'scoring_play' => $this->scoring_play,
            'home_score' => $this->home_score,
            'away_score' => $this->away_score,
            'is_epa_eligible' => (bool) $this->is_epa_eligible,
            'expected_points_before' => $this->expected_points_before,
            'expected_points_after' => $this->expected_points_after,
            'true_epa' => $this->true_epa,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'possession_team' => TeamResource::make($this->whenLoaded('possessionTeam')),
        ];
    }
}
