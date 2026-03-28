<?php

namespace App\Services\NBA;

use Illuminate\Support\Facades\File;

class WinProbabilityCalibrationInferenceService
{
    /**
     * @return array<string, mixed>
     */
    public function calibrate(string $sport, float $baselineProbability): array
    {
        $config = config("{$sport}.prediction.win_probability_calibration", []);
        $enabled = (bool) ($config['enabled'] ?? false);
        $applyToLiveOutput = (bool) ($config['apply_to_live_output'] ?? false);
        $artifactPath = (string) ($config['artifact_path'] ?? '');
        $baselineProbability = $this->clipProbability($baselineProbability);
        $result = [
            'enabled' => $enabled,
            'artifact_path' => $artifactPath,
            'baseline_win_probability' => round($baselineProbability, 6),
            'calibrated_win_probability' => round($baselineProbability, 6),
            'active_win_probability' => round($baselineProbability, 6),
            'active_source' => 'baseline',
            'apply_to_live_output' => $applyToLiveOutput,
            'model_type' => null,
            'alpha' => null,
            'beta' => null,
            'reason' => $enabled ? 'artifact_not_found' : 'feature_disabled',
        ];

        if (! $enabled || $artifactPath === '' || ! File::exists($artifactPath)) {
            return $result;
        }

        $artifact = json_decode((string) File::get($artifactPath), true);
        if (! is_array($artifact)) {
            $result['reason'] = 'invalid_artifact';

            return $result;
        }

        $alpha = is_numeric($artifact['alpha'] ?? null) ? (float) $artifact['alpha'] : null;
        $beta = is_numeric($artifact['beta'] ?? null) ? (float) $artifact['beta'] : null;

        if ($alpha === null || $beta === null) {
            $result['model_type'] = $artifact['model_type'] ?? null;
            $result['reason'] = 'missing_parameters';

            return $result;
        }

        $logit = log($baselineProbability / (1.0 - $baselineProbability));
        $calibratedProbability = $this->sigmoid(($alpha * $logit) + $beta);
        $result['model_type'] = $artifact['model_type'] ?? null;
        $result['alpha'] = $alpha;
        $result['beta'] = $beta;
        $result['calibrated_win_probability'] = round($calibratedProbability, 6);
        $result['reason'] = 'calibrated';

        if ($applyToLiveOutput) {
            $result['active_win_probability'] = round($calibratedProbability, 6);
            $result['active_source'] = 'calibrated';
        }

        return $result;
    }

    private function sigmoid(float $value): float
    {
        return 1.0 / (1.0 + exp(-$value));
    }

    private function clipProbability(float $probability): float
    {
        return min(0.999999, max(0.000001, $probability));
    }
}
