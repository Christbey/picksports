<?php

namespace App\Application\Sports\ReadModels;

use App\Models\CanonicalPrediction;
use App\Models\PredictionMarket;

final class CanonicalPredictionSummaryMapper
{
    public function fromModel(CanonicalPrediction $prediction): PredictionSummary
    {
        $prediction->loadMissing('markets');
        $markets = $prediction->markets
            ->map(fn (PredictionMarket $market): MarketSummary => new MarketSummary(
                type: $market->market_type,
                selection: $market->selection,
                projectedLine: $market->projected_line !== null ? (float) $market->projected_line : null,
                probability: $market->probability !== null ? (float) $market->probability : null,
                confidenceScore: $market->confidence_score !== null ? (float) $market->confidence_score : null,
            ))
            ->values()
            ->all();
        $homeMoneyline = $this->market($markets, 'moneyline', 'home');
        $homeSpread = $this->market($markets, 'spread', 'home');
        $total = $this->market($markets, 'total', 'combined');

        return new PredictionSummary(
            id: $prediction->public_id,
            predictedSpread: $homeSpread?->projectedLine,
            predictedTotal: $total?->projectedLine,
            confidenceScore: $homeMoneyline?->confidenceScore ?? $homeSpread?->confidenceScore,
            homeWinProbability: $homeMoneyline?->probability ?? 0.5,
            markets: $markets,
        );
    }

    /** @param list<MarketSummary> $markets */
    private function market(array $markets, string $type, string $selection): ?MarketSummary
    {
        foreach ($markets as $market) {
            if ($market->type === $type && $market->selection === $selection) {
                return $market;
            }
        }

        return null;
    }
}
