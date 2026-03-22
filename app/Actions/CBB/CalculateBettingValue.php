<?php

namespace App\Actions\CBB;

use App\Actions\Sports\Concerns\InteractsWithBettingMarkets;
use App\Models\CBB\Game;

class CalculateBettingValue
{
    use InteractsWithBettingMarkets;

    public function execute(Game $game): ?array
    {
        $prediction = $game->prediction;
        $oddsData = $game->odds_data;

        if (! $prediction || ! $oddsData || ! isset($oddsData['bookmakers'])) {
            return null;
        }

        $recommendations = [];

        // Extract markets from odds data
        $spreadsMarket = $this->extractMarket($oddsData, 'spreads');
        $totalsMarket = $this->extractMarket($oddsData, 'totals');
        $moneylineMarket = $this->extractMarket($oddsData, 'h2h');

        // 1. SPREAD VALUE ANALYSIS
        if ($spreadsMarket && $prediction->predicted_spread !== null) {
            $spreadRec = $this->analyzeSpread($game, $prediction, $spreadsMarket);
            if ($spreadRec) {
                $recommendations[] = $spreadRec;
            }
        }

        // 2. TOTAL VALUE ANALYSIS
        if ($totalsMarket && $prediction->predicted_total !== null) {
            $totalRec = $this->analyzeTotal($game, $prediction, $totalsMarket);
            if ($totalRec) {
                $recommendations[] = $totalRec;
            }
        }

        // 3. MONEYLINE VALUE ANALYSIS
        if ($moneylineMarket && $prediction->win_probability !== null) {
            $mlRec = $this->analyzeMoneyline($game, $prediction, $moneylineMarket);
            if ($mlRec) {
                $recommendations[] = $mlRec;
            }
        }

        return empty($recommendations) ? null : $recommendations;
    }

    protected function analyzeSpread(Game $game, $prediction, array $market): ?array
    {
        $homeTeam = $game->homeTeam->school;
        $awayTeam = $game->awayTeam->school;

        // Find home team spread
        $homeSpread = null;
        $homePrice = null;
        $awayPrice = null;

        foreach ($market['outcomes'] as $outcome) {
            if (str_contains($outcome['name'], $homeTeam) || $outcome['name'] === $game->odds_data['home_team']) {
                $homeSpread = $outcome['point'] ?? null;
                $homePrice = $outcome['price'] ?? -110;
            } else {
                $awayPrice = $outcome['price'] ?? -110;
            }
        }

        if ($homeSpread === null) {
            return null;
        }

        // Calculate edge: difference between our prediction and the market
        // IMPORTANT: Model uses positive = home favored, negative = away favored
        // Market uses negative = favored, positive = underdog (standard betting convention)
        // Convert market spread to model's convention: negate the market spread
        $marketSpreadModelConvention = -$homeSpread;

        $betHome = $prediction->predicted_spread > $marketSpreadModelConvention;
        $edge = abs($prediction->predicted_spread - $marketSpreadModelConvention);

        $minEdge = $betHome
            ? (float) config('cbb.betting.edge_thresholds.spread')
            : (float) config('cbb.betting.edge_thresholds.spread_away', config('cbb.betting.edge_thresholds.spread'));

        $marketLineAbs = abs((float) $homeSpread);
        if ($this->isTournamentRound($game) && ! $betHome && $marketLineAbs >= (float) config('cbb.betting.filters.big_dog_line_threshold', 15.0)) {
            $minEdge = max($minEdge, (float) config('cbb.betting.filters.big_dog_min_edge', 6.0));
        }

        // Edge threshold for recommendation
        if ($edge < $minEdge) {
            return null;
        }

        // Determine which side to bet
        $selectedOdds = $betHome ? ($homePrice ?? -110) : ($awayPrice ?? -110);
        $betLine = $betHome ? (float) $homeSpread : (float) (-$homeSpread);

        return [
            'type' => 'spread',
            'game_id' => $game->id,
            'recommendation' => ($betHome ? "Bet {$homeTeam}" : "Bet {$awayTeam}").' '.$this->formatLine($betLine),
            'bet_team' => $betHome ? $homeTeam : $awayTeam,
            'model_line' => round($prediction->predicted_spread, 1),
            'market_line' => round($marketSpreadModelConvention, 1),
            'model_home_line' => round(-$prediction->predicted_spread, 1),
            'market_home_line' => round((float) $homeSpread, 1),
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'edge' => round($edge, 1),
            'odds' => $selectedOdds,
            'confidence' => $this->calculateSpreadConfidence($edge, $minEdge, $game, $betHome, $marketLineAbs),
            'side_confidence' => round((float) $prediction->confidence_score, 2),
            'reasoning' => $this->getSpreadReasoning($prediction->predicted_spread, $marketSpreadModelConvention, $betHome, $homeTeam, $awayTeam),
        ];
    }

