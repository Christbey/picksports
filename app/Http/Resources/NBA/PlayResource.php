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
            'espn_id' => $this->espn_id,
            'sequence_number' => $this->sequence_number,
            'period' => $this->period,
            'clock' => $this->clock,
            'down' => $this->down,
            'distance' => $this->distance,
            'yard_line' => $this->yard_line,
            'play_type' => $this->play_type,
            'play_text' => $this->play_text,
            'yards_gained' => $this->yards_gained,
            'scoring_play' => $this->scoring_play,
            'touchdown' => $this->touchdown,
            'field_goal' => $this->field_goal,
            'safety' => $this->safety,
            'turnover' => $this->turnover,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'possession_team' => TeamResource::make($this->whenLoaded('possessionTeam')),
        ];
    }
}
