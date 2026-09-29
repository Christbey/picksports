<?php

namespace App\Services\Api\V2;

use App\Http\Resources\Api\V2\CanonicalSportPredictionResource;
use App\Http\Resources\Api\V2\SportPredictionResource;
use Illuminate\Http\Request;

class GamePredictionPayload
{
    public function __construct(
        private readonly SportPredictionQuery $predictions,
        private readonly SportPredictionPresentationService $presentations,
        private readonly CanonicalSportPredictionQuery $canonical,
        private readonly CanonicalPredictionPresentationService $canonicalPresentations,
    ) {}

    public function forGame(SportContext $context, string $game, Request $request): array
    {
        if ($this->canonical->supports($context)) {
            $prediction = $this->canonical->findForGame($context, $game, $request->user());

            return [
                'data' => new CanonicalSportPredictionResource($prediction, $context, $this->canonicalPresentations->forPrediction($prediction)),
                'meta' => ['prediction_source' => 'canonical', 'game_id' => $prediction->sportEvent?->getRelation($context->slug.'Game')?->getKey()],
            ];
        }
        $prediction = $this->predictions->findForGame($context, $game, $request->user());

        return [
            'data' => new SportPredictionResource($prediction, $context, $this->presentations->forPrediction($context, $prediction)),
            'meta' => ['prediction_source' => 'legacy', 'game_id' => (int) $prediction->game_id],
        ];
    }
}
