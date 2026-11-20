<?php

namespace App\Application\Sports\ReadModels;

use App\Models\CanonicalPrediction;
use Illuminate\Database\Eloquent\Model;

final readonly class PredictionDeliveryContext
{
    public function __construct(
        public Model $source,
        public string $sport,
        public Model $game,
        public ?float $predictedSpread,
        public ?float $predictedTotal,
        public ?float $homeWinProbability,
        public float|int|string|null $confidenceScore,
    ) {}

    public function isCanonical(): bool
    {
        return $this->source instanceof CanonicalPrediction;
    }
}
