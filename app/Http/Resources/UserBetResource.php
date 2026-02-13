<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserBetResource extends JsonResource
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
            'user_id' => $this->user_id,
            'prediction_id' => $this->prediction_id,
            'prediction_type' => $this->prediction_type,
            'bet_amount' => $this->bet_amount,
            'odds' => $this->odds,
            'bet_type' => $this->bet_type,
            'result' => $this->result,
            'profit_loss' => $this->profit_loss,
            'notes' => $this->notes,
            'placed_at' => $this->placed_at?->toISOString(),
            'settled_at' => $this->settled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'prediction' => $this->whenLoaded('prediction'),
        ];
    }
}
