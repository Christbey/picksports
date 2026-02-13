<?php

namespace App\Actions\NFL;

use App\Models\NFL\Game;

class CalculateBettingValue
{
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
            $totalRec = $this->analyzeTotal($prediction, $totalsMarket);
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
        $homeTeam = $this->getTeamDisplayName($game->homeTeam);
        $awayTeam = $this->getTeamDisplayName($game->awayTeam);

        // Find home team spread
        $homeSpread = null;
        $homePrice = null;

        foreach ($market['outcomes'] as $outcome) {
            if ($this->teamMatchesOutcome($game->homeTeam, $outcome['name'], $game->odds_data['home_team'] ?? '')) {
                $homeSpread = $outcome['point'] ?? null;
                $homePrice = $outcome['price'] ?? -110;
                break;
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

        $edge = abs($prediction->predicted_spread - $marketSpreadModelConvention);

        // Edge threshold for recommendation
        if ($edge < config('nfl.betting.edge_thresholds.spread')) {
            return null;
        }

        // Determine which side to bet
        $betHome = $prediction->predicted_spread > $marketSpreadModelConvention;

        return [
            'type' => 'spread',
            'game_id' => $game->id,
            'recommendation' => $betHome ? "Bet {$homeTeam}" : "Bet {$awayTeam}",
            'bet_team' => $betHome ? $homeTeam : $awayTeam,
            'model_line' => round($prediction->predicted_spread, 1),
            'market_line' => round($marketSpreadModelConvention, 1),
            'edge' => round($edge, 1),
            'odds' => $homePrice,
            'confidence' => round($prediction->confidence_score, 2),
            'reasoning' => $this->getSpreadReasoning($prediction->predicted_spread, $marketSpreadModelConvention, $betHome, $homeTeam, $awayTeam),
        ];
    }

    protected function analyzeTotal(object $prediction, array $market): ?array
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

        // Edge threshold for totals
        if ($edge < config('nfl.betting.edge_thresholds.total')) {
            return null;
        }

        $betOver = $prediction->predicted_total > $totalLine;
        $modelTotal = round($prediction->predicted_total, 1);
        $marketTotal = round($totalLine, 1);

        return [
            'type' => 'total',
            'recommendation' => $betOver ? 'Bet Over' : 'Bet Under',
            'model_line' => $modelTotal,
            'market_line' => $marketTotal,
            'edge' => round($edge, 1),
            'odds' => $betOver ? $overPrice : $underPrice,
            'confidence' => round($prediction->confidence_score, 2),
            'reasoning' => $betOver
                ? "Model projects {$modelTotal} points, {$edge} higher than market {$marketTotal}"
                : "Model projects {$modelTotal} points, {$edge} lower than market {$marketTotal}",
        ];
    }

