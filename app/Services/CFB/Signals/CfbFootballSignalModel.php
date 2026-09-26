<?php

namespace App\Services\CFB\Signals;

use Carbon\CarbonImmutable;

/** Frozen, evidence-fitted corrections; the calculator never queries mutable data. */
class CfbFootballSignalModel
{
    public const VERSION = 'cfb-football-signals-1';

    public function evaluate(array $inputs, array $configuration, ?array $baselineConfiguration = null, ?CarbonImmutable $capturedAt = null): array
    {
        $catalog = $configuration['catalog'] ?? CfbFootballSignalCatalog::all();
        $catalogHash = CfbFootballSignalEvidence::catalogHash($catalog);
        $rows = $families = [];
        $jointWeighting = ($configuration['weighting'] ?? null) === 'joint_ridge_v1';
        $evidence = $inputs['football_signal_evidence'] ?? [];
        $compatible = $baselineConfiguration !== null
            && ($evidence['baseline_hash'] ?? null) === CfbFootballSignalEvidence::baselineHash($baselineConfiguration)
            && ($evidence['version'] ?? null) === self::VERSION
            && ($evidence['feature_policy'] ?? 'observed_only') === ($configuration['feature_policy'] ?? 'observed_only');
        try {
            $asOf = CarbonImmutable::parse($evidence['as_of'] ?? 'invalid');
            $cutoff = CarbonImmutable::parse(data_get($inputs, 'event.starts_at', 'invalid'));
            $latest = isset($evidence['latest_source_observed_at']) ? CarbonImmutable::parse($evidence['latest_source_observed_at']) : null;
            $compatible = $compatible && $capturedAt !== null && $asOf->lte($capturedAt) && $capturedAt->lt($cutoff) && $asOf->lt($cutoff) && ($latest === null || $latest->lte($asOf));
        } catch (\Throwable) {
            $compatible = false;
        }
        foreach (['home', 'away'] as $side) {
            $features = CfbFootballSignalCatalog::features($inputs, $side, $configuration['feature_policy'] ?? 'observed_only');
            foreach ($catalog as $id => $definition) {
                $matched = CfbFootballSignalCatalog::evaluate($definition, $features);
                $fit = $evidence['signals'][$id] ?? [];
                $supported = $compatible && ($fit['status'] ?? null) === 'validated_residual'
                    && ($evidence['catalog_hash'] ?? null) === $catalogHash;
                if ($jointWeighting) {
                    $joint = $evidence['joint_models'][$definition['market']] ?? [];
                    $fit = ['coefficient' => $joint['coefficients'][$id] ?? null,
                        'sample_games' => $joint['feature_samples'][$id] ?? 0,
                        'validation_games' => $joint['validation_games'] ?? 0,
                        'validation_mae_improvement' => $joint['validation_mae_improvement'] ?? null];
                    $supported = $compatible && ($evidence['catalog_hash'] ?? null) === $catalogHash
                        && ($joint['status'] ?? null) === 'validated_joint_residual' && is_numeric($fit['coefficient']);
                    // Historical training uses a paired FPI baseline. Do not transfer its
                    // spread residuals to an unrelated Elo fallback baseline.
                    if ($definition['market'] === 'spread'
                        && (! is_numeric(data_get($inputs, 'home.metrics.fpi')) || ! is_numeric(data_get($inputs, 'away.metrics.fpi')))) {
                        $supported = false;
                    }
                }

                $row = ['id' => $id, 'label' => $definition['label'], 'family' => $definition['family'],
                    'side' => $side, 'market' => $definition['market'], 'matched' => $matched,
                    'input_support' => array_filter(array_combine($definition['inputs'], array_map(fn ($path) => str_starts_with($path, 'team.history.current_season.') ? data_get($features, 'team.history_support.current_season.'.substr($path, strlen('team.history.current_season.'))) : (str_starts_with($path, 'opponent.history.current_season.') ? data_get($features, 'opponent.history_support.current_season.'.substr($path, strlen('opponent.history.current_season.'))) : null), $definition['inputs']))),
                    'status' => $matched === null ? 'missing_inputs' : ($matched ? ($supported ? 'supported' : 'awaiting_evidence') : 'not_triggered'),
                    'sample_games' => $fit['sample_games'] ?? 0, 'validation_games' => $fit['validation_games'] ?? 0,
                    'validation_mae_improvement' => $fit['validation_mae_improvement'] ?? null,
                    'validation_scope' => $jointWeighting ? 'joint_market_model' : 'individual_rule',
                    'contribution_points' => 0.0];
                if ($matched && $supported && is_numeric($fit['coefficient'] ?? null) && is_finite((float) $fit['coefficient'])) {
                    $market = $definition['market'];
                    $sign = $jointWeighting && $market === 'total' ? 0.5 : ($market === 'spread' && $side === 'away' ? -1 : 1);
                    $row['raw_correction'] = $sign * (float) $fit['coefficient'];
                    $families[$market][$definition['family']][] = count($rows);
                }
                $rows[] = $row;
            }
        }
        $adjustments = ['spread' => 0.0, 'total' => 0.0];
        foreach ($families as $market => $groups) {
            if (! array_key_exists($market, $adjustments)) {
                continue;
            }
            // Legacy weights average families; joint ridge fits the additive contributions together.
            // More labels cannot manufacture a larger edge.
            foreach ($groups as $indices) {
                foreach ($indices as $index) {
                    $rows[$index]['contribution_points'] = $rows[$index]['raw_correction'] / ($jointWeighting ? 1 : count($indices) * count($groups));
                    $adjustments[$market] += $rows[$index]['contribution_points'];
                }
            }
            $cap = max(0, (float) ($configuration['maximum_'.$market.'_adjustment'] ?? ($market === 'spread' ? 2 : 3)));
            $scale = abs($adjustments[$market]) > $cap && abs($adjustments[$market]) > 0 ? $cap / abs($adjustments[$market]) : 1;
            $adjustments[$market] *= $scale;
            foreach ($groups as $indices) {
                foreach ($indices as $index) {
                    $rows[$index]['contribution_points'] = round($rows[$index]['contribution_points'] * $scale, 6);
                }
            }
        }

        return ['historical_training_status' => $evidence['historical_training_status'] ?? 'not_recorded', ...($jointWeighting ? ['weighting' => 'joint_ridge_v1', 'model_validation' => array_map(fn ($m) => array_diff_key($m, ['coefficients' => true, 'feature_samples' => true]), $evidence['joint_models'] ?? []), 'historical_training_games' => count(data_get($evidence, 'historical_training.source_game_ids', []))] : []), 'version' => self::VERSION, 'catalog_count' => count($catalog),
            'evaluated_team_conditions' => count($rows),
            'triggered' => count(array_filter($rows, fn ($row) => $row['matched'] === true)),
            'missing_inputs' => count(array_filter($rows, fn ($row) => $row['matched'] === null)),
            'applied' => count(array_filter($rows, fn ($row) => abs($row['contribution_points']) > .000001)),
            'spread_adjustment' => round($adjustments['spread'], 6), 'total_adjustment' => round($adjustments['total'], 6),
            'evidence_status' => $compatible ? ($evidence['status'] ?? 'no_frozen_training_evidence') : 'incompatible_or_unverified_evidence', 'signals' => $rows];
    }

