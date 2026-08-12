<?php

namespace App\Http\Resources\WNBA;

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
        $fullName = $this->full_name
            ?? trim(implode(' ', array_filter([$this->first_name, $this->last_name])))
            ?: null;

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
            'jersey' => $this->jersey_number,
            'jersey_number' => $this->jersey_number,
            'position' => $this->position,
            'height' => $this->height,
            'weight' => $this->weight,
            'experience' => $this->experience,
            'college' => $this->college,
            'headshot' => $this->headshot_url,
            'headshot_url' => $this->headshot_url,
            'active_injuries_count' => $this->when(
                $this->relationLoaded('activeInjuries'),
                fn () => $this->activeInjuries->count()
            ),
            'active_injuries' => $this->when(
                $this->relationLoaded('activeInjuries'),
                fn () => $this->activeInjuries->map(fn ($injury) => [
                    'id' => $injury->id,
                    'player_id' => $injury->player_id,
                    'player_name' => $injury->player?->full_name ?? $injury->player?->display_name ?? $injury->player?->name,
                    'status' => $injury->status,
                    'detail' => $injury->detail,
                    'type' => $injury->type,
                    'injury_date' => $injury->injury_date?->toDateString(),
                    'return_date' => $injury->return_date?->toDateString(),
                    'source_updated_at' => $injury->source_updated_at?->toIso8601String(),
                    'is_active' => (bool) $injury->is_active,
                    'updated_at' => $injury->updated_at?->toIso8601String(),
                ])->values()
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