    protected function analyzeMoneyline(Game $game, object $prediction, array $market): ?array
    {
        $homeTeam = $this->getTeamDisplayName($game->homeTeam);
        $awayTeam = $this->getTeamDisplayName($game->awayTeam);

        // Find home and away moneyline prices
        $homePrice = null;
        $awayPrice = null;

        foreach ($market['outcomes'] as $outcome) {
            if ($this->teamMatchesOutcome($game->homeTeam, $outcome['name'], $game->odds_data['home_team'] ?? '')) {
                $homePrice = $outcome['price'];
            } else {
                $awayPrice = $outcome['price'];
            }
        }

        if ($homePrice === null || $awayPrice === null) {
            return null;
        }

        // Convert American odds to implied probability
        $impliedHomeProb = $this->americanToImplied($homePrice);
        $impliedAwayProb = $this->americanToImplied($awayPrice);

        // Calculate edge: our probability vs market probability
        $homeEdge = $prediction->win_probability - $impliedHomeProb;
        $awayEdge = (1 - $prediction->win_probability) - $impliedAwayProb;

        // Need sufficient edge for a recommendation
        $minEdge = config('nfl.betting.edge_thresholds.moneyline');

        if (abs($homeEdge) < $minEdge && abs($awayEdge) < $minEdge) {
            return null;
        }

        $betHome = $homeEdge > $awayEdge;
        $edge = $betHome ? $homeEdge : $awayEdge;
        $price = $betHome ? $homePrice : $awayPrice;
        $modelProb = $betHome ? $prediction->win_probability : (1 - $prediction->win_probability);
        $impliedProb = $betHome ? $impliedHomeProb : $impliedAwayProb;

        // Calculate Kelly Criterion bet size
        $kellyConfig = config('nfl.betting.kelly');
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
            'confidence' => round($prediction->confidence_score, 2),
            'reasoning' => sprintf(
                'Model gives %d%% chance vs market implied %d%% (%+d%% edge)',
                round($modelProb * 100),
                round($impliedProb * 100),
                round($edge * 100)
            ),
        ];
    }

    protected function extractMarket(array $oddsData, string $marketKey): ?array
    {
        foreach ($oddsData['bookmakers'] as $bookmaker) {
            foreach ($bookmaker['markets'] as $market) {
                if ($market['key'] === $marketKey) {
                    return $market;
                }
            }
        }

        return null;
    }

    protected function getTeamDisplayName($team): string
    {
        // NFL teams use location + name for display
        $location = $team->location ?? '';
        $name = $team->name ?? '';

        return trim("{$location} {$name}") ?: $team->abbreviation ?? 'Unknown';
    }

    protected function teamMatchesOutcome($team, string $outcomeName, string $oddsApiTeamName): bool
    {
        $outcomeLower = strtolower($outcomeName);
        $oddsApiLower = strtolower($oddsApiTeamName);

        // Normalize "Los Angeles" to "LA" for comparison
        $outcomeLower = str_replace('los angeles', 'la', $outcomeLower);
        $oddsApiLower = str_replace('los angeles', 'la', $oddsApiLower);

        $teamLocation = strtolower($team->location ?? '');

        // Check if team location is contained in the outcome name
        if (! empty($teamLocation) && (str_contains($outcomeLower, $teamLocation) || str_contains($oddsApiLower, $teamLocation))) {
            return true;
        }

        // Check team name
        $teamName = strtolower($team->name ?? '');
        if (! empty($teamName) && str_contains($outcomeLower, $teamName)) {
            return true;
        }

        return $outcomeLower === $oddsApiLower;
    }

    protected function americanToImplied(int|float $odds): float
    {
        if ($odds > 0) {
            return 100 / ($odds + 100);
        }

        return abs($odds) / (abs($odds) + 100);
    }

    protected function kellyBet(float $probability, int|float $odds, float $fraction = 0.25): float
    {
        // Convert American odds to decimal
        $decimalOdds = $odds > 0
            ? ($odds / 100) + 1
            : (100 / abs($odds)) + 1;

        // Kelly Criterion: (probability * decimal_odds - 1) / (decimal_odds - 1)
        $kelly = ($probability * $decimalOdds - 1) / ($decimalOdds - 1);

        // Use fractional Kelly (more conservative)
        return $kelly * $fraction;
    }

    protected function getSpreadReasoning(float $modelSpread, float $marketSpread, bool $betHome, string $homeTeam, string $awayTeam): string
    {
        $diff = round(abs($modelSpread - $marketSpread), 1);

        if ($betHome) {
            // Model favors home team
            if ($marketSpread > 0) {
                // Market also favors home team
                return sprintf(
                    'Model predicts %s by %.1f, market has %s by %.1f (%.1f point edge)',
                    $homeTeam,
                    abs($modelSpread),
                    $homeTeam,
                    abs($marketSpread),
                    $diff
                );
            } else {
                // Market favors away team
                return sprintf(
                    'Model predicts %s by %.1f, market has %s by %.1f (%.1f point edge)',
                    $homeTeam,
                    abs($modelSpread),
                    $awayTeam,
                    abs($marketSpread),
                    $diff
                );
            }
        }

        // Model favors away team
        if ($marketSpread < 0) {
            // Market also favors away team
            return sprintf(
                'Model predicts %s by %.1f, market has %s by %.1f (%.1f point edge)',
                $awayTeam,
                abs($marketSpread),
                $awayTeam,
                abs($modelSpread),
                $diff
            );
        } else {
            // Market favors home team
            return sprintf(
                'Model predicts %s by %.1f, market has %s by %.1f (%.1f point edge)',
                $awayTeam,
                abs($marketSpread),
                $homeTeam,
                abs($modelSpread),
                $diff
            );
        }
    }
}
