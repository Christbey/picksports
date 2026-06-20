<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\GameWeather;
use App\Models\MLB\Prediction;

class MlbTotalCandidateBuilder
{
    public function __construct(private readonly MlbPickMarketService $markets) {}

    /**
     * @return list<MlbPickCandidateData>
     */
    public function build(Prediction $prediction): array
    {
        $game = $prediction->game;
        if (! $game || ! is_numeric($prediction->predicted_total)) {
            return [];
        }

        $outcomes = $this->markets->totalOutcomes($game, ['totals']);
        $noVig = $this->markets->noVigTotals($outcomes);
        $correctedTotal = (float) $prediction->predicted_total - (float) config('mlb.picks.total_bias_correction', 1.0);
        $weather = GameWeather::query()->where('game_id', $game->id)->latest('observed_at')->first();
        $rows = [];

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
                $reasonCodes[] = $side === 'over' ? 'weather_supports_over' : 'weather_supports_under';
                $reasonCodes[] = 'park_support';
            } else {
                $riskFlags[] = 'weather_missing';
            }
            if ($this->markets->stale($game)) {
                $riskFlags[] = 'stale_price';
            }
            if (abs($projectedValue) < 0.75) {
                $riskFlags[] = 'low_total_edge';
            }

            $rows[] = new MlbPickCandidateData(
                gameId: (int) $game->id,
                predictionId: (int) $prediction->id,
                season: (int) $prediction->season,
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
                    'predicted_total' => (float) $prediction->predicted_total,
                    'corrected_projected_total' => $correctedTotal,
                    'total_bias_correction' => (float) config('mlb.picks.total_bias_correction', 1.0),
                    'weather_present' => $weather !== null,
                ],
                marketSnapshot: $outcome,
                gameStartAt: $game->game_date,
            );
        }

        return $rows;
    }
}
