<?php

namespace App\Services\MLB\Picks;

use App\Application\Sports\ReadModels\BettingForecast;
use App\Models\MLB\GameWeather;
use App\Services\Predictions\BettingForecastSource;
use App\Support\MLB\MlbGameStart;
use Illuminate\Database\Eloquent\Model;

class MlbFirstInningCandidateBuilder
{
    public function __construct(private readonly MlbPickMarketService $markets) {}

    /**
     * @return list<MlbPickCandidateData>
     */
    public function build(Model|BettingForecast $prediction): array
    {
        $prediction = $prediction instanceof Model ? app(BettingForecastSource::class)->fromModel($prediction, 'mlb') : $prediction;

        if ($prediction->isCanonical() && ($prediction->homePitcherElo === null || $prediction->awayPitcherElo === null)) {
            return [];
        }

        $game = $prediction->game;
        if (! $game || ! is_numeric($prediction->predictedTotal)) {
            return [];
        }

        $outcomes = $this->markets->totalOutcomes($game, ['totals_1st_1_innings']);
        $noVig = $this->markets->noVigTotals($outcomes);
        $yrfiProbability = $this->yrfiProbability($prediction);
        $weather = GameWeather::query()->where('game_id', $game->id)->latest('observed_at')->first();
        $rows = [];

        foreach (['over', 'under'] as $side) {
            $outcome = $outcomes[$side];
            if (! $outcome || ! is_numeric($outcome['line'] ?? null)) {
                continue;
            }

            $modelProbability = $side === 'over' ? $yrfiProbability : 1 - $yrfiProbability;
            $marketProbability = $this->markets->implied((int) $outcome['price']);
            $reasonCodes = [
                'first_inning_run_model',
                $side === 'over' ? 'yrfi_model_signal' : 'nrfi_model_signal',
                'starter_top_order_context',
            ];
            $riskFlags = ['first_inning_model_unvalidated', 'first_inning_high_variance'];

            if ($weather !== null) {
                $reasonCodes[] = 'weather_context_available';
            } else {
                $riskFlags[] = 'weather_missing';
            }
            if (! $game->probable_home_pitcher_espn_id || ! $game->probable_away_pitcher_espn_id) {
                $riskFlags[] = 'starter_unconfirmed';
            } else {
                $reasonCodes[] = 'starter_confirmed';
            }
            if ($this->markets->stale($game)) {
                $riskFlags[] = 'stale_price';
            }

            $rows[] = new MlbPickCandidateData(
                gameId: (int) $game->id,
                predictionId: $prediction->legacyPredictionId(),
                canonicalPredictionId: $prediction->canonicalPredictionId(),
                season: $prediction->season(),
                marketType: 'first_inning_total',
                marketKey: 'totals_1st_1_innings',
                side: $side,
                line: (float) $outcome['line'],
                price: (int) $outcome['price'],
                book: $outcome['book'] ?? null,
                modelProbability: $modelProbability,
                marketProbability: $marketProbability,
                noVigProbability: $noVig[$side],
                blendProbability: $noVig[$side] !== null
                    ? ($modelProbability * 0.25) + ($noVig[$side] * 0.75)
                    : null,
                edgeRaw: $marketProbability !== null ? $modelProbability - $marketProbability : null,
                edgeNoVig: $noVig[$side] !== null ? $modelProbability - $noVig[$side] : null,
                projectedValue: $modelProbability,
                reasonCodes: $reasonCodes,
                riskFlags: $riskFlags,
                featureSnapshot: [
                    'yrfi_probability' => round($yrfiProbability, 4),
                    'nrfi_probability' => round(1 - $yrfiProbability, 4),
                    'full_game_projected_total' => (float) $prediction->predictedTotal,
                    'home_pitcher_elo' => (float) ($prediction->homePitcherElo ?? 1500),
                    'away_pitcher_elo' => (float) ($prediction->awayPitcherElo ?? 1500),
                ],
                marketSnapshot: $outcome,
                gameStartAt: MlbGameStart::for($game),
            );
        }

        return $rows;
    }

    private function yrfiProbability(BettingForecast $prediction): float
    {
        $baseline = (float) config('mlb.picks.first_inning.base_yrfi_probability', 0.50);
        $referenceTotal = (float) config('mlb.picks.first_inning.reference_total', 8.5);
        $totalWeight = (float) config('mlb.picks.first_inning.total_probability_per_run', 0.025);
        $pitcherWeight = (float) config('mlb.picks.first_inning.pitcher_probability_per_100_elo', 0.025);
        $correctedTotal = (float) $prediction->predictedTotal
            - (float) config('mlb.picks.total_bias_correction', 1.0);
        $averagePitcherElo = (
            (float) ($prediction->homePitcherElo ?? 1500)
            + (float) ($prediction->awayPitcherElo ?? 1500)
        ) / 2;

        $probability = $baseline
            + (($correctedTotal - $referenceTotal) * $totalWeight)
            - ((($averagePitcherElo - 1500) / 100) * $pitcherWeight);

        return max(0.35, min(0.65, $probability));
    }
}
