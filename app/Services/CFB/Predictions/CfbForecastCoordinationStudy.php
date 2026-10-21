<?php

namespace App\Services\CFB\Predictions;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Select on earlier dates only; validation and later results can never change the frozen policy. */
class CfbForecastCoordinationStudy
{
    public const VERSION = 'cfb-coordination-study-v3-winner-first';

    public function __construct(private readonly CfbForecastCoordinator $coordinator) {}

    public function select(array $training): array
    {
        $training = $this->verified($training);
        $complete = array_values(array_filter($training, fn ($r) => $this->complete($r)));
        if (count($complete) < 50) {
            throw new InvalidArgumentException('At least 50 identical complete training games are required.');
        }
        $policy = ['margin_alternative_weight' => 0.0, 'total_alternative_weight' => 0.0,
            'margin_market_weight' => 0.0, 'total_market_weight' => 0.0];
        $search = [];
        foreach (['margin', 'total'] as $target) {
            $best = null;
            foreach ([0.0, 0.05, 0.10] as $marketWeight) {
                foreach ([0.0, 0.25, 0.50, 0.75, 1.0] as $alternativeWeight) {
                    $trial = [...$policy, $target.'_alternative_weight' => $alternativeWeight, $target.'_market_weight' => $marketWeight];
                    $errors = [];
                    $winnerMistakes = 0;
                    foreach ($complete as $row) {
                        $forecast = $this->coordinator->forecast($row, $trial);
                        $errors[] = abs($forecast[$target === 'margin' ? 'home_margin' : 'total'] - $row['actual_'.$target]);
                        if ($target === 'margin' && $row['actual_margin'] != 0
                            && ($forecast['home_margin'] == 0 || (($forecast['home_margin'] > 0) !== ($row['actual_margin'] > 0)))) {
                            $winnerMistakes++;
                        }
                    }
                    $mae = array_sum($errors) / count($errors);
                    $search[$target][] = ['alternative_weight' => $alternativeWeight, 'market_weight' => $marketWeight,
                        'winner_mistakes' => $target === 'margin' ? $winnerMistakes : null, 'mae' => $mae];
                    $better = $best === null || ($target === 'margin' && $winnerMistakes < $best['winner_mistakes'])
                        || (($target !== 'margin' || $winnerMistakes === $best['winner_mistakes']) && $mae < $best['mae'] - 0.01);
                    if ($better) {
                        $best = ['mae' => $mae, 'winner_mistakes' => $winnerMistakes, 'policy' => $trial];
                    }
                }
            }
            $policy = $best['policy'];
        }
        $evidence = array_map(fn ($r) => array_intersect_key($r, array_flip([
            'game_id', 'prediction_id', 'kickoff', 'captured_at', 'formula_hash', 'primary_margin', 'primary_total',
            'result_margin', 'result_total', 'opponent_total', 'market_margin', 'market_total', 'actual_margin', 'actual_total',
        ])), $complete);

        return ['version' => self::VERSION, 'coordinator_version' => CfbForecastCoordinator::VERSION,
            'policy' => $policy, 'training_games' => count($complete), 'training_excluded_missing_alternatives' => count($training) - count($complete),
            'training_game_ids' => array_column($complete, 'game_id'), 'trained_through' => $complete[array_key_last($complete)]['kickoff'],
            'formula_hash' => $complete[0]['formula_hash'],
            'training_hash' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
            'policy_hash' => $this->coordinator->policyHash($policy), 'selection_search' => $search,
            'selection_objective' => ['margin' => 'fewest_outright_winner_mistakes_then_margin_mae', 'total' => 'total_mae'],
            'automatic_release_activation' => false, 'probability_status' => 'not_calibrated'];
    }

