<?php

namespace App\Services\NFL;

use DomainException;

/**
 * Counterfactual spread-policy audit, not a present-day regeneration or model training.
 * Unchanged signals/settings remain frozen. Rounded metadata must reproduce the
 * original published spread before any counterfactual is graded.
 */
class NflFrozenSpreadReplay
{
    public const VARIANTS = ['safeguards_v1', 'rushing_direction_only', 'injury_precision_only', 'history_context_off_only', 'batch_v2'];

    public function replay(array $metadata, float $publishedMargin, ?array $preseasonRecovery = null, string $variant = 'safeguards_v1'): array
    {
        try {
            if (! in_array($variant, self::VARIANTS, true)) {
                throw new DomainException('unsupported_variant');
            }
            $original = $this->margin($metadata, 'original', null);
            if (round($original, 1) !== round($publishedMargin, 1)) {
                throw new DomainException('original_forecast_not_reproduced');
            }
            $revised = $this->margin($metadata, $variant, $preseasonRecovery);

            return ['status' => 'replayed', 'original_margin' => round($original, 1),
                'revised_margin' => round($revised, 1),
                'preseason_recovery' => $preseasonRecovery];
        } catch (DomainException $e) {
            return ['status' => 'excluded', 'reason' => $e->getMessage()];
        }
    }

    private function margin(array $m, string $variant, ?array $recovery): float
    {
        $revised = in_array($variant, ['safeguards_v1', 'batch_v2'], true);
        $spread = $this->number($m, ($revised ? 'legacy' : 'blended').'.spread');
        $preseason = $this->stage($m, 'preseason_signal');
        if (($preseason['applied'] ?? false) === true) {
            $spread = $this->clamp($this->blend($spread, $this->number($preseason, 'signal_spread'), $this->weight($preseason, 'weight')));
        } elseif ($revised && ($preseason['reason'] ?? null) === 'true_epa_already_applied') {
            if ($recovery === null) {
                throw new DomainException('missing_pregame_preseason_ratings');
            }
            // Explicit counterfactual policy: the current code's 25% fallback.
            $spread = $this->clamp($this->blend($spread, $this->number($recovery, 'signal_spread'), 0.25));
        }

        foreach (['rolling_efficiency', 'opponent_adjusted_efficiency', 'qb_form', 'line_matchup'] as $key) {
            $stage = $this->stage($m, $key);
            if (! $stage['applied']) {
                continue;
            }
            $weight = $this->weight($stage, $key === 'qb_form' ? 'effective_weight' : 'weight');
            if ($revised && in_array($key, ['rolling_efficiency', 'opponent_adjusted_efficiency'], true)) {
                if (isset($stage['reliability_method'])) {
                    throw new DomainException('already_reweighted_snapshot');
                }
                $games = min($this->number($stage, 'home.games'), $this->number($stage, 'away.games'));
                if ($games < 0) {
                    throw new DomainException('invalid_sample');
                }
                $weight *= $games / ($games + 8);
            }
            $signal = $this->number($stage, 'signal_spread');
            if ($key === 'line_matchup' && in_array($variant, ['rushing_direction_only', 'batch_v2'], true)) {
                $signal = $this->correctedRushingSignal($stage);
            }
            $spread = $this->clamp($this->blend($spread, $key === 'rolling_efficiency' ? $signal : $spread + $signal, $weight));
        }

        $context = $this->stage($m, 'contextual_factors');
        if (array_key_exists('spread_adjustment', $context)) {
            // Enabled context still clamps when its net adjustment is zero.
            $adjustment = $variant === 'history_context_off_only'
                ? $this->contextWithoutHistory($context)
                : $this->number($context, 'spread_adjustment');
            $spread = $this->clamp($spread + $adjustment);
        } elseif ($context['applied']) {
            throw new DomainException('missing_contextual_factors.spread_adjustment');
        }
        $injuries = $this->stage($m, 'depth_chart_injuries');
        if ($injuries['enabled']) {
            // This stage rounds even when applied=false (zero injury adjustment).
            $spread += $this->number($injuries, 'spread_adjustment');
            if (! in_array($variant, ['injury_precision_only', 'batch_v2'], true)) {
                $spread = round($spread, 1);
            }
        }
        $calibration = $this->stage($m, 'adaptive_point_calibration');
        if (($calibration['reason'] ?? null) === 'calibrated') {
            $spread = $this->clamp($spread + ($revised ? 0.0 : $this->number($calibration, 'spread_adjustment')));
        } elseif ($calibration['applied']) {
            throw new DomainException('unsupported_calibration');
        }
        $market = $this->stage($m, 'market_blend');
        if ($market['applied'] && isset($market['market_spread'])) {
            $spread = $this->clamp($this->blend($this->number($market, 'market_spread'), $spread, $this->weight($market, 'spread_model_weight')));
        }

        // Weather, total environment, grades, probability calibration and trust
        // adjustments do not alter the spread in these supported model versions.
        return $spread;
    }

