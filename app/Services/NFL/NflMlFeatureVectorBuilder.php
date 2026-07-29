<?php

namespace App\Services\NFL;

class NflMlFeatureVectorBuilder
{
    /**
     * Analysis, calibration, shadow, and market-blend sections are excluded
     * because they depend on model outputs or downstream decisions.
     *
     * @var list<string>
     */
    private const SAFE_METADATA_SECTIONS = [
        'true_epa',
        'preseason_signal',
        'rolling_efficiency',
        'opponent_adjusted_efficiency',
        'total_environment',
        'qb_form',
        'line_matchup',
        'contextual_factors',
        'actual_weather',
        'depth_chart_injuries',
        'player_position_grades',
    ];

    /**
     * @param  array<string, mixed>  $baseFeatures
     * @param  array<string, mixed>  $modelMetadata
     * @param  array<string, mixed>  $marketContext
     * @return array<string, float|int>
     */
    public function build(array $baseFeatures, array $modelMetadata, array $marketContext = []): array
    {
        $features = [];

        $this->flattenNumeric($baseFeatures, '', $features);

        foreach (self::SAFE_METADATA_SECTIONS as $section) {
            $value = $modelMetadata[$section] ?? null;
            if (! is_array($value)) {
                continue;
            }

            $this->flattenNumeric($value, $section, $features);
        }

        $this->flattenNumeric($marketContext, 'market', $features);

        ksort($features);

        return $features;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $features
     */
    private function flattenNumeric(array $values, string $prefix, array &$features): void
    {
        if (array_is_list($values)) {
            return;
        }

        foreach ($values as $key => $value) {
            $normalizedKey = $this->normalizeKey((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            $path = $prefix === '' ? $normalizedKey : $prefix.'__'.$normalizedKey;
            if ($this->isIdentifier($path)) {
                continue;
            }

            if (is_array($value)) {
                $this->flattenNumeric($value, $path, $features);

                continue;
            }

            if (is_bool($value)) {
                $features[$path] = $value ? 1 : 0;

                continue;
            }

            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $features[$path] = is_int($value) ? $value : round((float) $value, 8);
            }
        }
    }

    private function normalizeKey(string $key): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_');
    }

    private function isIdentifier(string $path): bool
    {
        return preg_match('/(?:^|__)[a-z0-9_]*(?:_id|_uuid)$/', $path) === 1
            || preg_match('/(?:^|__)(?:id|uuid)$/', $path) === 1;
    }
}
