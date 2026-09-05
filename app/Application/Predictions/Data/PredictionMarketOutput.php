<?php

namespace App\Application\Predictions\Data;

use App\Models\PredictionMarket;
use InvalidArgumentException;

final readonly class PredictionMarketOutput
{
    public function __construct(
        public string $marketType,
        public string $selection,
        public ?float $projectedLine = null,
        public ?float $probability = null,
        public ?float $confidenceScore = null,
    ) {
        if (! in_array($this->marketType, PredictionMarket::MARKET_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported prediction output market type.');
        }

        if (! in_array($this->selection, PredictionMarket::SELECTIONS, true)) {
            throw new InvalidArgumentException('Unsupported prediction output selection.');
        }

        if ($this->probability !== null && ($this->probability < 0 || $this->probability > 1)) {
            throw new InvalidArgumentException('Prediction probability must be between zero and one.');
        }

        if ($this->confidenceScore !== null && ($this->confidenceScore < 0 || $this->confidenceScore > 100)) {
            throw new InvalidArgumentException('Prediction confidence must be between zero and one hundred.');
        }
    }

    /** @return array<string, float|string|null> */
    public function toArray(): array
    {
        return [
            'market_type' => $this->marketType,
            'selection' => $this->selection,
            'projected_line' => $this->normalizedNumber($this->projectedLine, 4),
            'probability' => $this->normalizedNumber($this->probability, 6),
            'confidence_score' => $this->normalizedNumber($this->confidenceScore, 4),
        ];
    }

    private function normalizedNumber(?float $value, int $precision): ?float
    {
        if ($value === null) {
            return null;
        }

        $rounded = round($value, $precision);

        return $rounded == 0.0 ? 0.0 : $rounded;
    }
}
