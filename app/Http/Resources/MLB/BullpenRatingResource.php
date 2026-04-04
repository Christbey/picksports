<?php

namespace App\Http\Resources\MLB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BullpenRatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'season' => $this->season,
            'season_type' => $this->season_type,
            'as_of_date' => $this->as_of_date?->toDateString(),
            'games_sampled' => $this->games_sampled,
            'weighted_usage' => $this->weighted_usage !== null ? (float) $this->weighted_usage : null,
            'weighted_era' => $this->weighted_era !== null ? (float) $this->weighted_era : null,
            'weighted_whip' => $this->weighted_whip !== null ? (float) $this->weighted_whip : null,
            'strikeouts_per_nine' => $this->strikeouts_per_nine !== null ? (float) $this->strikeouts_per_nine : null,
            'walks_per_nine' => $this->walks_per_nine !== null ? (float) $this->walks_per_nine : null,
            'home_runs_per_nine' => $this->home_runs_per_nine !== null ? (float) $this->home_runs_per_nine : null,
            'recent_form_score' => $this->recent_form_score !== null ? (float) $this->recent_form_score : null,
            'workload_penalty' => $this->workload_penalty !== null ? (float) $this->workload_penalty : null,
            'rating_score' => (float) $this->rating_score,
            'rating_rank' => $this->rating_rank,
            'calculation_date' => $this->calculation_date?->toDateString(),
            'team' => TeamResource::make($this->whenLoaded('team')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
