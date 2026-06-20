<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\Prediction;

class MlbRunLineCandidateBuilder
{
    public function __construct(private readonly MlbPickMarketService $markets) {}

    /**
     * @return list<MlbPickCandidateData>
     */
    public function build(Prediction $prediction): array
    {
        $game = $prediction->game;
        if (! $game || ! is_numeric($prediction->predicted_spread)) {
            return [];
        }

        $outcomes = $this->markets->sideOutcomes($game, ['spreads']);
        $noVig = $this->markets->noVigSides($outcomes);
        $homeMargin = (float) $prediction->predicted_spread;
        $rows = [];

        foreach (['home', 'away'] as $side) {
            $outcome = $outcomes[$side];
            if (! $outcome || ! is_numeric($outcome['line'] ?? null)) {
                continue;
            }

            $projectedValue = $side === 'home'
                ? $homeMargin + (float) $outcome['line']
                : (-1 * $homeMargin) + (float) $outcome['line'];
            $modelProbability = max(0.05, min(0.95, 0.5 + ($projectedValue / 6)));
            $marketProbability = $this->markets->implied((int) $outcome['price']);
            $reasonCodes = ['model_margin_edge', $side === 'home' ? 'favorite_cover_support' : 'underdog_run_support'];
            $riskFlags = ['high_variance_run_line'];

            if (abs($projectedValue) < 0.75) {
                $riskFlags[] = 'one_run_game_risk';
            }
            if (($prediction->predicted_total !== null) && (float) $prediction->predicted_total < 7.5) {
                $riskFlags[] = 'low_total_run_line_risk';
            }
            if ($this->markets->stale($game)) {
                $riskFlags[] = 'stale_price';
            }

            $rows[] = new MlbPickCandidateData(
                gameId: (int) $game->id,
                predictionId: (int) $prediction->id,
                season: (int) $prediction->season,
                marketType: 'run_line',
                marketKey: 'spreads',
                side: $side,
                line: (float) $outcome['line'],
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
                featureSnapshot: ['predicted_home_margin' => $homeMargin],
                marketSnapshot: $outcome,
                teamId: (int) ($side === 'home' ? $game->home_team_id : $game->away_team_id),
                gameStartAt: $game->game_date,
            );
        }

        return $rows;
    }
}
