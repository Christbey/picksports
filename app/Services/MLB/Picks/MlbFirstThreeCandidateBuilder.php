<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\Prediction;

class MlbFirstThreeCandidateBuilder
{
    public function __construct(private readonly MlbPickMarketService $markets) {}

    /**
     * @return list<MlbPickCandidateData>
     */
    public function build(Prediction $prediction): array
    {
        $game = $prediction->game;
        if (! $game) {
            return [];
        }

        $rows = [];
        $sideOutcomes = $this->markets->sideOutcomes($game, ['h2h_1st_3_innings', 'h2h_1st_3']);
        $sideNoVig = $this->markets->noVigSides($sideOutcomes);
        $pitcherEdge = ((float) ($prediction->home_pitcher_elo ?? 1500)) - ((float) ($prediction->away_pitcher_elo ?? 1500));

        foreach (['home', 'away'] as $side) {
            $outcome = $sideOutcomes[$side];
            if (! $outcome) {
                continue;
            }

            $projectedValue = $side === 'home' ? $pitcherEdge : -1 * $pitcherEdge;
            $modelProbability = max(0.05, min(0.95, 0.5 + ($projectedValue / 700)));
            $marketProbability = $this->markets->implied((int) $outcome['price']);

            $rows[] = new MlbPickCandidateData(
                gameId: (int) $game->id,
                predictionId: (int) $prediction->id,
                season: (int) $prediction->season,
                marketType: 'first_3_moneyline',
                marketKey: 'h2h_1st_3_innings',
                side: $side,
                line: null,
                price: (int) $outcome['price'],
                book: $outcome['book'] ?? null,
                modelProbability: $modelProbability,
                marketProbability: $marketProbability,
                noVigProbability: $sideNoVig[$side],
                blendProbability: $sideNoVig[$side] !== null ? ($modelProbability * 0.25) + ($sideNoVig[$side] * 0.75) : null,
                edgeRaw: $marketProbability !== null ? $modelProbability - $marketProbability : null,
                edgeNoVig: $sideNoVig[$side] !== null ? $modelProbability - $sideNoVig[$side] : null,
                projectedValue: $projectedValue,
                reasonCodes: ['f5_pitcher_edge', 'starter_confirmed'],
                riskFlags: ['f3_high_variance', 'low_sample_first_3'],
                featureSnapshot: ['pitcher_edge' => $pitcherEdge],
                marketSnapshot: $outcome,
                teamId: (int) ($side === 'home' ? $game->home_team_id : $game->away_team_id),
                gameStartAt: $game->game_date,
            );
        }

        $totalOutcomes = $this->markets->totalOutcomes($game, ['totals_1st_3_innings', 'totals_1st_3']);
        $totalNoVig = $this->markets->noVigTotals($totalOutcomes);
        $f3Total = is_numeric($prediction->predicted_total) ? ((float) $prediction->predicted_total - (float) config('mlb.picks.total_bias_correction', 1.0)) * 0.32 : null;

        foreach (['over', 'under'] as $side) {
            $outcome = $totalOutcomes[$side];
            if (! $outcome || $f3Total === null || ! is_numeric($outcome['line'] ?? null)) {
                continue;
            }

            $line = (float) $outcome['line'];
            $projectedValue = $side === 'over' ? $f3Total - $line : $line - $f3Total;
            $modelProbability = max(0.05, min(0.95, 0.5 + ($projectedValue / 3)));
            $marketProbability = $this->markets->implied((int) $outcome['price']);

            $rows[] = new MlbPickCandidateData(
                gameId: (int) $game->id,
                predictionId: (int) $prediction->id,
                season: (int) $prediction->season,
                marketType: 'first_3_total',
                marketKey: 'totals_1st_3_innings',
                side: $side,
                line: $line,
                price: (int) $outcome['price'],
                book: $outcome['book'] ?? null,
                modelProbability: $modelProbability,
                marketProbability: $marketProbability,
                noVigProbability: $totalNoVig[$side],
                blendProbability: $totalNoVig[$side] !== null ? ($modelProbability * 0.25) + ($totalNoVig[$side] * 0.75) : null,
                edgeRaw: $marketProbability !== null ? $modelProbability - $marketProbability : null,
                edgeNoVig: $totalNoVig[$side] !== null ? $modelProbability - $totalNoVig[$side] : null,
                projectedValue: $projectedValue,
                reasonCodes: [$side === 'over' ? 'model_total_over_market' : 'model_total_under_market'],
                riskFlags: ['f3_high_variance', 'low_sample_first_3'],
                featureSnapshot: ['corrected_f3_total' => $f3Total],
                marketSnapshot: $outcome,
                gameStartAt: $game->game_date,
            );
        }

        return $rows;
    }
}