    /** Chronological held-out residual check. A duplicate game cannot inflate sample size. */
    public function fit(array $observations): array
    {
        $observations = collect($observations)->filter(fn ($row) => isset($row['game_id'], $row['starts_at'])
            && is_numeric($row['residual'] ?? null) && is_finite((float) $row['residual']))->sortBy('starts_at')->unique('game_id')->values()->all();
        $n = count($observations);
        $split = (int) floor($n * .7);
        $train = array_slice($observations, 0, $split);
        $test = array_slice($observations, $split);
        $result = ['sample_games' => $n, 'training_games' => count($train), 'validation_games' => count($test),
            'status' => 'insufficient_evidence', 'coefficient' => null, 'validation_mae_improvement' => null];
        if (count($train) < 30 || count($test) < 15) {
            return $result;
        }
        // Do not split games played on the same day across training and validation.
        $boundary = substr($test[0]['starts_at'], 0, 10);
        $train = array_values(array_filter($train, fn ($row) => substr($row['starts_at'], 0, 10) < $boundary));
        $result['training_games'] = count($train);
        $result['discarded_boundary_games'] = $n - count($train) - count($test);
        $result['sample_games'] = count($train) + count($test);
        if (count($train) < 30) {
            return $result;
        }
        $coefficient = array_sum(array_column($train, 'residual')) / (count($train) + 30);
        $lifts = array_map(fn ($row) => abs($row['residual']) - abs($row['residual'] - $coefficient), $test);
        $improvement = array_sum($lifts) / count($test);
        $variance = array_sum(array_map(fn ($lift) => ($lift - $improvement) ** 2, $lifts)) / (count($test) - 1);
        $standardError = sqrt($variance / count($test));
        // Conservative multiple-comparison screen across 200 candidate rules.
        $supported = $improvement > 0 && $improvement > 3.5 * $standardError;

        return [...$result, 'coefficient' => round($coefficient, 6),
            'validation_mae_improvement' => round($improvement, 6),
            'validation_standard_error' => round($standardError, 6),
            'status' => $supported ? 'validated_residual' : 'no_validation_improvement'];
    }
}
