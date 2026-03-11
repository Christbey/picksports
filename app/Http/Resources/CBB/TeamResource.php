<?php

namespace App\Http\Resources\CBB;

use App\Support\InjuryImpactScorer;
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
        $impactScorer = app(InjuryImpactScorer::class);

        return [
            'id' => $this->id,
            'espn_id' => $this->espn_id,
            'abbreviation' => $this->abbreviation,
            'location' => $this->school,
            'school' => $this->school,
            'mascot' => $this->mascot,
            'name' => $this->mascot,
            'display_name' => $this->school,
            'short_display_name' => $this->abbreviation,
            'conference' => $this->conference,
            'division' => $this->division,
            'color' => $this->color,
            'logo' => $this->logo_url,
            'logo_url' => $this->logo_url,
            'active_injuries_count' => $this->when(
                $this->relationLoaded('activePlayerInjuries'),
                fn () => $this->activePlayerInjuries->count()
            ),
            'active_injuries' => $this->when(
                $this->relationLoaded('activePlayerInjuries'),
                fn () => $this->activePlayerInjuries->map(function ($injury) use ($impactScorer) {
                    $impact = $impactScorer->describe('cbb', (int) $injury->player_id, $injury->status);

                    return [
                        'id' => $injury->id,
                        'player_id' => $injury->player_id,
                        'player_name' => $injury->player?->full_name,
                        'player_headshot' => $injury->player?->headshot_url ?? $injury->player?->headshot,
                        'status' => $injury->status,
                        'detail' => $injury->detail,
                        'type' => $injury->type,
                        'impact_score' => $impact['score'],
                        'impact_label' => $impact['label'],
                        'impact_spread' => $impact['spread_impact'],
                        'impact_total' => $impact['total_impact'],
                        'impact_multiplier' => $impact['multiplier'],
                        'injury_date' => $injury->injury_date?->toDateString(),
                        'return_date' => $injury->return_date?->toDateString(),
                        'source_updated_at' => $injury->source_updated_at?->toIso8601String(),
                        'is_active' => (bool) $injury->is_active,
                        'updated_at' => $injury->updated_at?->toIso8601String(),
                    ];
                })->values()
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