    protected function analyzeTotal(Game $game, object $prediction, array $market): ?array
    {
        // Find the total line
        $totalLine = null;
        $overPrice = null;
        $underPrice = null;

        foreach ($market['outcomes'] as $outcome) {
            if ($outcome['name'] === 'Over') {
                $totalLine = $outcome['point'] ?? null;
                $overPrice = $outcome['price'] ?? -110;
            } elseif ($outcome['name'] === 'Under') {
                $underPrice = $outcome['price'] ?? -110;
            }
        }

        if ($totalLine === null) {
            return null;
        }

        $edge = abs($prediction->predicted_total - $totalLine);
        $betOver = $prediction->predicted_total > $totalLine;
        $minEdge = (float) config('cbb.betting.edge_thresholds.total');
        $highTotalMarketFloor = (float) config('cbb.betting.filters.high_total_under_market_floor', 155.0);
        $highTotalMinEdge = (float) config('cbb.betting.filters.high_total_under_min_edge', 5.5);
        $highTotalSkipEdge = (float) config('cbb.betting.filters.high_total_under_skip_edge', 15.0);
        $highTotalOverMarketFloor = (float) config('cbb.betting.filters.high_total_over_market_floor', 145.0);
        $highTotalOverMinEdge = (float) config('cbb.betting.filters.high_total_over_min_edge', 4.5);
        $highTotalOverSkipEdge = (float) config('cbb.betting.filters.high_total_over_skip_edge', 16.5);

        if ($this->isTournamentRound($game) && ! $betOver) {
            $minEdge = max($minEdge, (float) config('cbb.betting.filters.tournament_under_min_edge', 4.5));

            $marketTotalFloor = (float) config('cbb.betting.filters.tournament_under_market_total_floor', 145.0);
            $skipEdge = (float) config('cbb.betting.filters.tournament_under_skip_edge', 18.0);
            if ((float) $totalLine >= $marketTotalFloor && $edge >= $skipEdge) {
                return null;
            }
        }

        if (! $betOver && (float) $totalLine >= $highTotalMarketFloor) {
            $minEdge = max($minEdge, $highTotalMinEdge);

            if ($edge >= $highTotalSkipEdge) {
                return null;
            }
        }

        if ($betOver && (float) $totalLine >= $highTotalOverMarketFloor) {
            $minEdge = max($minEdge, $highTotalOverMinEdge);

            if ($edge >= $highTotalOverSkipEdge) {
                return null;
            }
        }

        // Edge threshold for totals
        if ($edge < $minEdge) {
            return null;
        }

        $modelTotal = round($prediction->predicted_total, 1);
        $marketTotal = round($totalLine, 1);
        $totalConfidence = $this->calculateTotalConfidence($edge, $minEdge, $game, $betOver, (float) $totalLine);

        return [
            'type' => 'total',
            'recommendation' => $betOver ? 'Bet Over' : 'Bet Under',
            'model_line' => $modelTotal,
            'market_line' => $marketTotal,
            'edge' => round($edge, 1),
            'odds' => $betOver ? $overPrice : $underPrice,
            'confidence' => $totalConfidence,
            'side_confidence' => round($prediction->confidence_score, 2),
            'reasoning' => $betOver
                ? "Model projects {$modelTotal} points, {$edge} higher than market {$marketTotal}"
                : "Model projects {$modelTotal} points, {$edge} lower than market {$marketTotal}",
        ];
    }

