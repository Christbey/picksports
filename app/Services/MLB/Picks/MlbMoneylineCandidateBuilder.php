<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\Prediction;
use App\Support\MLB\MlbGameStart;

class MlbMoneylineCandidateBuilder
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

        $outcomes = $this->markets->sideOutcomes($game, ['h2h']);
        $noVig = $this->markets->noVigSides($outcomes);
        $model = [
            'home' => $this->normalizeProbability($prediction->win_probability),
            'away' => $prediction->win_probability !== null ? 1 - $this->normalizeProbability($prediction->win_probability) : null,
        ];
        $modelSide = ($model['home'] ?? 0.0) >= ($model['away'] ?? 0.0) ? 'home' : 'away';
        $marketSide = ($noVig['home'] ?? 0.0) >= ($noVig['away'] ?? 0.0) ? 'home' : 'away';

        $rows = [];
        foreach (['home', 'away'] as $side) {
            $outcome = $outcomes[$side];
            if (! $outcome || $model[$side] === null) {
                continue;
            }

            $marketProbability = $this->markets->implied((int) $outcome['price']);
            $blendProbability = $noVig[$side] !== null ? ($model[$side] * 0.25) + ($noVig[$side] * 0.75) : null;
            $reasonCodes = [
                $side === $modelSide && $side === $marketSide ? 'model_market_agrees' : 'model_market_disagrees',
                $side === 'home' ? 'home_field_support' : 'market_underdog',
            ];
            $riskFlags = [];

            if ($noVig[$side] === null) {
                $riskFlags[] = 'missing_no_vig';
            }
            if ($this->markets->stale($game)) {
                $riskFlags[] = 'stale_odds';
            }
            if (! $game->probable_home_pitcher_espn_id || ! $game->probable_away_pitcher_espn_id) {
                $riskFlags[] = 'unconfirmed_pitcher';
            } else {
                $reasonCodes[] = 'probable_pitchers_confirmed';
            }
            if (($model[$side] ?? 0.0) < 0.52) {
                $riskFlags[] = 'low_model_confidence';
            }
            if ($side === 'away') {
                $riskFlags[] = 'away_pick_risk';
            }

            $rows[] = new MlbPickCandidateData(
                gameId: (int) $game->id,
                predictionId: (int) $prediction->id,
                season: (int) $prediction->season,
                marketType: 'moneyline',
                marketKey: 'h2h',
                side: $side,
                line: null,
                price: (int) $outcome['price'],
                book: $outcome['book'] ?? null,
                modelProbability: $model[$side],
                marketProbability: $marketProbability,
                noVigProbability: $noVig[$side],
                blendProbability: $blendProbability,
                edgeRaw: $marketProbability !== null ? $model[$side] - $marketProbability : null,
                edgeNoVig: $noVig[$side] !== null ? $model[$side] - $noVig[$side] : null,
                projectedValue: $model[$side],
                reasonCodes: $reasonCodes,
                riskFlags: $riskFlags,
                featureSnapshot: [
                    'model_side' => $modelSide,
                    'market_side' => $marketSide,
                    'home_win_probability' => $model['home'],
                    'away_win_probability' => $model['away'],
                ],
                marketSnapshot: $outcome,
                teamId: (int) ($side === 'home' ? $game->home_team_id : $game->away_team_id),
                gameStartAt: MlbGameStart::for($game),
            );
        }

        return $rows;
    }

    private function normalizeProbability(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $probability = (float) $value;

        return $probability > 1 ? $probability / 100 : $probability;
    }
}
