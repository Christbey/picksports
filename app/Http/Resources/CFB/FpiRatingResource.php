<?php

namespace App\Http\Resources\CFB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FpiRatingResource extends JsonResource
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
            'fpi_rating' => $this->fpi_rating,
            'fpi_rank' => $this->fpi_rank,
            'offensive_fpi' => $this->offensive_fpi,
            'defensive_fpi' => $this->defensive_fpi,
            'special_teams_fpi' => $this->special_teams_fpi,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
