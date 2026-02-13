<?php

namespace App\Http\Resources\MLB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
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
            'espn_id' => $this->espn_id,
            'abbreviation' => $this->abbreviation,
            'location' => $this->location,
            'name' => $this->name,
            'nickname' => $this->nickname,
            'league' => $this->league,
            'division' => $this->division,
            'color' => $this->color,
            'logo_url' => $this->logo_url,
            'elo_rating' => (float) $this->elo_rating,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
