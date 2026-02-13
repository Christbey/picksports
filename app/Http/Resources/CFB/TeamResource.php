<?php

namespace App\Http\Resources\CFB;

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
            'display_name' => $this->display_name,
            'short_display_name' => $this->short_display_name,
            'conference' => $this->conference,
            'division' => $this->division,
            'color' => $this->color,
            'alternate_color' => $this->alternate_color,
            'logo' => $this->logo,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
