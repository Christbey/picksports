<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\Prediction;
use App\Services\MLB\MlbPeriodModelContextService;
use App\Support\MLB\MlbGameStart;

class MlbFirstFiveCandidateBuilder
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
        return [
            ...$this->sideCandidates($prediction, 'first_5_moneyline', 'h2h_1st_5_innings', ['h2h_1st_5_innings', 'h2h_1st_5']),
            ...$this->sideCandidates($prediction, 'first_5_run_line', 'spreads_1st_5_innings', ['spreads_1st_5_innings', 'spreads_1st_5']),
            ...$this->totalCandidates($prediction),
        ];
    }

    /**
     * @param  list<string>  $marketKeys
     * @return list<MlbPickCandidateData>
     */
    private function sideCandidates(Prediction $prediction, string $marketType, string $marketKey, array $marketKeys): array
    {
        $game = $prediction->game;
        if (! $game) {
            return [];
        }

        $outcomes = $this->markets->sideOutcomes($game, $marketKeys);
        $noVig = $this->markets->noVigSides($outcomes);
        $pitcherEdge = ((float) ($prediction->home_pitcher_elo ?? 1500)) - ((float) ($prediction->away_pitcher_elo ?? 1500));
        $teamEdge = ((float) ($prediction->home_team_elo ?? 1500)) - ((float) ($prediction->away_team_elo ?? 1500));
        $rows = [];

        foreach (['home', 'away'] as $side) {
            $outcome = $outcomes[$side];
            if (! $outcome) {
                continue;
            }

            $signedPitcherEdge = $side === 'home' ? $pitcherEdge : -1 * $pitcherEdge;
            $signedTeamEdge = $side === 'home' ? $teamEdge : -1 * $teamEdge;
            $projectedValue = ($signedPitcherEdge * 0.65) + ($signedTeamEdge * 0.25);
            if ($marketType === 'first_5_run_line' && is_numeric($outcome['line'] ?? null)) {
                $projectedValue += ((float) $outcome['line']) * 50;
            }
            $heuristicProbability = max(0.05, min(0.95, 0.5 + ($projectedValue / 600)));
            $periodModel = $marketType === 'first_5_moneyline'
                ? $this->periodModels->qualifiedProbability(
                    (int) $game->id,
                    'first_5_moneyline',
                    $side,
                )
                : null;
            $modelProbability = $periodModel['probability'] ?? $heuristicProbability;
            $marketProbability = $this->markets->implied((int) $outcome['price']);
            $riskFlags = [];
            $reasonCodes = $periodModel
                ? ['promoted_period_model_probability', 'f5_market_agreement']
                : ['f5_pitcher_edge', 'f5_offense_split_edge', 'f5_market_agreement'];

            if (! $game->probable_home_pitcher_espn_id || ! $game->probable_away_pitcher_espn_id) {
                $riskFlags[] = 'starter_unconfirmed';
            } else {
                $reasonCodes[] = 'starter_confirmed';
            }

            $rows[] = new MlbPickCandidateData(
                gameId: (int) $game->id,
                predictionId: (int) $prediction->id,
                season: (int) $prediction->season,
                marketType: $marketType,
                marketKey: $marketKey,
                side: $side,
                line: is_numeric($outcome['line'] ?? null) ? (float) $outcome['line'] : null,
                price: (int) $outcome['price'],
                book: $outcome['book'] ?? null,
                modelProbability: $modelProbability,
                marketProbability: $marketProbability,
                noVigProbability: $noVig[$side],
                blendProbability: $noVig[$side] !== null ? ($modelProbability * 0.25) + ($noVig[$side] * 0.75) : null,
                edgeRaw: $marketProbability !== null ? $modelProbability - $marketProbability : null,
                edgeNoVig: $noVig[$side] !== null ? $modelProbability - $noVig[$side] : null,
                projectedValue: $projectedValue,
                reasonCodes: $reasonCodes,
                riskFlags: $riskFlags,
                featureSnapshot: [
                    'pitcher_edge' => $pitcherEdge,
                    'team_edge' => $teamEdge,
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

        return $rows;
    }

    /**
     * @return list<MlbPickCandidateData>
     */
    private function totalCandidates(Prediction $prediction): array
    {
        $game = $prediction->game;
        if (! $game || ! is_numeric($prediction->predicted_total)) {
            return [];
        }

        $outcomes = $this->markets->totalOutcomes($game, ['totals_1st_5_innings', 'totals_1st_5']);
        $noVig = $this->markets->noVigTotals($outcomes);
        $f5Total = ((float) $prediction->predicted_total - (float) config('mlb.picks.total_bias_correction', 1.0)) * 0.54;
        $rows = [];

        foreach (['over', 'under'] as $side) {
            $outcome = $outcomes[$side];
            if (! $outcome || ! is_numeric($outcome['line'] ?? null)) {
                continue;
            }

            $line = (float) $outcome['line'];
            $projectedValue = $side === 'over' ? $f5Total - $line : $line - $f5Total;
            $modelProbability = max(0.05, min(0.95, 0.5 + ($projectedValue / 4)));
            $marketProbability = $this->markets->implied((int) $outcome['price']);

            $rows[] = new MlbPickCandidateData(
                gameId: (int) $game->id,
                predictionId: (int) $prediction->id,
                season: (int) $prediction->season,
                marketType: 'first_5_total',
                marketKey: 'totals_1st_5_innings',
                side: $side,
                line: $line,
                price: (int) $outcome['price'],
                book: $outcome['book'] ?? null,
                modelProbability: $modelProbability,
                marketProbability: $marketProbability,
                noVigProbability: $noVig[$side],
                blendProbability: $noVig[$side] !== null ? ($modelProbability * 0.25) + ($noVig[$side] * 0.75) : null,
                edgeRaw: $marketProbability !== null ? $modelProbability - $marketProbability : null,
                edgeNoVig: $noVig[$side] !== null ? $modelProbability - $noVig[$side] : null,
                projectedValue: $projectedValue,
                reasonCodes: ['f5_pitcher_edge', $side === 'over' ? 'model_total_over_market' : 'model_total_under_market'],
                riskFlags: ['total_model_over_bias'],
                featureSnapshot: ['corrected_f5_total' => $f5Total],
                marketSnapshot: $outcome,
                gameStartAt: MlbGameStart::for($game),
            );
        }

        return $rows;
    }
}
