<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CbbBracketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'user_id' => $this->user_id,
            'group_id' => $this->group_id,
            'group' => $this->whenLoaded('group', fn () => [
                'id' => $this->group?->id,
                'public_id' => $this->group?->public_id,
                'name' => $this->group?->name,
            ]),
            'season' => $this->season,
            'name' => $this->name,
            'picks' => $this->picks ?? [],
            'points_earned' => (int) ($this->points_earned ?? 0),
            'max_points_remaining' => (int) ($this->max_points_remaining ?? 0),
            'correct_picks' => (int) ($this->correct_picks ?? 0),
            'incorrect_picks' => (int) ($this->incorrect_picks ?? 0),
            'graded_through_round' => $this->graded_through_round,
            'results' => $this->results ?? [],
            'is_locked' => $this->isLocked(),
            'can_edit' => ! $this->isLocked(),
            'lock_at' => $this->lockAt()?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
