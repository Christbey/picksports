<?php

namespace App\Application\Sports\ReadModels;

final readonly class PredictionSummary
{
    /**
     * @param  list<MarketSummary>  $markets
     */
    public function __construct(
        public int|string $id,
        public ?float $predictedSpread,
        public ?float $predictedTotal,
        public ?float $confidenceScore,
        public float $homeWinProbability,
        public array $markets,
    ) {}

    public function market(string $type, ?string $selection = null): ?MarketSummary
    {
        foreach ($this->markets as $market) {
            if ($market->type === $type
                && ($selection === null || $market->selection === $selection)) {
                return $market;
            }
        }

        return null;
    }
}
