<?php

namespace App\Actions\Sports;

use App\Actions\Sports\Concerns\InteractsWithBettingMarkets;

abstract class AbstractLineBasedCalculateBettingValue
{
    use InteractsWithBettingMarkets;

    public function execute(object $game): ?array
    {
        $prediction = $game->prediction ?? null;
        $oddsData = $game->odds_data ?? null;

        if (! $prediction || ! $oddsData || ! isset($oddsData['bookmakers'])) {
            return null;
        }

        $recommendations = [];

        $spreadsMarket = $this->extractMarket($oddsData, 'spreads');
        $totalsMarket = $this->extractMarket($oddsData, 'totals');
        $moneylineMarket = $this->extractMarket($oddsData, 'h2h');

        if ($spreadsMarket && $prediction->predicted_spread !== null) {
            $spreadRec = $this->analyzeSpread($game, $prediction, $spreadsMarket);
            if ($spreadRec) {
                $recommendations[] = $spreadRec;
            }
        }

        if ($totalsMarket && $prediction->predicted_total !== null) {
            $totalRec = $this->analyzeTotal($prediction, $totalsMarket);
            if ($totalRec) {
                $recommendations[] = $totalRec;
            }
        }

        if ($moneylineMarket && $prediction->win_probability !== null) {
            $moneylineRec = $this->analyzeMoneyline($game, $prediction, $moneylineMarket);
            if ($moneylineRec) {
                $recommendations[] = $moneylineRec;
            }
        }

        return empty($recommendations) ? null : $recommendations;
    }

    abstract protected function sportKey(): string;

    abstract protected function getTeamDisplayName(object $team): string;

    abstract protected function teamMatchesOutcome(object $team, string $outcomeName, string $oddsApiTeamName): bool;

    protected function analyzeSpread(object $game, object $prediction, array $market): ?array
    {
        $homeTeam = $this->getTeamDisplayName($game->homeTeam);
        $awayTeam = $this->getTeamDisplayName($game->awayTeam);

        $homeSpread = null;
        $homePrice = null;
        $awayPrice = null;

        foreach ($market['outcomes'] ?? [] as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }

            if ($this->teamMatchesOutcome($game->homeTeam, (string) ($outcome['name'] ?? ''), (string) ($game->odds_data['home_team'] ?? ''))) {
                $homeSpread = $outcome['point'] ?? null;
                $homePrice = $outcome['price'] ?? -110;
            } else {
                $awayPrice = $outcome['price'] ?? -110;
            }
        }

        if ($homeSpread === null) {
            return null;
        }

        $marketSpreadModelConvention = -$homeSpread;
        $modelSpreadMarketConvention = -(float) $prediction->predicted_spread;
        $edge = abs((float) $prediction->predicted_spread - (float) $marketSpreadModelConvention);

        if ($edge < $this->spreadThreshold()) {
            return null;
        }

        $betHome = (float) $prediction->predicted_spread > (float) $marketSpreadModelConvention;
        $selectedOdds = $betHome ? ($homePrice ?? -110) : ($awayPrice ?? -110);
        $betLine = $betHome ? (float) $homeSpread : (float) (-$homeSpread);