    private function correctedRushingSignal(array $stage): float
    {
        $home = (array) ($stage['home'] ?? []);
        $away = (array) ($stage['away'] ?? []);
        $edges = (new NflRushingMatchup)->edges($home, $away);
        if ($edges === null) {
            throw new DomainException('missing_rushing_profile');
        }
        // Explicit legacy-policy assumptions, not weights fitted to these outcomes.
        // Require reconstruction of the old signal before using the candidate.
        $pressureDifference = ($this->number($away, 'def_sack_rate') + $this->number($home, 'off_sack_allowed_rate'))
            - ($this->number($home, 'def_sack_rate') + $this->number($away, 'off_sack_allowed_rate'));
        $legacy = max(-4, min(4, ($edges['legacy_home'] - $edges['legacy_away']) * 1.35 - $pressureDifference * 34));
        if (abs($legacy - $this->number($stage, 'signal_spread')) > 0.003) {
            throw new DomainException('legacy_line_signal_not_reproduced');
        }

        return max(-4, min(4, ($edges['home'] - $edges['away']) * 1.35 - $pressureDifference * 34));
    }

    private function contextWithoutHistory(array $stage): float
    {
        $retained = $this->number($stage, 'home_away_strength.spread_adjustment')
            + $this->number($stage, 'schedule_spot.spread_adjustment')
            + $this->number($stage, 'coaching_prior.spread_adjustment');
        $history = $this->number($stage, 'division_rivalry.spread_adjustment')
            + $this->number($stage, 'matchup_records.spread_adjustment')
            + $this->number($stage, 'same_week_records.spread_adjustment');
        if (abs(max(-2, min(2, $retained + $history)) - $this->number($stage, 'spread_adjustment')) > 0.003) {
            throw new DomainException('legacy_context_not_reproduced');
        }

        return max(-2, min(2, $retained));
    }

    private function stage(array $metadata, string $key): array
    {
        $stage = $metadata[$key] ?? null;
        if (! is_array($stage) || ! is_bool($stage['enabled'] ?? null) || ! is_bool($stage['applied'] ?? null)) {
            throw new DomainException('missing_stage_'.$key);
        }

        return $stage;
    }

    private function number(array $values, string $key): float
    {
        $value = data_get($values, $key);
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new DomainException('missing_'.$key);
        }

        return (float) $value;
    }

    private function weight(array $stage, string $key): float
    {
        $weight = $this->number($stage, $key);
        if ($weight < 0 || $weight > 1) {
            throw new DomainException('invalid_weight');
        }

        return $weight;
    }

    private function blend(float $a, float $b, float $weight): float
    {
        return $a * (1 - $weight) + $b * $weight;
    }

    private function clamp(float $spread): float
    {
        return max(-15.0, min(15.0, $spread));
    }
}
