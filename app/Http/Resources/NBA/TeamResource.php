<?php

namespace App\Http\Resources\NBA;

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
        // Construct full team name from location + name (e.g., "Los Angeles Lakers")
        $displayName = trim(($this->location ?? '').' '.($this->name ?? ''));

        return [
            'id' => $this->id,
            'espn_id' => $this->espn_id,
            'abbreviation' => $this->abbreviation,
            'location' => $this->location,
            'name' => $this->name,
            'display_name' => $displayName ?: $this->name,
            'short_display_name' => $this->abbreviation ?? $this->name,
            'conference' => $this->conference,
            'division' => $this->division,
            'color' => $this->color,
            'alternate_color' => $this->alternate_color ?? null,
            'logo' => $this->logo_url,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
