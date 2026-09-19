<?php

namespace App\Services\CFB\Predictions;

use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\ModelArtifact;
use App\Services\ML\ModelArtifactRegistry;
use Carbon\CarbonImmutable;

class CfbSpreadCoverProbabilityService
{
    public function assess(CanonicalPrediction $prediction, string $side, float $sideLine, ?float $americanPrice): array
    {
        $unavailable = static fn (string $reason): array => ['status' => 'unavailable', 'probability_status' => 'unavailable',
            'cover_probability' => null, 'push_probability' => null, 'loss_probability' => null,
            'expected_value_per_unit' => null, 'positive_expected_value' => false, 'artifact_id' => null, 'risk_flags' => [$reason]];
        if ($americanPrice === null || ! is_finite($americanPrice) || abs($americanPrice) < 100) {
            return $unavailable('spread_price_unavailable');
        }
        if (! in_array($side, ['home', 'away'], true) || ! is_finite($sideLine) || abs($sideLine * 2 - round($sideLine * 2)) > 0.000001) {
            return $unavailable('unsupported_spread_settlement');
        }
        $artifactId = config('cfb.predictions.spread_value.calibration_artifact_id');
        $artifact = $artifactId ? ModelArtifact::query()->find($artifactId) : null;
        if (! $artifact || $artifact->sport !== 'cfb' || $artifact->market_type !== 'spread'
            || $artifact->model_version !== CfbSpreadResidualCalibration::VERSION || $artifact->status !== 'promoted'
            || data_get($artifact->metrics, 'validation_passed') !== true
            || data_get($artifact->promotion_decision, 'prospective_shadow_passed') !== true) {
            return $unavailable('spread_calibration_unavailable');
        }
        try {
            $path = app(ModelArtifactRegistry::class)->materializeArtifact($artifact);
            $model = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $release = $prediction->calculationRun?->release;
            $releaseCompatible = ($model['release_configuration_hash'] ?? null) === $release?->configuration_hash
                && ($model['release_version'] ?? null) === $release?->semantic_version;
            if (! $releaseCompatible) {
                $trainedRelease = CalculationRelease::query()->where('sport', 'cfb')->where('phase', 'pregame')
                    ->where('semantic_version', $model['release_version'] ?? '')
                    ->where('configuration_hash', $model['release_configuration_hash'] ?? '')->first();
                $releaseCompatible = $trainedRelease && app(CfbFrozenMoneylineCalibrationDataset::class)->matchesRelease($prediction, $trainedRelease);
            }
            if (($model['model_version'] ?? null) !== CfbSpreadResidualCalibration::VERSION
                || ! $releaseCompatible
                || ($model['validation_passed'] ?? false) !== true
                || CarbonImmutable::parse($model['evaluated_through'])->gte($prediction->generated_at)
                || CarbonImmutable::parse($model['trained_at'])->gt($prediction->generated_at)
                || ($model['counts']['train'] ?? 0) < 500 || ($model['counts']['validation'] ?? 0) < 200 || ($model['counts']['test'] ?? 0) < 200) {
                return $unavailable('spread_calibration_incompatible');
            }
            $spread = $prediction->markets->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home');
            $margin = is_numeric($spread?->projected_line) ? -(float) $spread->projected_line : null;
            if ($margin === null || $margin < $model['model_margin_range'][0] || $margin > $model['model_margin_range'][1]) {
                return $unavailable('spread_calibration_out_of_domain');
            }
            $calibrator = app(CfbSpreadResidualCalibration::class);
            if (! in_array($calibrator->lineBucket($sideLine), $model['validated_line_buckets'] ?? [], true)) {
                return $unavailable('spread_calibration_line_bucket_unvalidated');
            }
            $result = $calibrator->price($calibrator->probabilities($model['residuals'], $margin, $side, $sideLine), $americanPrice);

            return [...$result, 'status' => 'calibrated', 'probability_status' => 'calibrated', 'artifact_id' => $artifact->id,
                'risk_flags' => $result['positive_expected_value'] ? [] : ['non_positive_expected_value']];
        } catch (\Throwable) {
            return $unavailable('spread_calibration_artifact_invalid');
        }
    }
}