    public function evaluate(array $artifact, array $validation): array
    {
        if (($artifact['version'] ?? null) !== self::VERSION
            || ! hash_equals($artifact['policy_hash'] ?? '', $this->coordinator->policyHash($artifact['policy']))) {
            throw new InvalidArgumentException('Frozen coordination policy identity mismatch.');
        }
        $rows = $this->verified($validation);
        $lastTrainingDay = CarbonImmutable::parse($artifact['trained_through'])->setTimezone('America/Chicago')->toDateString();
        foreach ($rows as $row) {
            if ($row['formula_hash'] !== $artifact['formula_hash']) {
                throw new InvalidArgumentException('Validation must use the same independent primary formula.');
            }
            if (in_array($row['game_id'], $artifact['training_game_ids'], true)
                || CarbonImmutable::parse($row['kickoff'])->setTimezone('America/Chicago')->toDateString() <= $lastTrainingDay) {
                throw new InvalidArgumentException('Validation must contain different games on strictly later complete kickoff dates.');
            }
        }
        $errors = ['published_margin' => [], 'coordinated_margin' => [], 'published_total' => [], 'coordinated_total' => []];
        $biases = $errors;
        $winners = ['published' => ['win' => 0, 'loss' => 0, 'undecided' => 0], 'coordinated' => ['win' => 0, 'loss' => 0, 'undecided' => 0]];
        $evidence = [];
        foreach ($rows as $row) {
            $forecast = $this->coordinator->forecast($row, $artifact['policy']);
            foreach (['margin', 'total'] as $target) {
                $base = $row['published_'.$target];
                $candidate = $forecast[$target === 'margin' ? 'home_margin' : 'total'];
                foreach (['published' => $base, 'coordinated' => $candidate] as $name => $value) {
                    $biases[$name.'_'.$target][] = $value - $row['actual_'.$target];
                    $errors[$name.'_'.$target][] = abs($value - $row['actual_'.$target]);
                }
            }
            foreach (['published' => $row['published_margin'], 'coordinated' => $forecast['home_margin']] as $name => $margin) {
                $result = $margin == 0 || $row['actual_margin'] == 0 ? 'undecided' : (($margin > 0) === ($row['actual_margin'] > 0) ? 'win' : 'loss');
                $winners[$name][$result]++;
            }
            $evidence[] = ['game_id' => $row['game_id'], 'prediction_id' => $row['prediction_id'], 'kickoff' => $row['kickoff'],
                'published_margin' => $row['published_margin'], 'published_total' => $row['published_total'],
                'actual_margin' => $row['actual_margin'], 'actual_total' => $row['actual_total'], 'forecast' => $forecast];
        }
        $means = array_map(fn ($v) => $v ? array_sum($v) / count($v) : null, $errors);
        $bias = array_map(fn ($v) => $v ? array_sum($v) / count($v) : null, $biases);
        $n = count($rows);
        $secondary = [
            'margin_not_worse' => $n > 0 && $means['coordinated_margin'] <= $means['published_margin'],
            'total_not_worse' => $n > 0 && $means['coordinated_total'] <= $means['published_total'],
            'some_error_improvement' => $n > 0 && ($means['coordinated_margin'] < $means['published_margin'] || $means['coordinated_total'] < $means['published_total']),
            'margin_bias_not_worse' => $n > 0 && abs($bias['coordinated_margin']) <= abs($bias['published_margin']),
            'total_bias_not_worse' => $n > 0 && abs($bias['coordinated_total']) <= abs($bias['published_total'])];
        $gates = ['validation_sample' => $n >= 40,
            'winner_accuracy_not_worse' => $n > 0 && $winners['coordinated']['win'] >= $winners['published']['win'],
            'winner_gain_or_secondary_improvement_without_regression' => $n > 0 &&
                ($winners['coordinated']['win'] > $winners['published']['win'] || ! in_array(false, $secondary, true))];

        return ['version' => self::VERSION, 'policy_hash' => $artifact['policy_hash'], 'games' => $n,
            'complete_alternative_games' => count(array_filter($rows, fn ($r) => $this->complete($r))),
            'mean_absolute_errors' => $means, 'mean_predicted_minus_actual_bias' => $bias, 'winner_records' => $winners,
            'gates' => $gates, 'secondary_score_diagnostics' => $secondary, 'temporal_validation_passed' => ! in_array(false, $gates, true),
            'automatic_release_activation' => false, 'prospectively_validated' => false,
            'limited_forecast_release_eligible' => ! in_array(false, $gates, true), 'reason' => in_array(false, $gates, true)
                ? 'The candidate fails forecast accuracy checks; retain the published primary forecast.'
                : 'Retrospective chronological evaluation; prospective confirmation remains separate.', 'rows' => $evidence];
    }

    private function complete(array $row): bool
    {
        return isset($row['result_margin'], $row['result_total'], $row['opponent_total']);
    }

    private function verified(array $rows): array
    {
        $seen = $hashes = [];
        foreach ($rows as $row) {
            if (! isset($row['game_id'], $row['prediction_id'], $row['formula_hash'], $row['kickoff'], $row['captured_at'])
                || isset($seen[$row['game_id']]) || ($row['pregame_safe'] ?? false) !== true
                || CarbonImmutable::parse($row['captured_at'])->gte(CarbonImmutable::parse($row['kickoff']))) {
                throw new InvalidArgumentException('Unique, verified frozen pregame inputs are required.');
            }
            foreach (['primary_margin', 'primary_total', 'published_margin', 'published_total', 'actual_margin', 'actual_total'] as $key) {
                if (! is_numeric($row[$key] ?? null) || ! is_finite((float) $row[$key])) {
                    throw new InvalidArgumentException("Missing finite evaluation field: {$key}.");
                }
            }
            if ($row['actual_total'] < abs($row['actual_margin'])) {
                throw new InvalidArgumentException('Actual results must imply nonnegative team scores.');
            }
            $this->coordinator->forecast($row, ['margin_alternative_weight' => 0.0, 'total_alternative_weight' => 0.0, 'margin_market_weight' => 0.0, 'total_market_weight' => 0.0]);
            $seen[$row['game_id']] = true;
            $hashes[$row['formula_hash']] = true;
        }
        if (count($hashes) > 1) {
            throw new InvalidArgumentException('Incompatible primary formula configurations cannot be fitted together.');
        }
        usort($rows, fn ($a, $b) => CarbonImmutable::parse($a['kickoff'])->getTimestamp() <=> CarbonImmutable::parse($b['kickoff'])->getTimestamp() ?: $a['game_id'] <=> $b['game_id']);

        return $rows;
    }
}
