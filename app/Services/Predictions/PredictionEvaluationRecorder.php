<?php

namespace App\Services\Predictions;

use App\Models\PredictionEvaluation;
use App\Support\Odds\MarketSpread;
use Illuminate\Database\Eloquent\Model;

class PredictionEvaluationRecorder
{
    public function record(Model $prediction, Model $game, string $sport, float $actualSpread, float $actualTotal): void
    {
        $actualHomeWin = $actualSpread === 0.0 ? null : ($actualSpread > 0 ? 1.0 : 0.0);
        $calibration = $this->extractCalibrationMetadata($prediction);
        $activeProbability = $this->numericValue($prediction->win_probability ?? null);
        $baselineProbability = $this->numericValue($calibration['baseline_win_probability'] ?? $activeProbability);
        $calibratedProbability = $this->numericValue($calibration['calibrated_win_probability'] ?? null);

        $activeWinProbabilityError = $this->probabilityError($actualHomeWin, $activeProbability);
        $activeBrierScore = $this->brierScore($actualHomeWin, $activeProbability);
        $baselineWinProbabilityError = $this->probabilityError($actualHomeWin, $baselineProbability);
        $baselineBrierScore = $this->brierScore($actualHomeWin, $baselineProbability);
        $calibratedWinProbabilityError = $this->probabilityError($actualHomeWin, $calibratedProbability);
        $calibratedBrierScore = $this->brierScore($actualHomeWin, $calibratedProbability);
        $baselineLogLoss = $this->logLoss($actualHomeWin, $baselineProbability);
        $calibratedLogLoss = $this->logLoss($actualHomeWin, $calibratedProbability);
        $activeLogLoss = $this->logLoss($actualHomeWin, $activeProbability);

        $marketSpread = $this->extractMarketSpread($prediction);
        $marketSpreadError = $marketSpread === null
            ? null
            : abs($actualSpread - $marketSpread['home_margin']);
        $modelSpreadError = is_numeric($prediction->spread_error ?? null)
            ? (float) $prediction->spread_error
            : null;

        PredictionEvaluation::query()->updateOrCreate(
            [
                'prediction_table' => $prediction->getTable(),
                'prediction_id' => (int) $prediction->getKey(),
                'model_version' => $prediction->model_version,
                'feature_version' => $prediction->feature_version,
                'blend_version' => $prediction->blend_version,
            ],
            [
                'sport' => $sport,
                'game_id' => (int) ($prediction->game_id ?? $game->getKey()),
                'actuals' => [
                    'actual_spread' => round($actualSpread, 1),
                    'actual_total' => round($actualTotal, 1),
                    'home_score' => (int) ($game->home_score ?? 0),
                    'away_score' => (int) ($game->away_score ?? 0),
                    'actual_home_win' => $actualHomeWin === null ? null : (bool) $actualHomeWin,
                ],
                'errors' => [
                    'spread_error' => $modelSpreadError,
                    'total_error' => is_numeric($prediction->total_error ?? null) ? (float) $prediction->total_error : null,
                    'winner_correct' => $actualHomeWin === null
                        ? null
                        : (bool) ($prediction->winner_correct ?? false),
                    'win_probability_error' => $activeWinProbabilityError,
                    'brier_score' => $activeBrierScore,
                    'log_loss' => $activeLogLoss,
                    'active_win_probability' => $activeProbability,
                    'active_win_probability_source' => $calibration['active_source'] ?? 'baseline',
                    'baseline_win_probability' => $baselineProbability,
                    'baseline_win_probability_error' => $baselineWinProbabilityError,
                    'baseline_brier_score' => $baselineBrierScore,
                    'baseline_log_loss' => $baselineLogLoss,
                    'calibrated_win_probability' => $calibratedProbability,
                    'calibrated_win_probability_error' => $calibratedWinProbabilityError,
                    'calibrated_brier_score' => $calibratedBrierScore,
                    'calibrated_log_loss' => $calibratedLogLoss,
                    'calibration_beats_baseline_brier' => $calibratedBrierScore !== null && $baselineBrierScore !== null
                        ? $calibratedBrierScore < $baselineBrierScore
                        : null,
                    'calibration_beats_baseline_log_loss' => $calibratedLogLoss !== null && $baselineLogLoss !== null
                        ? $calibratedLogLoss < $baselineLogLoss
                        : null,
                ],
                'market_comparison' => [
                    'bookmaker_home_spread' => $marketSpread['bookmaker_home_line'] ?? null,
                    'bookmaker_spread_convention' => $marketSpread === null
                        ? null
                        : MarketSpread::BOOKMAKER_HOME_LINE_CONVENTION,
                    'market_spread' => $marketSpread['home_margin'] ?? null,
                    'market_spread_convention' => $marketSpread === null
                        ? null
                        : MarketSpread::HOME_MARGIN_CONVENTION,
                    'market_spread_source' => $marketSpread['source'] ?? null,
                    'market_spread_error' => $marketSpreadError,
                    'model_beats_market_spread' => $marketSpreadError !== null && $modelSpreadError !== null
                        ? $modelSpreadError < $marketSpreadError
                        : null,
                ],
                'evaluated_at' => now(),
            ]
        );
    }

    /**
     * @return array{bookmaker_home_line: ?float, home_margin: float, source: string}|null
     */
    private function extractMarketSpread(Model $prediction): ?array
    {
        if (is_numeric($prediction->vegas_spread ?? null)) {
            $bookmakerHomeLine = (float) $prediction->vegas_spread;

            return [
                'bookmaker_home_line' => $bookmakerHomeLine,
                'home_margin' => MarketSpread::bookmakerHomeLineToHomeMargin($bookmakerHomeLine),
                'source' => 'vegas_spread',
            ];
        }

        if (is_numeric($prediction->market_spread ?? null)) {
            return [
                'bookmaker_home_line' => null,
                'home_margin' => (float) $prediction->market_spread,
                'source' => 'market_spread',
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractCalibrationMetadata(Model $prediction): array
    {
        $metadata = $prediction->model_metadata ?? null;
        if (! is_array($metadata)) {
            return [];
        }

        $calibration = $metadata['win_probability_calibration'] ?? null;

        return is_array($calibration) ? $calibration : [];
    }

    private function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function probabilityError(?float $actualHomeWin, ?float $probability): ?float
    {
        return $actualHomeWin === null || $probability === null
            ? null
            : abs($actualHomeWin - $probability);
    }

    private function brierScore(?float $actualHomeWin, ?float $probability): ?float
    {
        return $actualHomeWin === null || $probability === null
            ? null
            : ($actualHomeWin - $probability) ** 2;
    }

    private function logLoss(?float $actualHomeWin, ?float $probability): ?float
    {
        if ($actualHomeWin === null || $probability === null) {
            return null;
        }

        $probability = min(0.999999, max(0.000001, $probability));

        return -(($actualHomeWin * log($probability)) + ((1.0 - $actualHomeWin) * log(1.0 - $probability)));
    }
}
