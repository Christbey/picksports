<?php

namespace App\Actions\Sports\Concerns;

trait InteractsWithBettingMarkets
{
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

    protected function americanToImplied(int|float $odds): float
    {
        if ($odds > 0) {
            return 100 / ($odds + 100);
        }

        return abs($odds) / (abs($odds) + 100);
    }

    protected function kellyBet(float $probability, int|float $odds, float $fraction = 0.25): float
    {
        $decimalOdds = $odds > 0
            ? ($odds / 100) + 1
            : (100 / abs($odds)) + 1;

        $kelly = ($probability * $decimalOdds - 1) / ($decimalOdds - 1);

        return $kelly * $fraction;
    }

    protected function getSpreadReasoning(float $modelSpread, float $marketSpread, bool $betHome, string $homeTeam, string $awayTeam): string
    {
        $diff = round(abs($modelSpread - $marketSpread), 1);
        $betTeam = $betHome ? $homeTeam : $awayTeam;

        return "Model has {$diff}-point value on {$betTeam}";
    }

    protected function formatLine(float $line): string
    {
        return $line > 0 ? '+'.number_format($line, 1) : number_format($line, 1);
    }
}
