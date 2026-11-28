<?php

namespace App\Services\MLB\Picks;

use App\Application\Sports\ReadModels\BettingForecast;
use App\Models\MLB\GameWeather;
use App\Services\Predictions\BettingForecastSource;
use App\Support\MLB\MlbGameStart;
use Illuminate\Database\Eloquent\Model;

class MlbTotalCandidateBuilder
{
    public function __construct(private readonly MlbPickMarketService $markets, private readonly BettingForecastSource $forecasts) {}

    /**
     * @return list<MlbPickCandidateData>
     */
    public function build(Model|BettingForecast $prediction): array
    {
        $prediction = $prediction instanceof Model ? $this->forecasts->fromModel($prediction, 'mlb') : $prediction;

        $game = $prediction->game;
        if (! $game || ! is_numeric($prediction->predictedTotal)) {
            return [];
        }

        $outcomes = $this->markets->totalOutcomes($game, ['totals']);
        $noVig = $this->markets->noVigTotals($outcomes);
        $correctedTotal = (float) $prediction->predictedTotal - (float) config('mlb.picks.total_bias_correction', 1.0);
        $weather = GameWeather::query()->where('game_id', $game->id)->latest('observed_at')->first();
        $rows = [];
        $samePredictionGame = $prediction->isCanonical()
            ? ($game->sport_event_id !== null && (int) $prediction->source->getAttribute('sport_event_id') === (int) $game->sport_event_id)
            : ((int) $prediction->source->getAttribute('game_id') > 0 && (int) $prediction->source->getAttribute('game_id') === (int) $game->id);

        foreach (['over', 'under'] as $side) {
            $outcome = $outcomes[$side];
            if (! $outcome || ! is_numeric($outcome['line'] ?? null)) {
                continue;
            }

            $line = (float) $outcome['line'];
            $projectedValue = $side === 'over' ? $correctedTotal - $line : $line - $correctedTotal;
            $modelProbability = max(0.05, min(0.95, 0.5 + ($projectedValue / 6)));
            $marketProbability = $this->markets->implied((int) $outcome['price']);
            $reasonCodes = [$side === 'over' ? 'model_total_over_market' : 'model_total_under_market'];
            $riskFlags = ['total_model_over_bias'];

            if ($weather) {
                $reasonCodes[] = 'weather_context_available';
            } else {
                $riskFlags[] = 'weather_missing';
            }
            if ($samePredictionGame && data_get($prediction->modelMetadata, 'actual_weather.applied') === true
                && $this->supportsSide(data_get($prediction->modelMetadata, 'actual_weather.total_adjustment'), $side)) {
                $reasonCodes[] = $side === 'over' ? 'weather_supports_over' : 'weather_supports_under';
            }
            if ($samePredictionGame && $this->supportsSide(data_get($prediction->modelMetadata, 'park_context.total_adjustment'), $side)) {
                $reasonCodes[] = 'park_support';
            }
            if ($this->markets->stale($game)) {
                $riskFlags[] = 'stale_price';
            }
            if (abs($projectedValue) < 0.75) {
                $riskFlags[] = 'low_total_edge';
            }

            $rows[] = new MlbPickCandidateData(
                gameId: (int) $game->id,
                predictionId: $prediction->legacyPredictionId(),
                canonicalPredictionId: $prediction->canonicalPredictionId(),
                season: $prediction->season(),
                marketType: 'total',
                marketKey: 'totals',
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
                reasonCodes: $reasonCodes,
                riskFlags: $riskFlags,
                featureSnapshot: [
                    'predicted_total' => (float) $prediction->predictedTotal,
                    'corrected_projected_total' => $correctedTotal,
                    'total_bias_correction' => (float) config('mlb.picks.total_bias_correction', 1.0),
                    'weather_present' => $weather !== null,
                ],
                marketSnapshot: $outcome,
                gameStartAt: MlbGameStart::for($game),
            );
        }

        return $rows;
    }

    private function supportsSide(mixed $adjustment, string $side): bool
    {
        if (! is_numeric($adjustment) || ! is_finite((float) $adjustment)) {
            return false;
        }

        return $side === 'over' ? (float) $adjustment > 0 : (float) $adjustment < 0;
    }
}