    protected function analyzeMoneyline(Game $game, object $prediction, array $market): ?array
    {
        $homeTeam = $game->homeTeam->school;
        $awayTeam = $game->awayTeam->school;
        $oddsHomeTeam = (string) ($game->odds_data['home_team'] ?? '');
        $oddsAwayTeam = (string) ($game->odds_data['away_team'] ?? '');

        // Find home and away moneyline prices
        $homePrice = null;
        $awayPrice = null;

        foreach ($market['outcomes'] as $outcome) {
            $outcomeName = (string) ($outcome['name'] ?? '');
            $price = $outcome['price'] ?? null;

            if (! is_numeric($price)) {
                continue;
            }

            $price = (float) $price;

            if (str_contains($outcomeName, $homeTeam) || $outcomeName === $oddsHomeTeam) {
                $homePrice = $price;
            } elseif (str_contains($outcomeName, $awayTeam) || $outcomeName === $oddsAwayTeam) {
                $awayPrice = $price;
            }
        }

        // Moneyline recommendation requires valid prices for both sides.
        if ($homePrice === null || $awayPrice === null) {
            return null;
        }

        // Convert American odds to implied probability
        $impliedHomeProb = $this->americanToImplied($homePrice);
        $impliedAwayProb = $this->americanToImplied($awayPrice);

        $homeModelProb = (float) $prediction->win_probability;
        $awayModelProb = 1 - $homeModelProb;

        // Safe side = team with higher model win probability.
        $betHome = $homeModelProb >= $awayModelProb;
        $modelProb = $betHome ? $homeModelProb : $awayModelProb;
        $impliedProb = $betHome ? $impliedHomeProb : $impliedAwayProb;
        $edge = $modelProb - $impliedProb;

        // Need sufficient positive edge for a recommendation.
        $minEdge = config('cbb.betting.edge_thresholds.moneyline');
        if ($edge < $minEdge) {
            return null;
        }

        $price = $betHome ? $homePrice : $awayPrice;

        // Calculate Kelly Criterion bet size
        $kellyConfig = config('cbb.betting.kelly');
        $kellySizePercent = $this->kellyBet($modelProb, $price, $kellyConfig['fraction']) * 100;
        $maxKelly = $kellyConfig['max_percent'];

        return [
            'type' => 'moneyline',
            'recommendation' => $betHome ? "Bet {$homeTeam} ML" : "Bet {$awayTeam} ML",
            'bet_team' => $betHome ? $homeTeam : $awayTeam,
            'model_probability' => round($modelProb * 100, 1),
            'implied_probability' => round($impliedProb * 100, 1),
            'edge' => round($edge * 100, 1),
            'odds' => $price,
            'kelly_bet_size_percent' => max(0, min($maxKelly, round($kellySizePercent, 1))),
            'confidence' => $this->calculateMoneylineConfidence($edge, $modelProb),
            'side_confidence' => round((float) $prediction->confidence_score, 2),
            'reasoning' => sprintf(
                'Safe side: model gives %d%% chance vs market implied %d%% (%+d%% edge)',
                round($modelProb * 100),
                round($impliedProb * 100),
                round($edge * 100)
            ),
        ];
    }

    protected function calculateTotalConfidence(float $edge, float $threshold, ?Game $game = null, bool $betOver = false, float $marketTotal = 0.0): float
    {
        $confidence = 50 + (($edge / $threshold) * 10);

        if ($game && $this->isTournamentRound($game) && ! $betOver) {
            if ($marketTotal >= 145.0) {
                $confidence -= 8;
            }
            if ($edge >= 12.0) {
                $confidence -= 7;
            }
        }

        if (! $betOver && $marketTotal >= (float) config('cbb.betting.filters.high_total_under_market_floor', 155.0)) {
            $confidence -= (float) config('cbb.betting.filters.high_total_under_confidence_penalty', 10.0);
        }

        if ($betOver && $marketTotal >= (float) config('cbb.betting.filters.high_total_over_market_floor', 145.0)) {
            $confidence -= (float) config('cbb.betting.filters.high_total_over_confidence_penalty', 8.0);
        }

        return round(max(50, min(95, $confidence)), 2);
    }

    protected function calculateSpreadConfidence(float $edge, float $threshold, Game $game, bool $betHome, float $marketLineAbs): float
    {
        $confidence = 50 + (($edge / max(0.1, $threshold)) * 9);

        if ($this->isTournamentRound($game) && ! $betHome && $marketLineAbs >= 15.0) {
            $confidence -= 8;
        }

        return round(max(50, min(95, $confidence)), 2);
    }

    protected function calculateMoneylineConfidence(float $edge, float $modelProbability): float
    {
        $threshold = max(0.005, (float) config('cbb.betting.edge_thresholds.moneyline'));
        $confidence = 50 + (($edge / $threshold) * 7) + max(0, ($modelProbability - 0.5) * 20);

        return round(max(50, min(95, $confidence)), 2);
    }

    protected function isTournamentRound(Game $game): bool
    {
        return in_array((string) ($game->tournament_round ?? ''), ['round_of_64', 'round_of_32'], true);
    }
}
