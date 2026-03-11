<?php

namespace App\Http\Resources\NFL;

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
            'first_name' => null,
            'last_name' => null,
            'full_name' => $this->display_name ?? $this->name,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'short_name' => $this->short_name,
            'jersey' => $this->jersey,
            'jersey_number' => $this->jersey,
            'position' => $this->position,
            'height' => $this->height,
            'weight' => $this->weight,
            'experience' => $this->experience,
            'college' => $this->college,
            'headshot' => $this->headshot,
            'headshot_url' => $this->headshot,
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
