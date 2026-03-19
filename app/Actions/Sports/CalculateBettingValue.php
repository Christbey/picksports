<?php

namespace App\Actions\Sports;

class CalculateBettingValue
{
    public function execute(object $game, string $sportKey = 'nba'): ?array
    {
        $prediction = $game->prediction ?? null;
        $oddsData = $game->odds_data ?? null;

        if (! $prediction || ! is_array($oddsData) || ! isset($oddsData['bookmakers'])) {
            return null;
        }

        $recommendations = [];

        $spreadsMarket = $this->extractMarket($oddsData, 'spreads');
        $totalsMarket = $this->extractMarket($oddsData, 'totals');
        $moneylineMarket = $this->extractMarket($oddsData, 'h2h');

        if ($spreadsMarket && $prediction->predicted_spread !== null) {
            $spreadRec = $this->analyzeSpread($game, $prediction, $spreadsMarket, $sportKey);
            if ($spreadRec) {
                $recommendations[] = $spreadRec;
            }
        }

        if ($totalsMarket && $prediction->predicted_total !== null) {
            $totalRec = $this->analyzeTotal($prediction, $totalsMarket, $sportKey);
            if ($totalRec) {
                $recommendations[] = $totalRec;
            }
        }

        if ($moneylineMarket && $prediction->win_probability !== null) {
            $mlRec = $this->analyzeMoneyline($game, $prediction, $moneylineMarket, $sportKey);
            if ($mlRec) {
                $recommendations[] = $mlRec;
            }
        }

        return $recommendations === [] ? null : $recommendations;
    }

    protected function analyzeSpread(object $game, object $prediction, array $market, string $sportKey): ?array
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
        if ($edge < $this->spreadThreshold($sportKey)) {
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
            // Display both lines in sportsbook convention (favorite negative, underdog positive)
            'model_line' => round($modelSpreadMarketConvention, 1),
            'market_line' => round((float) $homeSpread, 1),
            'model_home_line' => round(-(float) $prediction->predicted_spread, 1),
            'market_home_line' => round((float) $homeSpread, 1),
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'edge' => round($edge, 1),
            'odds' => (float) $selectedOdds,
            'confidence' => round((float) ($prediction->confidence_score ?? 0), 2),
            'reasoning' => 'Model spread diverges from market spread.',
        ];
    }

    protected function analyzeTotal(object $prediction, array $market, string $sportKey): ?array
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
        if ($edge < $this->totalThreshold($sportKey)) {
            return null;
        }

        $betOver = (float) $prediction->predicted_total > (float) $totalLine;

        return [
            'type' => 'total',
            'recommendation' => $betOver ? 'Bet Over' : 'Bet Under',
            'model_line' => round((float) $prediction->predicted_total, 1),
            'market_line' => round((float) $totalLine, 1),
            'edge' => round($edge, 1),
            'odds' => (float) ($betOver ? $overPrice : $underPrice),
            'confidence' => round((float) ($prediction->confidence_score ?? 0), 2),
            'reasoning' => 'Model total diverges from market total.',
        ];
    }

    protected function analyzeMoneyline(object $game, object $prediction, array $market, string $sportKey): ?array
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

        if ($edge < $this->moneylineThreshold($sportKey)) {
            return null;
        }

        $selectedPrice = $betHome ? (float) $homePrice : (float) $awayPrice;

        return [
            'type' => 'moneyline',
            'recommendation' => $betHome ? "Bet {$homeTeam} ML" : "Bet {$awayTeam} ML",
            'bet_team' => $betHome ? $homeTeam : $awayTeam,
            'model_probability' => round($modelProb * 100, 1),
            'implied_probability' => round($impliedProb * 100, 1),
            'edge' => round($edge * 100, 1),
            'odds' => $selectedPrice,
            'confidence' => round((float) ($prediction->confidence_score ?? 0), 2),
            'reasoning' => 'Model win probability exceeds market implied probability.',
        ];
    }

    protected function extractMarket(array $oddsData, string $marketKey): ?array
    {
        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) === $marketKey) {
                    return $market;
                }
            }
        }

        return null;
    }

    protected function spreadThreshold(string $sportKey): float
    {
        return (float) config("{$sportKey}.betting.edge_thresholds.spread", 2.5);
    }

    protected function totalThreshold(string $sportKey): float
    {
        return (float) config("{$sportKey}.betting.edge_thresholds.total", 3.0);
    }

    protected function moneylineThreshold(string $sportKey): float
    {
        return (float) config("{$sportKey}.betting.edge_thresholds.moneyline", 0.05);
    }

    protected function getTeamDisplayName(object $team): string
    {
        $display = trim(implode(' ', array_filter([
            $team->school ?? $team->location ?? null,
            $team->mascot ?? $team->name ?? null,
        ])));

        return $display !== '' ? $display : ($team->abbreviation ?? 'Unknown');
    }

    protected function teamMatchesOutcome(object $team, string $outcomeName, string $oddsApiTeamName): bool
    {
        $outcome = strtolower($outcomeName);
        $oddsApi = strtolower($oddsApiTeamName);

        foreach ([
            strtolower((string) ($team->school ?? '')),
            strtolower((string) ($team->location ?? '')),
            strtolower((string) ($team->name ?? '')),
            strtolower((string) ($team->mascot ?? '')),
            strtolower((string) ($team->abbreviation ?? '')),
        ] as $token) {
            if ($token !== '' && (str_contains($outcome, $token) || str_contains($oddsApi, $token))) {
                return true;
            }
        }

        return $outcome === $oddsApi;
    }

    protected function americanToImplied(float $odds): float
    {
        if ($odds > 0) {
            return 100 / ($odds + 100);
        }

        return abs($odds) / (abs($odds) + 100);
    }

    protected function formatLine(float $line): string
    {
        return $line > 0 ? '+'.number_format($line, 1) : number_format($line, 1);
    }
}
