<?php

namespace App\Models\Relations;

use App\Enums\PredictionSport;
use App\Models\CanonicalPrediction;
use App\Models\UserBet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A user bet may reference only a known forecast model, never an arbitrary stored class. */
final class AllowedPredictionMorphTo extends MorphTo
{
    public function getResults(): ?Model
    {
        if (! $this->acceptsType($this->parent, $this->parent->getAttribute($this->morphType))) {
            return null;
        }
        $result = parent::getResults();
        if ($result === null) {
            return null;
        }
        $this->loadIdentityContext(new Collection([$result]));

        return $this->matchesIdentity($this->parent, $result) ? $result : null;
    }

    protected function buildDictionary(Collection $models): void
    {
        parent::buildDictionary($models->filter(fn (UserBet $bet): bool => $this->acceptsType($bet, $bet->getAttribute($this->morphType))));
    }

    protected function getResultsByType($type): Collection
    {
        if (UserBet::supportedPredictionModel($type) === null) {
            return new Collection;
        }
        $results = parent::getResultsByType($type);
        $this->loadIdentityContext($results);

        return $results;
    }

    protected function matchToMorphParents($type, Collection $results): void
    {
        foreach ($results as $result) {
            $owner = $this->getDictionaryKey($result->{$this->ownerKey ?? $result->getKeyName()});
            foreach ($this->dictionary[$type][$owner] ?? [] as $bet) {
                if ($this->matchesIdentity($bet, $result)) {
                    $bet->setRelation($this->relationName, $result);
                }
            }
        }
    }

    private function acceptsType(UserBet $bet, ?string $type): bool
    {
        $class = UserBet::supportedPredictionModel($type);
        $sport = $bet->normalizedPredictionSport();

        return $class !== null && $sport !== null
            && ($class === CanonicalPrediction::class || PredictionSport::fromLegacyModelClass($class) === $sport);
    }

    private function loadIdentityContext(Collection $results): void
    {
        if ($results->isEmpty()) {
            return;
        }
        $results->loadMissing($results->first() instanceof CanonicalPrediction
            ? 'sportEvent:id,sport'
            : ['game:id,sport_event_id', 'game.sportEvent:id,sport']);
    }

    private function matchesIdentity(UserBet $bet, Model $prediction): bool
    {
        $sport = $bet->normalizedPredictionSport();
        $event = $prediction instanceof CanonicalPrediction
            ? $prediction->getRelation('sportEvent')
            : $prediction->getRelation('game')?->getRelation('sportEvent');
        if ($prediction instanceof CanonicalPrediction && ($prediction->sport !== $sport?->value || $event === null)) {
            return false;
        }
        if ($event !== null && $event->sport !== $sport?->value) {
            return false;
        }

        return $bet->sport_event_id === null || ($event !== null && (int) $bet->sport_event_id === (int) $event->getKey());
    }
}
