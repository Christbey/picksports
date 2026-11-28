<?php

namespace App\Http\Resources\NBA;

use App\Services\Api\V2\PreparedInjuryImpacts;
use App\Support\Injuries\StoredInjuryClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerInjuryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $impact = app(PreparedInjuryImpacts::class)->get($this->resource);

        return [
            'id' => $this->id,
            'player_id' => $this->player_id,
            'player_name' => $this->player?->full_name,
            'player_headshot' => $this->player?->headshot_url ?? $this->player?->headshot,
            'status' => $this->status,
            'detail' => $this->detail,
            'type' => $this->type,
            'impact_score' => $impact['score'],
            'impact_label' => $impact['label'],
            'impact_spread' => $impact['spread_impact'],
            'impact_total' => $impact['total_impact'],
            'impact_multiplier' => $impact['multiplier'],
            'injury_date' => $this->injury_date?->toDateString(),
            'return_date' => $this->return_date?->toDateString(),
            ...StoredInjuryClock::fields($this->resource),
            'is_active' => (bool) $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
