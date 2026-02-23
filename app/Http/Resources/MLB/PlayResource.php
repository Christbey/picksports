<?php

namespace App\Http\Resources\MLB;

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
            'espn_play_id' => $this->espn_play_id,
            'sequence_number' => $this->sequence_number,
            'inning' => $this->inning,
            'inning_half' => $this->inning_half,
            'play_type' => $this->play_type,
            'play_text' => $this->play_text,
            'score_value' => $this->score_value,
            'is_at_bat' => $this->is_at_bat,
            'is_scoring_play' => $this->is_scoring_play,
            'is_out' => $this->is_out,
            'balls' => $this->balls,
            'strikes' => $this->strikes,
            'outs' => $this->outs,
            'home_score' => $this->home_score,
            'away_score' => $this->away_score,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'game' => GameResource::make($this->whenLoaded('game')),
        ];
    }
}
