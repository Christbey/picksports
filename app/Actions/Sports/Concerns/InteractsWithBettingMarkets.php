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

    /**
     * @return array{home:float,away:float,raw_home:float,raw_away:float,hold:float}|null
     */
    protected function noVigMoneylineProbabilities(int|float $homeOdds, int|float $awayOdds): ?array
    {
        $rawHome = $this->americanToImplied($homeOdds);
        $rawAway = $this->americanToImplied($awayOdds);
        $total = $rawHome + $rawAway;

        if ($total <= 0) {
            return null;
        }

        return [
            'home' => $rawHome / $total,
            'away' => $rawAway / $total,
            'raw_home' => $rawHome,
            'raw_away' => $rawAway,
            'hold' => max(0.0, $total - 1.0),
        ];
    }

    protected function americanPayoutPerUnit(int|float $odds): float
    {
        return $odds > 0 ? ((float) $odds / 100) : (100 / abs((float) $odds));
    }

    protected function expectedValuePerUnit(float $probability, int|float $odds): float
    {
        return ($probability * $this->americanPayoutPerUnit($odds)) - (1 - $probability);
    }

    protected function probabilityToAmericanOdds(float $probability): int
    {
        $probability = max(0.001, min(0.999, $probability));

        if ($probability >= 0.5) {
            return (int) round(-100 * $probability / (1 - $probability));
        }

        return (int) round((100 * (1 - $probability)) / $probability);
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
