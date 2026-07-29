<?php

namespace App\Services\Predictions;

use App\Support\Odds\MarketSpread;

class SnapshotEvaluationProjector
{
    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>  $actuals
     * @param  array<string, mixed>  $marketContext
     * @return array{errors:array<string,mixed>,market_comparison:array<string,mixed>}
     */
    public function project(array $outputs, array $actuals, array $marketContext): array
    {
        $actualSpread = $this->numeric($actuals['actual_spread'] ?? null);
        $actualTotal = $this->numeric($actuals['actual_total'] ?? null);
        $predictedSpread = $this->numeric($outputs['predicted_spread'] ?? $outputs['blended_predicted_spread'] ?? null);
        $predictedTotal = $this->numeric($outputs['predicted_total'] ?? $outputs['blended_predicted_total'] ?? null);
        $winProbability = $this->numeric($outputs['win_probability'] ?? null);
        $actualHomeWin = $actualSpread === null || $actualSpread === 0.0
            ? null
            : ($actualSpread > 0 ? 1.0 : 0.0);
        $spreadError = $actualSpread === null || $predictedSpread === null
            ? null
            : abs($actualSpread - $predictedSpread);
        $bookmakerHomeLine = $this->numeric(
            $marketContext['bookmaker_home_line']
                ?? $marketContext['bookmaker_home_spread']
                ?? $marketContext['vegas_spread']
                ?? null
        );
        $marketHomeMargin = $this->numeric($marketContext['market_home_margin'] ?? null);

        if ($marketHomeMargin === null && $bookmakerHomeLine !== null) {
            $marketHomeMargin = MarketSpread::bookmakerHomeLineToHomeMargin($bookmakerHomeLine);
        }

        if ($marketHomeMargin === null) {
            $marketHomeMargin = $this->numeric($marketContext['market_spread'] ?? null);
        }

        $marketSpreadError = $actualSpread === null || $marketHomeMargin === null
            ? null
            : abs($actualSpread - $marketHomeMargin);

        return [
            'errors' => [
                'spread_error' => $spreadError,
                'total_error' => $actualTotal === null || $predictedTotal === null
                    ? null
                    : abs($actualTotal - $predictedTotal),
                'winner_correct' => $actualHomeWin === null || $predictedSpread === null
                    ? null
                    : (($actualSpread > 0 && $predictedSpread > 0)
                        || ($actualSpread < 0 && $predictedSpread < 0)),
                'win_probability_error' => $actualHomeWin === null || $winProbability === null
                    ? null
                    : abs($actualHomeWin - $winProbability),
                'brier_score' => $actualHomeWin === null || $winProbability === null
                    ? null
                    : ($actualHomeWin - $winProbability) ** 2,
                'log_loss' => $this->logLoss($actualHomeWin, $winProbability),
            ],
            'market_comparison' => [
                'bookmaker_home_spread' => $bookmakerHomeLine,
                'bookmaker_spread_convention' => $bookmakerHomeLine === null
                    ? null
                    : MarketSpread::BOOKMAKER_HOME_LINE_CONVENTION,
                'market_spread' => $marketHomeMargin,
                'market_spread_convention' => $marketHomeMargin === null
                    ? null
                    : MarketSpread::HOME_MARGIN_CONVENTION,
                'market_spread_error' => $marketSpreadError,
                'model_beats_market_spread' => $spreadError !== null && $marketSpreadError !== null
                    ? $spreadError < $marketSpreadError
                    : null,
            ],
        ];
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function logLoss(?float $actual, ?float $probability): ?float
    {
        if ($actual === null || $probability === null) {
            return null;
        }

        $probability = min(0.999999, max(0.000001, $probability));

        return -(($actual * log($probability)) + ((1.0 - $actual) * log(1.0 - $probability)));
    }
}
