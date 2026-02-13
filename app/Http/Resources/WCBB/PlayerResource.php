<?php

namespace App\Http\Resources\WCBB;

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
            'name' => $this->name,
            'display_name' => $this->display_name,
            'short_name' => $this->short_name,
            'jersey' => $this->jersey,
            'position' => $this->position,
            'height' => $this->height,
            'weight' => $this->weight,
            'experience' => $this->experience,
            'college' => $this->college,
            'headshot' => $this->headshot,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
