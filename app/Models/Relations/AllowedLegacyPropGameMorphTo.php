<?php

namespace App\Models\Relations;

use App\Models\PlayerProp;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Legacy generic props retain their provider sport keys without permitting arbitrary morph classes. */
final class AllowedLegacyPropGameMorphTo extends MorphTo
{
    public function getResults(): ?Model
    {
        if (PlayerProp::supportedGameModel($this->parent->sport, $this->parent->getAttribute($this->morphType)) === null) {
            return null;
        }

        return parent::getResults();
    }

    protected function buildDictionary(Collection $models): void
    {
        parent::buildDictionary($models->filter(fn (PlayerProp $prop): bool => PlayerProp::supportedGameModel($prop->sport, $prop->getAttribute($this->morphType)) !== null));
    }
}
