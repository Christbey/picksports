<?php

namespace App\Services\NFL;

class NflSpreadProbability
{
    /**
     * Empirical residual distribution. Rounded score margins retain integer-line pushes.
     * This is a shadow estimate, never a calibrated claim or automatic bet approval.
     *
     * @param  list<float>  $residuals  Actual home margin minus predicted home margin.
     */
    public function estimate(array $residuals, float $margin, float $homeLine, ?int $price = null): array
    {
        $side = (new NflSpreadBacktestEvaluator)->pickSide($margin, -$homeLine);
        $base = ['status' => 'unavailable', 'selection' => $side, 'home_line' => $homeLine,
            'edge_points' => round($margin + $homeLine, 3),
            'minimum_edge_points' => (float) config('nfl.predictions.analysis_layer.min_spread_edge', 2.0),
            'recommendation' => abs($margin + $homeLine) < (float) config('nfl.predictions.analysis_layer.min_spread_edge', 2.0)
                ? 'pass_small_edge' : 'directional_lean_unvalidated',
            'cover_probability' => null, 'push_probability' => null, 'loss_probability' => null,
            'conditional_cover_probability' => null, 'expected_profit_per_unit' => null,
            'sample_size' => count($residuals), 'calibrated' => false, 'bet_eligible' => false];
        if (! is_finite($margin) || ! is_finite($homeLine)
            || abs($homeLine * 2 - round($homeLine * 2)) > 0.00001) {
            return [...$base, 'edge_points' => null, 'recommendation' => 'unavailable', 'reason' => 'invalid_margin_or_line'];
        }
        if ($side === 'none' || count($residuals) < 200) {
            return [...$base, 'reason' => $side === 'none' ? 'no_directional_edge' : 'insufficient_prior_residuals'];
        }
        $wins = $pushes = 0;
        foreach ($residuals as $residual) {
            if (! is_numeric($residual) || ! is_finite((float) $residual)) {
                return [...$base, 'reason' => 'invalid_residual'];
            }
            $cover = (round($margin + $residual) + $homeLine) * ($side === 'home' ? 1 : -1);
            $wins += $cover > 0 ? 1 : 0;
            $pushes += abs($cover) < 0.00001 ? 1 : 0;
        }
        $win = $wins / count($residuals);
        $push = $pushes / count($residuals);
        $loss = max(0, 1 - $win - $push);
        $payout = $price !== null && abs($price) >= 100 ? ($price > 0 ? $price / 100 : 100 / -$price) : null;

        return [...$base, 'status' => 'shadow_uncalibrated', 'reason' => 'requires_held_out_validation',
            'cover_probability' => $win, 'push_probability' => $push, 'loss_probability' => $loss,
            'conditional_cover_probability' => $push < 1 ? $win / (1 - $push) : null,
            'expected_profit_per_unit' => $payout === null ? null : $win * $payout - $loss];
    }
}
