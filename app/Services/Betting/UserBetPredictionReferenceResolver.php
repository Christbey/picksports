<?php

namespace App\Services\Betting;

use App\Enums\PredictionSport;
use App\Models\CanonicalPrediction;
use App\Models\UserBet;
use App\Support\Betting\UserBetPredictionReference;
use Illuminate\Database\Eloquent\Model;

class UserBetPredictionReferenceResolver
{
    public function resolve(PredictionSport $sport, int|string $predictionId): UserBetPredictionReference
    {
        if (! is_numeric($predictionId)) {
            $prediction = CanonicalPrediction::query()->where('sport', $sport->value)
                ->where('public_id', $predictionId)->firstOrFail();

            return new UserBetPredictionReference($sport, (int) $prediction->getKey(),
                (int) $prediction->sport_event_id, CanonicalPrediction::class);
        }

        $predictionId = (int) $predictionId;
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

        if ($bet->prediction_type === CanonicalPrediction::class) {
            return new UserBetPredictionReference($sport, (int) $bet->prediction_id,
                $bet->sport_event_id, CanonicalPrediction::class);
        }

        $resolved = $this->resolve($sport, (int) $bet->prediction_id);

        return new UserBetPredictionReference(
            sport: $resolved->sport,
            predictionId: $resolved->predictionId,
            sportEventId: $bet->sport_event_id ?? $resolved->sportEventId,
        );
    }
}
