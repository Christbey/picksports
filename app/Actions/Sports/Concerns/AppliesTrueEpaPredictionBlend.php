<?php

namespace App\Actions\Sports\Concerns;

use Illuminate\Database\Eloquent\Model;

trait AppliesTrueEpaPredictionBlend
{
    /**
     * @return array{0:float,1:array<string,mixed>}
     */
    protected function applyTrueEpaSpreadBlendForSport(
        string $sport,
        float $legacySpread,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        float $defaultSpreadPointsPerEpa
    ): array {
        if (! config("{$sport}.prediction.true_epa.enabled", false)) {
            return [$legacySpread, [
                'true_epa_enabled' => false,
                'true_epa_applied' => false,
                'true_epa_reason' => 'feature_disabled',
            ]];
        }

        $homeNet = $homeMetrics?->net_true_epa_per_play;
        $awayNet = $awayMetrics?->net_true_epa_per_play;
        if ($homeNet === null || $awayNet === null) {
            return [$legacySpread, [
                'true_epa_enabled' => true,
                'true_epa_applied' => false,
                'true_epa_reason' => 'missing_net_true_epa',
            ]];
        }

        $weight = $this->clampValue((float) config("{$sport}.prediction.true_epa.blend_weight", 0.30), 0.0, 1.0);
        $epaDiff = (float) $homeNet - (float) $awayNet;
        $epaSpread = $epaDiff * (float) config("{$sport}.prediction.true_epa.spread_points_per_epa", $defaultSpreadPointsPerEpa);
        $blendedSpread = $this->blendValues($legacySpread, $epaSpread, $weight);

        return [$blendedSpread, [
            'true_epa_enabled' => true,
            'true_epa_applied' => true,
            'true_epa_reason' => 'applied',
            'true_epa_weight' => round($weight, 4),
            'true_epa_diff' => round($epaDiff, 6),
            'true_epa_spread_component' => round($epaSpread, 4),
        ]];
    }

    /**
     * @return array{0:float,1:array<string,mixed>}
     */
    protected function applyTrueEpaTotalBlendForSport(
        string $sport,
        float $legacyTotal,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        float $defaultScale,
        float $defaultMin,
        float $defaultMax
    ): array {
        if (! config("{$sport}.prediction.true_epa.enabled", false)) {
            return [$legacyTotal, []];
        }

        $homeOff = $homeMetrics?->offensive_true_epa_per_play;
        $homeDef = $homeMetrics?->defensive_true_epa_per_play;
        $awayOff = $awayMetrics?->offensive_true_epa_per_play;
        $awayDef = $awayMetrics?->defensive_true_epa_per_play;
        if ($homeOff === null || $homeDef === null || $awayOff === null || $awayDef === null) {
            return [$legacyTotal, ['true_epa_total_reason' => 'missing_off_def_true_epa']];
        }

        $weight = $this->clampValue((float) config("{$sport}.prediction.true_epa.blend_weight", 0.30), 0.0, 1.0);
        $scale = (float) config("{$sport}.prediction.true_epa.total_points_per_epa_component", $defaultScale);
        $homeExpectedDelta = ((float) $homeOff - (float) $awayDef) * $scale;
        $awayExpectedDelta = ((float) $awayOff - (float) $homeDef) * $scale;
        $epaTotal = $legacyTotal + $homeExpectedDelta + $awayExpectedDelta;

        $blendedTotal = $this->blendValues($legacyTotal, $epaTotal, $weight);
        $blendedTotal = $this->clampValue(
            $blendedTotal,
            (float) config("{$sport}.prediction.true_epa.min_predicted_total", $defaultMin),
            (float) config("{$sport}.prediction.true_epa.max_predicted_total", $defaultMax)
        );

        return [$blendedTotal, [
            'true_epa_total_component' => round($epaTotal, 4),
            'true_epa_total_reason' => 'applied',
        ]];
    }

    protected function blendValues(float $legacy, float $epaBased, float $weight): float
    {
        return ($legacy * (1 - $weight)) + ($epaBased * $weight);
    }

    protected function clampValue(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
