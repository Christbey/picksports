<?php

namespace App\Services\Predictions;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

#[Singleton]
final class PredictionResourcePresentationStore
{
    /** @var WeakMap<Model, PredictionResourcePresentationData> */
    private WeakMap $presentations;

    public function __construct()
    {
        $this->presentations = new WeakMap;
    }

    public function put(Model $prediction, PredictionResourcePresentationData $presentation): void
    {
        $this->presentations[$prediction] = $presentation;
    }

    public function get(Model $prediction): ?PredictionResourcePresentationData
    {
        return $this->presentations[$prediction] ?? null;
    }
}
