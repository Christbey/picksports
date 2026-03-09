<?php

namespace App\Services\Sports;

class FuturesEdgeService
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function annotate(array $rows, string $modelProbabilityField = 'champion_probability'): array
    {
        return array_map(function (array $row) use ($modelProbabilityField): array {
            $modelProbability = isset($row[$modelProbabilityField]) && is_numeric($row[$modelProbabilityField])
                ? (float) $row[$modelProbabilityField]
                : null;

            $marketProbability = isset($row['market_odds']['implied_probability']) && is_numeric($row['market_odds']['implied_probability'])
                ? (float) $row['market_odds']['implied_probability']
                : null;

            $edgeProbability = null;
            if ($modelProbability !== null && $marketProbability !== null) {
                $edgeProbability = $modelProbability - $marketProbability;
            }

            $row['market_edge'] = [
                'model_probability' => $modelProbability,
                'market_probability' => $marketProbability,
                'edge_probability' => $edgeProbability,
                'edge_percent_points' => $edgeProbability !== null ? $edgeProbability * 100.0 : null,
                'fair_price' => $this->probabilityToAmericanOdds($modelProbability),
                'market_price' => $row['market_odds']['price'] ?? null,
                'has_edge' => $edgeProbability !== null,
            ];

            return $row;
        }, $rows);
    }

    protected function probabilityToAmericanOdds(?float $probability): ?int
    {
        if ($probability === null || $probability <= 0.0 || $probability >= 1.0) {
            return null;
        }

        if ($probability >= 0.5) {
            return (int) round((-100.0 * $probability) / (1.0 - $probability));
        }

        return (int) round((100.0 * (1.0 - $probability)) / $probability);
    }
}
