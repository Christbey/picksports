<?php

namespace App\Services\CFB\Predictions;

use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** A single forecast from frozen team models; market influence is bounded independently of fitted weights. */
class CfbForecastCoordinator
{
    public const VERSION = 'cfb-forecast-coordination-v2';

    public const MAX_MARKET_WEIGHT = 0.10;

    public function policyHash(array $policy): string
    {
        ksort($policy);

        return hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
    }

    public function apply(PredictionOutput $output, EventInputSnapshotData $snapshot, array $configuration): PredictionOutput
    {
        return $this->applyWithReference($output, $snapshot, $configuration, $configuration);
    }

    public function preview(PredictionOutput $output, EventInputSnapshotData $snapshot, array $originalConfiguration, array $counterfactualConfiguration): PredictionOutput
    {
        if (($originalConfiguration['forecast_coordination'] ?? null) !== ($counterfactualConfiguration['forecast_coordination'] ?? null)) {
            throw new InvalidArgumentException('A counterfactual preview must retain the original frozen coordination policy.');
        }

        return $this->applyWithReference($output, $snapshot, $counterfactualConfiguration, $originalConfiguration);
    }

    private function applyWithReference(PredictionOutput $output, EventInputSnapshotData $snapshot, array $configuration, array $reference): PredictionOutput
    {
        $artifact = $reference['forecast_coordination'] ?? [];
        if (! ($artifact['enabled'] ?? false)) {
            return $output;
        }
        if (! isset($snapshot->inputs['candidate_suite'])
            || data_get($output->metadata, 'feature_coverage.spread_baseline') !== 'fpi_points'
            || data_get($snapshot->inputs, 'scoring_challenger.promoted', false)) {
            return new PredictionOutput($output->markets, [...$output->metadata, 'forecast_coordination' => [
                'status' => 'primary_retained', 'reason' => 'The frozen input or primary model family is outside the evaluated coordination scope.',
            ]], $output->diagnostics, $output->generatedAt);
        }
        $core = array_intersect_key($reference, array_flip(['elo', 'spread', 'total', 'context', 'injuries']));
        if (($artifact['version'] ?? null) !== self::VERSION
            || ! hash_equals($artifact['policy_hash'] ?? '', $this->policyHash($artifact['policy'] ?? []))
            || ! hash_equals($artifact['formula_hash'] ?? '', app(CanonicalPayloadHasher::class)->hash($core))
            || ! ($artifact['historical_accuracy_checks_passed'] ?? false)
            || ($artifact['training_games'] ?? 0) < 50 || ($artifact['evaluation_games'] ?? 0) < 40
            || ! $snapshot->cutoffAt || $snapshot->capturedAt->gte($snapshot->cutoffAt)
            || CarbonImmutable::parse($artifact['available_at'] ?? '9999-01-01')->gt($snapshot->capturedAt)) {
            throw new InvalidArgumentException('The frozen coordination policy is incompatible or not available at forecast capture.');
        }
        $markets = collect($output->markets);
        $line = $markets->first(fn ($m) => $m->marketType === 'spread' && $m->selection === 'home')->projectedLine;
        $total = $markets->first(fn ($m) => $m->marketType === 'total')->projectedLine;
        $signals = $output->diagnostics['football_signals'] ?? [];
        $clean = $this->independentSignals($signals, $configuration['football_signals'] ?? []);
        $primaryMargin = round(($signals['base_home_margin'] ?? -$line) + $clean['spread'], 1);
        $primaryTotal = round(($signals['base_total'] ?? $total) + $clean['total'], 1);
        $evidence = data_get($snapshot->inputs, 'candidate_suite.evidence', []);
        $market = data_get($snapshot->inputs, 'candidate_suite.market', []);
        $spreadQuote = $this->safeQuote($market['spread'] ?? null, $snapshot);
        $totalQuote = $this->safeQuote($market['total'] ?? null, $snapshot);
        $forecast = $this->forecast([
            'primary_margin' => $primaryMargin, 'primary_total' => $primaryTotal,
            'result_margin' => ($evidence['status'] ?? null) === 'available' ? data_get($evidence, 'result_rating.home_margin') : null,
            'result_total' => ($evidence['status'] ?? null) === 'available' ? data_get($evidence, 'result_rating.total') : null,
            'opponent_total' => ($evidence['status'] ?? null) === 'available' ? data_get($evidence, 'opponent_adjusted_total.total') : null,
            'market_margin' => $spreadQuote === null ? null : -(float) $spreadQuote['line'],
            'market_total' => $totalQuote === null ? null : (float) $totalQuote['line'],
        ], $artifact['policy']);
        $scale = (float) $configuration['spread']['probability_coefficient'];
        if ($scale <= 0 || ! is_finite($scale)) {
            throw new InvalidArgumentException('The retained probability scale must be positive.');
        }
        $p = round(1 / (1 + exp(-$forecast['home_margin'] / $scale)), 6);
        $finalMarkets = array_map(fn ($m) => match ($m->marketType) {
            'spread' => new PredictionMarketOutput('spread', 'home', projectedLine: $forecast['home_line']),
            'total' => new PredictionMarketOutput('total', $m->selection, projectedLine: $forecast['total']),
            'moneyline' => new PredictionMarketOutput('moneyline', $m->selection, probability: $m->selection === 'home' ? $p : round(1 - $p, 6)),
            default => $m,
        }, $output->markets);
        $provenance = [...$forecast, 'status' => $configuration === $reference ? 'historically_evaluated_limited_sample' : 'counterfactual_preview',
            'policy_hash' => $artifact['policy_hash'], 'removed_market_rule_ids' => $clean['removed_rule_ids'],
            'quote_ids' => ['spread' => $spreadQuote['quote_id'] ?? null, 'total' => $totalQuote['quote_id'] ?? null],
            'probability_status' => 'uncalibrated_model_estimate', 'prospectively_validated' => false,
            'retained_indirect_effects' => ['External FPI assumptions and pre-snapshot Elo history are retained.'],
            'original_primary' => ['home_margin' => -$line, 'total' => $total]];

        return new PredictionOutput($finalMarkets, [...$output->metadata, 'home_margin' => $forecast['home_margin'],
            'forecast_coordination' => $provenance], [...$output->diagnostics, 'forecast_coordination' => $provenance,
                'projected_total' => $forecast['total']], $output->generatedAt);
    }

