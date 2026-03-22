<?php

namespace App\Http\Resources\CFB;

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
        $fullName = $this->full_name ?? $this->display_name ?? $this->name;
        $jerseyNumber = $this->jersey_number ?? $this->jersey;
        $headshotUrl = $this->headshot_url ?? $this->headshot;

        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'espn_id' => $this->espn_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $fullName,
            'name' => $fullName,
            'display_name' => $fullName,
            'short_name' => $fullName,
            'jersey' => $jerseyNumber,
            'jersey_number' => $jerseyNumber,
            'position' => $this->position,
            'height' => $this->height,
            'weight' => $this->weight,
            'experience' => $this->year,
            'year' => $this->year,
            'hometown' => $this->hometown,
            'college' => null,
            'headshot' => $headshotUrl,
            'headshot_url' => $headshotUrl,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
