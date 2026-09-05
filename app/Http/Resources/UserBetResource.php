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
        $predictionSport = $this->resource->normalizedPredictionSport();

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'prediction_id' => $this->prediction_id,
            'prediction_sport' => $predictionSport?->value,
            'prediction_reference' => $predictionSport !== null && $this->prediction_id !== null
                ? [
                    'sport' => $predictionSport->value,
                    'id' => (int) $this->prediction_id,
                    'event_id' => $this->whenLoaded('sportEvent', fn (): ?string => $this->sportEvent?->public_id),
                ]
                : null,
            'bet_amount' => $this->bet_amount,
            'odds' => $this->odds,
            'bet_type' => $this->bet_type,
            'selection_side' => $this->selection_side,
            'selection_label' => $this->selection_label,
            'line' => $this->line,
            'result' => $this->result,
            'profit_loss' => $this->profit_loss,
            'notes' => $this->notes,
            'placed_at' => $this->placed_at?->toISOString(),
            'settled_at' => $this->settled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
