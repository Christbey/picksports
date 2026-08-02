<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\Game;
use App\Models\MLB\PlayerProp;
use App\Support\MLB\MlbGameStart;

class MlbPlayerPropCandidateBuilder
{
    /**
     * @return list<MlbPickCandidateData>
     */
    public function build(Game $game): array
    {
        return PlayerProp::query()
            ->where('game_id', $game->id)
            ->whereNotNull('line')
            ->whereNotNull('over_price')
            ->whereNotNull('under_price')
            ->get()
            ->map(fn (PlayerProp $prop): ?MlbPickCandidateData => $this->candidate($game, $prop))
            ->filter()
            ->values()
            ->all();
    }

    private function candidate(Game $game, PlayerProp $prop): ?MlbPickCandidateData
    {
        $overProbability = $this->normalizeProbability($prop->predicted_over_probability);
        $marketOver = $this->normalizeProbability($prop->market_over_probability);
        if ($overProbability === null || $marketOver === null) {
            return null;
        }

        $side = in_array($prop->recommended_side, ['over', 'under'], true)
            ? (string) $prop->recommended_side
            : ($overProbability >= $marketOver ? 'over' : 'under');
        $modelProbability = $side === 'over' ? $overProbability : 1 - $overProbability;
        $marketProbability = $side === 'over' ? $marketOver : 1 - $marketOver;
        $price = $side === 'over' ? (int) $prop->over_price : (int) $prop->under_price;
        $edge = $modelProbability - $marketProbability;
        $riskFlags = [];

        if (($prop->data_quality_score ?? 0) < 60) {
            $riskFlags[] = 'low_sample_prop';
        }
        if ($prop->fetched_at !== null && $prop->fetched_at->lt(now()->subHours((int) config('mlb.signals.odds_stale_hours', 12)))) {
            $riskFlags[] = 'prop_market_stale';
        }
        if (($prop->match_quality_score ?? 100) < 60) {
            $riskFlags[] = 'lineup_not_confirmed';
        }

        return new MlbPickCandidateData(
            gameId: (int) $game->id,
            predictionId: $game->prediction?->id ? (int) $game->prediction->id : null,
            season: (int) $game->season,
            marketType: 'player_prop',
            marketKey: (string) $prop->market,
            side: $side,
            line: (float) $prop->line,
            price: $price,
            book: $prop->bookmaker,
            modelProbability: $modelProbability,
            marketProbability: $marketProbability,
            noVigProbability: null,
            blendProbability: ($modelProbability * 0.5) + ($marketProbability * 0.5),
            edgeRaw: $edge,
            edgeNoVig: $edge,
            projectedValue: (float) ($prop->edge_probability ?? ($edge * 100)),
            reasonCodes: ['player_recent_form_support', 'season_rate_support', 'matchup_support', 'line_value'],
            riskFlags: $riskFlags,
            featureSnapshot: [
                'prop_id' => $prop->id,
                'player_name' => $prop->player_name,
                'market' => $prop->market,
                'confidence_score' => $prop->confidence_score,
                'data_quality_score' => $prop->data_quality_score,
                'match_quality_score' => $prop->match_quality_score,
            ],
            marketSnapshot: [
                'over_price' => $prop->over_price,
                'under_price' => $prop->under_price,
                'book' => $prop->bookmaker,
                'fetched_at' => $prop->fetched_at?->toISOString(),
            ],
            playerId: $prop->player_id ? (int) $prop->player_id : null,
            gameStartAt: MlbGameStart::for($game),
        );
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
