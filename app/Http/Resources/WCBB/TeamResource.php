<?php

namespace App\Http\Resources\WCBB;

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
            'school' => $this->school,
            'mascot' => $this->mascot,
            'name' => $this->mascot,
            'display_name' => $this->school,
            'conference' => $this->conference,
            'division' => $this->division,
            'color' => $this->color,
            'logo' => $this->logo_url,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
