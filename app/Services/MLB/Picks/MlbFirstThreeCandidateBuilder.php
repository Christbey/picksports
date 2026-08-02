<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\Prediction;
use App\Services\MLB\MlbPeriodModelContextService;
use App\Support\MLB\MlbGameStart;

class MlbFirstThreeCandidateBuilder
{
    public function __construct(
        private readonly MlbPickMarketService $markets,
        private readonly MlbPeriodModelContextService $periodModels,
    ) {}

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
            $heuristicProbability = max(0.05, min(0.95, 0.5 + ($projectedValue / 700)));
            $periodModel = $this->periodModels->qualifiedProbability(
                (int) $game->id,
                'first_3_moneyline',
                $side,
            );
            $modelProbability = $periodModel['probability'] ?? $heuristicProbability;
            $marketProbability = $this->markets->implied((int) $outcome['price']);
            $reasonCodes = [$periodModel ? 'promoted_period_model_probability' : 'f3_pitcher_edge'];
            $riskFlags = ['f3_high_variance', 'low_sample_first_3'];

            if (! $game->probable_home_pitcher_espn_id || ! $game->probable_away_pitcher_espn_id) {
                $riskFlags[] = 'starter_unconfirmed';
            } else {
                $reasonCodes[] = 'starter_confirmed';
            }

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
                reasonCodes: $reasonCodes,
                riskFlags: $riskFlags,
                featureSnapshot: [
                    'pitcher_edge' => $pitcherEdge,
                    'heuristic_probability' => $heuristicProbability,
                    'model_source' => $periodModel ? 'promoted_period_model' : 'elo_heuristic',
                    'period_model_artifact_id' => data_get($periodModel, 'context.lineage.artifact_id'),
                    'period_model_run_id' => data_get($periodModel, 'context.lineage.model_run_id'),
                    'period_model_feature_hash' => data_get($periodModel, 'context.lineage.feature_hash'),
                ],
                marketSnapshot: $outcome,
                teamId: (int) ($side === 'home' ? $game->home_team_id : $game->away_team_id),
                gameStartAt: MlbGameStart::for($game),
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
                gameStartAt: MlbGameStart::for($game),
            );
        }

        return $rows;
    }
}
