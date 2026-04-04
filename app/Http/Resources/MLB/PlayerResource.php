<?php

namespace App\Http\Resources\MLB;

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
            'name' => $this->full_name,
            'jersey_number' => $this->jersey_number,
            'position' => $this->position,
            'batting_hand' => $this->batting_hand,
            'throwing_hand' => $this->throwing_hand,
            'height' => $this->height,
            'weight' => $this->weight,
            'hometown' => $this->hometown,
            'headshot_url' => $this->headshot_url,
            'elo_rating' => $this->elo_rating !== null ? (float) $this->elo_rating : null,
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
