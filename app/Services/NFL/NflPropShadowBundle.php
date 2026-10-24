<?php

namespace App\Services\NFL;

use Carbon\CarbonImmutable;
use RuntimeException;

/** Trusted, hash-pinned numeric JSON; no executable model files are loaded. */
class NflPropShadowBundle
{
    public function load(): array
    {
        $path = (string) config('nfl.props.shadow.bundle_path');
        $expected = (string) config('nfl.props.shadow.bundle_sha256');
        if (! preg_match('/^[a-f0-9]{64}$/', $expected) || ! is_file($path)) {
            throw new RuntimeException('NFL prop shadow requires a frozen bundle and its SHA-256.');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || ! hash_equals($expected, hash('sha256', $bytes))) {
            throw new RuntimeException('NFL prop shadow bundle hash mismatch.');
        }
        $bundle = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (($bundle['format'] ?? null) !== 'nfl-prop-shadow-bundle-v1'
            || ($bundle['feature_version'] ?? null) !== NflPropShadowFeatures::VERSION
            || ($bundle['production_promotion'] ?? null) !== false
            || empty($bundle['features']) || empty($bundle['markets'])
            || CarbonImmutable::parse($bundle['frozen_at'])->isFuture()) {
            throw new RuntimeException('Invalid frozen NFL prop shadow bundle.');
        }
        foreach ($bundle['markets'] as $market) {
            if (CarbonImmutable::parse($market['training_labels_available_at'])->gt(CarbonImmutable::parse($bundle['frozen_at']))) {
                throw new RuntimeException('NFL prop shadow bundle contains future training outcomes.');
            }
        }

        return ['sha256' => $expected, 'data' => $bundle];
    }

    public function predict(array $bundle, string $market, array $features): array
    {
        $model = $bundle['markets'][$market] ?? null;
        $names = $bundle['features'];
        if (! is_array($model) || array_diff($names, array_keys($features)) || array_diff(array_keys($features), $names)) {
            throw new RuntimeException('NFL prop shadow features do not match the frozen model.');
        }
        foreach ($features as $value) {
            if ($value !== null && (! is_numeric($value) || ! is_finite((float) $value))) {
                throw new RuntimeException('NFL prop shadow features must be finite or null.');
            }
        }
        $ridge = $model['ridge'];
        $linear = (float) $ridge['intercept'];
        foreach ($names as $index => $name) {
            $value = $features[$name] ?? $ridge['imputation'][$index];
            $linear += ($value - $ridge['mean'][$index]) / $ridge['scale'][$index] * $ridge['coefficients'][$index];
        }
        $boosted = (float) $model['xgboost']['base_score'];
        foreach ($model['xgboost']['trees'] as $node) {
            $depth = 0;
            while (! array_key_exists('leaf', $node)) {
                if (++$depth > 8) {
                    throw new RuntimeException('NFL prop shadow tree exceeds its supported depth.');
                }
                $value = $features[$node['split']];
                $branch = $value === null ? $node['missing']
                    : ($this->float32((float) $value) < $this->float32((float) $node['split_condition']) ? $node['yes'] : $node['no']);
                $node = collect($node['children'])->firstWhere('nodeid', $branch);
                if (! is_array($node)) {
                    throw new RuntimeException('Invalid NFL prop shadow tree branch.');
                }
            }
            $boosted += (float) $node['leaf'];
        }
        if (! is_finite($linear) || ! is_finite($boosted)) {
            throw new RuntimeException('NFL prop shadow produced a non-finite forecast.');
        }

        return ['ridge' => $model['nonnegative'] ? max(0, $linear) : $linear,
            'xgboost' => $model['nonnegative'] ? max(0, $boosted) : $boosted];
    }

    private function float32(float $value): float
    {
        return unpack('g', pack('g', $value))[1];
    }
}
