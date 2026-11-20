<?php

namespace App\Services\Betting;

use App\Enums\PredictionSport;
use App\Models\CanonicalPrediction;
use App\Models\UserBet;
use App\Support\Betting\UserBetPredictionReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserBetPredictionReferenceResolver
{
    public function resolve(PredictionSport $sport, int|string $predictionId): UserBetPredictionReference
    {
        if (is_string($predictionId) && Str::isUlid($predictionId)) {
            $prediction = CanonicalPrediction::query()->where('sport', $sport->value)
                ->where('public_id', $predictionId)
                ->whereHas('sportEvent', fn ($query) => $query->where('sport', $sport->value))->firstOrFail();

            return new UserBetPredictionReference($sport, (int) $prediction->getKey(),
                (int) $prediction->sport_event_id, CanonicalPrediction::class);
        }

        if (filter_var($predictionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw ValidationException::withMessages(['prediction_id' => 'Use a positive internal ID or canonical public ULID.']);
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

        $class = UserBet::supportedPredictionModel($bet->prediction_type);
        if ($class === null || ($class !== CanonicalPrediction::class && PredictionSport::fromLegacyModelClass($class) !== $sport)) {
            return null;
        }
        $prediction = $bet->loadMissing('prediction')->getRelation('prediction');
        if ($prediction === null) {
            // Keep missing historical legacy IDs representable without guessing a different source.
            return $class !== CanonicalPrediction::class && $bet->sport_event_id === null
                ? new UserBetPredictionReference($sport, (int) $bet->prediction_id, null)
                : null;
        }
        $eventId = $prediction instanceof CanonicalPrediction
            ? $prediction->sport_event_id
            : $prediction->getRelation('game')?->sport_event_id;

        return new UserBetPredictionReference($sport, (int) $prediction->getKey(), $eventId !== null ? (int) $eventId : null, $class);
    }
}
