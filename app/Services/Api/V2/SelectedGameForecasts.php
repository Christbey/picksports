<?php

namespace App\Services\Api\V2;

use App\Models\CanonicalPrediction;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

/** Request-local results of the gated canonical forecast query, including missing forecasts. */
#[Scoped]
final class SelectedGameForecasts
{
    /** @var WeakMap<Model, array{prediction: CanonicalPrediction|null}> */
    private WeakMap $forecasts;

    public function __construct()
    {
        $this->forecasts = new WeakMap;
    }

    public function put(Model $game, ?CanonicalPrediction $prediction): void
    {
        $this->forecasts[$game] = ['prediction' => $prediction];
    }

    public function contains(Model $game): bool
    {
        return $this->forecasts->offsetExists($game);
    }

    public function get(Model $game): ?CanonicalPrediction
    {
        return $this->forecasts[$game]['prediction'] ?? null;
    }
}