    private function safeQuote(?array $quote, EventInputSnapshotData $snapshot): ?array
    {
        if (! $quote || ! isset($quote['line'], $quote['quote_id'], $quote['opposite_quote'])) {
            return null;
        }
        try {
            foreach ([$quote, $quote['opposite_quote']] as $side) {
                foreach (['captured_at', 'stored_at'] as $key) {
                    if (! isset($side[$key])) {
                        return null;
                    }
                    $at = CarbonImmutable::parse($side[$key]);
                    if ($at->gt($snapshot->capturedAt) || $at->gte($snapshot->cutoffAt)
                        || ($key === 'captured_at' && $at->lt($snapshot->capturedAt->subHours(36)))) {
                        return null;
                    }
                }
                if (! empty($side['provider_observed_at'])) {
                    $at = CarbonImmutable::parse($side['provider_observed_at']);
                    if ($at->gt($snapshot->capturedAt) || $at->lt($snapshot->capturedAt->subHours(36))) {
                        return null;
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return $quote;
    }

    public function marketDependent(array $definition): bool
    {
        foreach ($definition['inputs'] ?? [] as $field) {
            if (preg_match('/(^context\.(spread|home_spread|total|market)|ats|cover_rate|over_rate|under_rate|closing_line|market_line)/i', (string) $field)) {
                return true;
            }
        }

        return false;
    }

    /** Reaggregate the frozen signal coefficients; removing a rule must not disable unrelated coefficients. */
    public function independentSignals(array $signals, array $configuration): array
    {
        $groups = $removed = [];
        foreach ($signals['signals'] ?? [] as $row) {
            if (! array_key_exists('raw_correction', $row)) {
                continue;
            }
            $definition = $configuration['catalog'][$row['id']] ?? null;
            if (! is_array($definition)) {
                throw new InvalidArgumentException('A contributing signal requires its frozen rule definition.');
            }
            if ($this->marketDependent($definition)) {
                $removed[$row['id']] = true;

                continue;
            }
            $value = $row['raw_correction'];
            if (! is_numeric($value) || ! is_finite((float) $value) || ! in_array($row['market'], ['spread', 'total'], true)) {
                throw new InvalidArgumentException('Signal corrections must be finite points in a supported market.');
            }
            $groups[$row['market']][$row['family']][] = (float) $value;
        }
        $adjustments = ['spread' => 0.0, 'total' => 0.0];
        foreach ($groups as $market => $families) {
            foreach ($families as $values) {
                $divisor = ($configuration['weighting'] ?? null) === 'joint_ridge_v1' ? 1 : count($families) * count($values);
                $adjustments[$market] += array_sum($values) / $divisor;
            }
            $cap = max(0, (float) ($configuration['maximum_'.$market.'_adjustment'] ?? ($market === 'spread' ? 2 : 3)));
            $adjustments[$market] = round(max(-$cap, min($cap, $adjustments[$market])), 6);
        }

        return [...$adjustments, 'removed_rule_ids' => array_keys($removed)];
    }

    /** @param array<string, float> $policy */
    public function forecast(array $inputs, array $policy): array
    {
        foreach (['margin_alternative_weight', 'total_alternative_weight', 'margin_market_weight', 'total_market_weight'] as $key) {
            $weight = $policy[$key] ?? null;
            $maximum = str_contains($key, 'market') ? self::MAX_MARKET_WEIGHT : 1.0;
            if (! is_numeric($weight) || ! is_finite((float) $weight) || $weight < 0 || $weight > $maximum) {
                throw new InvalidArgumentException("Invalid bounded forecast weight: {$key}.");
            }
        }
        $primaryMargin = $this->required($inputs, 'primary_margin');
        $primaryTotal = $this->required($inputs, 'primary_total');
        if ($primaryTotal < abs($primaryMargin)) {
            throw new InvalidArgumentException('The primary forecast must imply nonnegative team scores.');
        }
        $alternativeMargin = $this->optional($inputs, 'result_margin');
        $resultTotal = $this->optional($inputs, 'result_total');
        $opponentTotal = $this->optional($inputs, 'opponent_total');
        $alternativeTotal = $resultTotal !== null && $opponentTotal !== null ? ($resultTotal + $opponentTotal) / 2 : null;
        $marginWeight = $alternativeMargin !== null ? (float) $policy['margin_alternative_weight'] : 0.0;
        $totalWeight = $alternativeTotal !== null ? (float) $policy['total_alternative_weight'] : 0.0;
        $independentMargin = round($primaryMargin * (1 - $marginWeight) + ($alternativeMargin ?? 0) * $marginWeight, 3);
        $unconstrainedTotal = $primaryTotal * (1 - $totalWeight) + ($alternativeTotal ?? 0) * $totalWeight;
        $independentTotal = round(max(abs($independentMargin), $unconstrainedTotal), 3);
        $marketMargin = $this->optional($inputs, 'market_margin');
        $marketTotal = $this->optional($inputs, 'market_total');
        $marginMarketWeight = $marketMargin !== null ? (float) $policy['margin_market_weight'] : 0.0;
        $totalMarketWeight = $marketTotal !== null ? (float) $policy['total_market_weight'] : 0.0;
        $marginShift = max(-1, min(1, (($marketMargin ?? $independentMargin) - $independentMargin) * $marginMarketWeight));
        $totalShift = max(-2, min(2, (($marketTotal ?? $independentTotal) - $independentTotal) * $totalMarketWeight));
        $candidateMargin = $independentMargin + $marginShift;
        $candidateMargin = $independentMargin > 0 ? max(0.001, $candidateMargin)
            : ($independentMargin < 0 ? min(-0.001, $candidateMargin) : 0.0);
        $margin = round($candidateMargin, 3);
        $total = round(max(abs($margin), $independentTotal + $totalShift), 3);

        return ['version' => self::VERSION, 'home_margin' => $margin, 'home_line' => -$margin, 'total' => $total,
            'home_points' => ($total + $margin) / 2, 'away_points' => ($total - $margin) / 2,
            'independent_home_margin' => round($independentMargin, 3), 'independent_total' => round($independentTotal, 3),
            'effective_weights' => ['margin_alternative' => $marginWeight, 'total_alternative' => $totalWeight,
                'margin_market' => $marginMarketWeight, 'total_market' => $totalMarketWeight],
            'market_effect_points' => ['margin' => round($margin - $independentMargin, 3), 'total' => round($total - $independentTotal, 3)],
            'alternative_margin_disagreement' => $alternativeMargin === null ? null : abs($alternativeMargin - $primaryMargin),
            'winner_disagreement' => $alternativeMargin !== null && $primaryMargin * $alternativeMargin < 0,
            'fallbacks' => array_values(array_filter([$alternativeMargin === null ? 'missing_result_margin' : null,
                $alternativeTotal === null ? 'missing_paired_alternative_totals' : null,
                $marketMargin === null ? 'missing_frozen_market_margin' : null, $marketTotal === null ? 'missing_frozen_market_total' : null])),
            'probability_status' => 'not_estimated_by_coordinator', 'physical_total_adjustment' => round($independentTotal - $unconstrainedTotal, 3)];
    }

    private function required(array $values, string $key): float
    {
        return $this->optional($values, $key) ?? throw new InvalidArgumentException("Missing finite forecast: {$key}.");
    }

    private function optional(array $values, string $key): ?float
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException("Forecast inputs must be finite: {$key}.");
        }
        if (str_ends_with($key, 'total') && $value < 0) {
            throw new InvalidArgumentException('Scoring totals cannot be negative.');
        }

        return (float) $value;
    }
}
