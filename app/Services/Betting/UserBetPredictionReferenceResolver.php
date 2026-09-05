<?php

namespace App\Services\Betting;

use App\Enums\PredictionSport;
use App\Models\UserBet;
use App\Support\Betting\UserBetPredictionReference;
use Illuminate\Database\Eloquent\Model;

class UserBetPredictionReferenceResolver
{
    public function resolve(PredictionSport $sport, int $predictionId): UserBetPredictionReference
    {
        $modelClass = $sport->predictionModelClass();

        /** @var Model|null $prediction */
        $prediction = $modelClass::query()->with([
            'game:id,sport_event_id',
            'game.sportEvent:id,sport',
        ])->find($predictionId);
        $game = $prediction?->getRelation('game');
        $sportEvent = $game?->getRelation('sportEvent');

        return new UserBetPredictionReference(
            sport: $sport,
            predictionId: $predictionId,
            sportEventId: $sportEvent?->getAttribute('sport') === $sport->value
                ? (int) $sportEvent->getKey()
                : null,
        );
    }

    public function fromStoredBet(UserBet $bet): ?UserBetPredictionReference
    {
        if ($bet->prediction_id === null) {
            return null;
        }

        $sport = $bet->normalizedPredictionSport();

        if ($sport === null) {
            return null;
        }

        $resolved = $this->resolve($sport, (int) $bet->prediction_id);

        return new UserBetPredictionReference(
            sport: $resolved->sport,
            predictionId: $resolved->predictionId,
            sportEventId: $bet->sport_event_id ?? $resolved->sportEventId,
        );
    }
}
