<?php

namespace App\Http\Resources\CBB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerResource extends JsonResource
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
            'espn_id' => $this->espn_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'name' => $this->full_name, // For frontend compatibility
            'jersey_number' => $this->jersey_number,
            'position' => $this->position,
            'height' => $this->height,
            'weight' => $this->weight,
            'year' => $this->year,
            'hometown' => $this->hometown,
            'headshot_url' => $this->headshot_url,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
