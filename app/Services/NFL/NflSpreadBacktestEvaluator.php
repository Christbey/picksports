<?php

namespace App\Services\NFL;

class NflSpreadBacktestEvaluator
{
    /**
     * @return array<string,mixed>
     */
    public function evaluate(
        float $modelHomeMargin,
        float $entryHomeSpread,
        float $actualHomeMargin,
        ?float $closingHomeSpread = null
    ): array {
        $entryMarketHomeMargin = $this->homeMarginFromSportsbookHomeSpread($entryHomeSpread);
        $closingMarketHomeMargin = $closingHomeSpread !== null
            ? $this->homeMarginFromSportsbookHomeSpread($closingHomeSpread)
            : null;
        $pick = $this->pickSide($modelHomeMargin, $entryMarketHomeMargin);
        $coverMargin = $this->coverMargin($pick, $actualHomeMargin, $entryMarketHomeMargin);
        $result = $this->result($coverMargin);

        return [
            'model_spread' => $modelHomeMargin,
            'market_spread' => $entryMarketHomeMargin,
            'market_home_line' => $entryHomeSpread,
            'closing_spread' => $closingMarketHomeMargin,
            'closing_home_line' => $closingHomeSpread,
            'actual_margin' => $actualHomeMargin,
            'pick' => $pick,
            'cover_margin' => $coverMargin,
            'result' => $result,
            'won' => $result === 'win',
            'push' => $result === 'push',
            'edge' => abs($modelHomeMargin - $entryMarketHomeMargin),
            'clv' => $this->spreadClv($pick, $entryMarketHomeMargin, $closingMarketHomeMargin),
            'data_quality_flags' => $this->dataQualityFlags($entryHomeSpread, $closingHomeSpread),
        ];
    }

    public function homeMarginFromSportsbookHomeSpread(float $homeSpread): float
    {
        return -$homeSpread;
    }

    public function pickSide(float $modelHomeMargin, float $marketHomeMargin): string
    {
        if (abs($modelHomeMargin - $marketHomeMargin) < 0.0001) {
            return 'none';
        }

        return $modelHomeMargin > $marketHomeMargin ? 'home' : 'away';
    }

    public function coverMargin(string $pick, float $actualHomeMargin, float $marketHomeMargin): ?float
    {
        return match ($pick) {
            'home' => round($actualHomeMargin - $marketHomeMargin, 3),
            'away' => round($marketHomeMargin - $actualHomeMargin, 3),
            default => null,
        };
    }

    public function result(?float $coverMargin): string
    {
        if ($coverMargin === null) {
            return 'no_pick';
        }

        if (abs($coverMargin) < 0.0001) {
            return 'push';
        }

        return $coverMargin > 0 ? 'win' : 'loss';
    }

    public function spreadClv(string $pick, float $entryMarketHomeMargin, ?float $closingMarketHomeMargin): ?float
    {
        if ($closingMarketHomeMargin === null) {
            return null;
        }

        return match ($pick) {
            'home' => round($closingMarketHomeMargin - $entryMarketHomeMargin, 3),
            'away' => round($entryMarketHomeMargin - $closingMarketHomeMargin, 3),
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public function dataQualityFlags(float $entryHomeSpread, ?float $closingHomeSpread = null): array
    {
        $flags = [];
        $maxAbsSpread = (float) config('nfl.betting.backtest.max_abs_home_spread', 21.0);

        if (abs($entryHomeSpread) > $maxAbsSpread) {
            $flags[] = 'implausible_entry_spread';
        }

        if ($closingHomeSpread !== null && abs($closingHomeSpread) > $maxAbsSpread) {
            $flags[] = 'implausible_closing_spread';
        }

        return $flags;
    }
}