        return [
            'type' => 'spread',
            'game_id' => $game->id,
            'recommendation' => ($betHome ? "Bet {$homeTeam}" : "Bet {$awayTeam}").' '.$this->formatLine($betLine),
            'bet_team' => $betHome ? $homeTeam : $awayTeam,
            'model_line' => round($modelSpreadMarketConvention, 1),
            'market_line' => round((float) $homeSpread, 1),
            'model_home_line' => round(-(float) $prediction->predicted_spread, 1),
            'market_home_line' => round((float) $homeSpread, 1),
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'edge' => round($edge, 1),
            'odds' => $selectedOdds,
            'confidence' => $this->spreadConfidenceScore($edge),
            'side_confidence' => round((float) ($prediction->confidence_score ?? 0), 2),
            'reasoning' => $this->getSpreadReasoning((float) $prediction->predicted_spread, (float) $marketSpreadModelConvention, $betHome, $homeTeam, $awayTeam),
        ];
    }

    protected function analyzeTotal(object $prediction, array $market): ?array
    {
        $totalLine = null;
        $overPrice = null;
        $underPrice = null;

        foreach ($market['outcomes'] ?? [] as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }

            if (($outcome['name'] ?? null) === 'Over') {
                $totalLine = $outcome['point'] ?? null;
                $overPrice = $outcome['price'] ?? -110;
            } elseif (($outcome['name'] ?? null) === 'Under') {
                $underPrice = $outcome['price'] ?? -110;
            }
        }

        if ($totalLine === null) {
            return null;
        }

        $edge = abs((float) $prediction->predicted_total - (float) $totalLine);
        if ($edge < $this->totalThreshold()) {
            return null;
        }

        $betOver = (float) $prediction->predicted_total > (float) $totalLine;
        $modelTotal = round((float) $prediction->predicted_total, 1);
        $marketTotal = round((float) $totalLine, 1);

        return [
            'type' => 'total',
            'recommendation' => $betOver ? 'Bet Over' : 'Bet Under',
            'model_line' => $modelTotal,
            'market_line' => $marketTotal,
            'edge' => round($edge, 1),
            'odds' => $betOver ? $overPrice : $underPrice,
            'confidence' => $this->totalConfidenceScore($edge),
            'side_confidence' => round((float) ($prediction->confidence_score ?? 0), 2),
            'reasoning' => $betOver
                ? "Model projects {$modelTotal} points, {$edge} higher than market {$marketTotal}"
                : "Model projects {$modelTotal} points, {$edge} lower than market {$marketTotal}",
        ];
    }

    protected function analyzeMoneyline(object $game, object $prediction, array $market): ?array
    {
        $homeTeam = $this->getTeamDisplayName($game->homeTeam);
        $awayTeam = $this->getTeamDisplayName($game->awayTeam);

        $homePrice = null;
        $awayPrice = null;

        foreach ($market['outcomes'] ?? [] as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }

            if ($this->teamMatchesOutcome($game->homeTeam, (string) ($outcome['name'] ?? ''), (string) ($game->odds_data['home_team'] ?? ''))) {
                $homePrice = $outcome['price'] ?? null;
            } else {
                $awayPrice = $outcome['price'] ?? null;
            }
        }

        if (! is_numeric($homePrice) || ! is_numeric($awayPrice)) {
            return null;
        }

        $homeModelProb = (float) $prediction->win_probability;
        $awayModelProb = 1 - $homeModelProb;
        $betHome = $homeModelProb >= $awayModelProb;
        $modelProb = $betHome ? $homeModelProb : $awayModelProb;
        $impliedProb = $betHome
            ? $this->americanToImplied((float) $homePrice)
            : $this->americanToImplied((float) $awayPrice);
        $edge = $modelProb - $impliedProb;

        if ($edge < $this->moneylineThreshold()) {
            return null;
        }

        $price = $betHome ? (float) $homePrice : (float) $awayPrice;
        $kellyConfig = (array) config($this->sportKey().'.betting.kelly', []);
        $kellyFraction = (float) ($kellyConfig['fraction'] ?? 0.25);
        $maxKelly = (float) ($kellyConfig['max_percent'] ?? 5.0);
        $kellySizePercent = $this->kellyBet($modelProb, $price, $kellyFraction) * 100;

        return [
            'type' => 'moneyline',
            'recommendation' => $betHome ? "Bet {$homeTeam} ML" : "Bet {$awayTeam} ML",
            'bet_team' => $betHome ? $homeTeam : $awayTeam,
            'model_probability' => round($modelProb * 100, 1),
            'implied_probability' => round($impliedProb * 100, 1),
            'edge' => round($edge * 100, 1),
            'odds' => $price,
            'kelly_bet_size_percent' => max(0, min($maxKelly, round($kellySizePercent, 1))),
            'confidence' => $this->moneylineConfidenceScore($edge),
            'side_confidence' => round((float) ($prediction->confidence_score ?? 0), 2),
            'reasoning' => sprintf(
                'Safe side: model gives %d%% chance vs market implied %d%% (%+d%% edge)',
                round($modelProb * 100),
                round($impliedProb * 100),
                round($edge * 100)
            ),
        ];
    }

    protected function spreadThreshold(): float
    {
        return (float) config($this->sportKey().'.betting.edge_thresholds.spread');
    }

    protected function totalThreshold(): float
    {
        return (float) config($this->sportKey().'.betting.edge_thresholds.total');
    }

    protected function moneylineThreshold(): float
    {
        return (float) config($this->sportKey().'.betting.edge_thresholds.moneyline');
    }

    protected function spreadConfidenceScore(float $edge): float
    {
        return round(min(95, 50 + ($edge * 4)), 2);
    }

    protected function totalConfidenceScore(float $edge): float
    {
        return round(min(95, 50 + ($edge * 5)), 2);
    }

    protected function moneylineConfidenceScore(float $edge): float
    {
        $threshold = max(0.005, $this->moneylineThreshold());

        return round(min(95, 50 + (($edge / $threshold) * 7)), 2);
    }
}
