<?php

namespace App\Http\Resources;

use App\Enums\PredictionSport;
use App\Models\CanonicalPrediction;
use App\Models\UserBet;
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

        $predictionClass = UserBet::supportedPredictionModel($this->prediction_type);
        $prediction = $this->resource->relationLoaded('prediction') ? $this->resource->getRelation('prediction') : null;
        $isCanonical = $predictionClass === CanonicalPrediction::class;
        $hasValidType = $predictionSport !== null && $predictionClass !== null
            && ($isCanonical || PredictionSport::fromLegacyModelClass($predictionClass) === $predictionSport);
        $hasResolvedIdentity = $prediction !== null
            || (! $isCanonical && $this->sport_event_id === null);
        $hasReference = $hasValidType && $this->prediction_id !== null && $hasResolvedIdentity;
        $predictionId = $isCanonical ? $prediction?->public_id : $this->prediction_id;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'prediction_id' => $predictionId,
            'prediction_sport' => $predictionSport?->value,
            'prediction_reference' => $hasReference
                ? [
                    'sport' => $predictionSport->value,
                    'id' => $predictionId,
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
