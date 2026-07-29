<?php

namespace App\Services\NBA;

use App\Services\ML\ModelArtifactRegistry;
use Illuminate\Support\Facades\File;

class WinProbabilityCalibrationInferenceService
{
    public function __construct(
        private readonly ModelArtifactRegistry $artifacts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function calibrate(string $sport, float $baselineProbability): array
    {
        $config = config("{$sport}.prediction.win_probability_calibration", []);
        $enabled = (bool) ($config['enabled'] ?? false);
        $shadowEnabled = (bool) ($config['shadow_enabled'] ?? false);
        $applyToLiveOutput = (bool) ($config['apply_to_live_output'] ?? false);
        $artifactPath = (string) ($config['artifact_path'] ?? '');
        $baselineProbability = $this->clipProbability($baselineProbability);
        $result = [
            'enabled' => $enabled,
            'shadow_enabled' => $shadowEnabled,
            'shadow_active' => false,
            'artifact_path' => $artifactPath,
            'artifact_id' => null,
            'training_run_id' => null,
            'config_hash' => null,
            'baseline_win_probability' => round($baselineProbability, 6),
            'calibrated_win_probability' => round($baselineProbability, 6),
            'active_win_probability' => round($baselineProbability, 6),
            'active_source' => 'baseline',
            'apply_to_live_output' => $applyToLiveOutput,
            'model_type' => null,
            'alpha' => null,
            'beta' => null,
            'reason' => ($enabled || $shadowEnabled) ? 'artifact_not_found' : 'feature_disabled',
        ];

        if ((! $enabled && ! $shadowEnabled) || $artifactPath === '') {
            return $result;
        }

        $registeredArtifact = $this->artifacts->forPath($artifactPath);
        try {
            $resolvedArtifactPath = $registeredArtifact
                ? $this->artifacts->materializeArtifact($registeredArtifact)
                : $this->absolutePath($artifactPath);
        } catch (\RuntimeException) {
            return $result;
        }

        if (! File::exists($resolvedArtifactPath)) {
            return $result;
        }

        $artifact = json_decode((string) File::get($resolvedArtifactPath), true);
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
        $result['artifact_id'] = $registeredArtifact?->id ?? ($artifact['artifact_id'] ?? null);
        $result['training_run_id'] = $registeredArtifact?->training_run_id ?? ($artifact['training_run_id'] ?? null);
        $result['config_hash'] = $registeredArtifact?->trainingRun?->config_hash ?? ($artifact['config_hash'] ?? null);
        $result['alpha'] = $alpha;
        $result['beta'] = $beta;
        $result['calibrated_win_probability'] = round($calibratedProbability, 6);
        $result['shadow_active'] = true;
        $result['reason'] = $enabled ? 'calibrated' : 'shadow_calibrated';

        if ($enabled && $applyToLiveOutput && $registeredArtifact?->status === 'promoted') {
            $result['active_win_probability'] = round($calibratedProbability, 6);
            $result['active_source'] = 'calibrated';
        } elseif ($enabled && $applyToLiveOutput) {
            $result['reason'] = 'promotion_required';
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

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
