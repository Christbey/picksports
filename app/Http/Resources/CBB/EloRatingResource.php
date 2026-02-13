<?php

namespace App\Http\Resources\CBB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EloRatingResource extends JsonResource
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
            'week' => $this->week,
            'elo_rating' => $this->elo_rating,
            'elo_change' => $this->elo_change,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
