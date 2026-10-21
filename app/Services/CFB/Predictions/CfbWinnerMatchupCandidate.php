<?php

namespace App\Services\CFB\Predictions;

/** Fixed exploratory logistic challenger. Scaling and coefficients are fitted on earlier dates only. */
class CfbWinnerMatchupCandidate
{
    public function features(array $row): ?array
    {
        if (! is_numeric($row['home_probability'] ?? null) || $row['home_probability'] <= 0 || $row['home_probability'] >= 1
            || ! isset($row['season']) || ($row['quality']['qualified'] ?? false) !== true
            || ($row['home_metric_season'] ?? null) !== ($row['season'] ?? null)
            || ($row['away_metric_season'] ?? null) !== ($row['season'] ?? null)) {
            return null;
        }
        $values = [];
        foreach (['offensive_success_rate', 'defensive_success_rate', 'offensive_true_epa_per_play',
            'defensive_true_epa_per_play', 'strength_of_schedule', 'turnover_differential'] as $field) {
            foreach (['home', 'away'] as $side) {
                $value = $row[$side.'_metrics'][$field] ?? null;
                if (! is_numeric($value) || ! is_finite((float) $value)) {
                    return null;
                }
                $values[$side][$field] = (float) $value;
            }
        }
        $h = $values['home'];
        $a = $values['away'];
        $p = $row['home_probability'];

        return [log($p / (1 - $p)),
            (($h['offensive_success_rate'] + $a['defensive_success_rate']) - ($a['offensive_success_rate'] + $h['defensive_success_rate'])) / 2,
            (($h['offensive_true_epa_per_play'] + $a['defensive_true_epa_per_play']) - ($a['offensive_true_epa_per_play'] + $h['defensive_true_epa_per_play'])) / 2,
            $h['strength_of_schedule'] - $a['strength_of_schedule'],
            $h['turnover_differential'] - $a['turnover_differential']];
    }

    public function fit(array $rows, bool $matchups): ?array
    {
        if (count($rows) < 100) {
            return null;
        }
        $x = array_map(fn ($r) => $matchups ? $this->features($r) : [log($r['home_probability'] / (1 - $r['home_probability']))], $rows);
        if (in_array(null, $x, true)) {
            throw new \InvalidArgumentException('Training must contain complete frozen features.');
        }
        $means = $scales = [];
        foreach (array_keys($x[0]) as $j) {
            $v = array_column($x, $j);
            $means[$j] = array_sum($v) / count($v);
            $scales[$j] = max(0.000001, sqrt(array_sum(array_map(fn ($z) => ($z - $means[$j]) ** 2, $v)) / count($v)));
        }
        $design = array_map(fn ($v) => [1.0, ...array_map(fn ($j) => ($v[$j] - $means[$j]) / $scales[$j], array_keys($v))], $x);
        $weights = array_fill(0, count($design[0]), 0.0);
        for ($step = 0; $step < 400; $step++) {
            $gradient = array_fill(0, count($weights), 0.0);
            foreach ($design as $i => $v) {
                $prediction = $this->sigmoid(array_sum(array_map(fn ($j) => $v[$j] * $weights[$j], array_keys($weights))));
                $error = $prediction - ($rows[$i]['home_score'] > $rows[$i]['away_score'] ? 1 : 0);
                foreach ($weights as $j => $weight) {
                    $gradient[$j] += $error * $v[$j] / count($rows);
                }
            }
            foreach ($weights as $j => $weight) {
                $weights[$j] -= 0.05 * ($gradient[$j] + ($j === 0 ? 0 : 0.05 * $weight));
            }
        }

        return ['matchups' => $matchups, 'means' => $means, 'scales' => $scales, 'weights' => $weights,
            'training_game_ids' => array_column($rows, 'game_id'), 'trained_through' => max(array_column($rows, 'kickoff')),
            'fixed_recipe' => '400_steps_lr_0.05_l2_0.05_no_holdout_tuning'];
    }

    public function predict(array $row, array $model): ?float
    {
        $v = $model['matchups'] ? $this->features($row) : [log($row['home_probability'] / (1 - $row['home_probability']))];
        if ($v === null) {
            return null;
        }
        $score = $model['weights'][0];
        foreach ($v as $j => $value) {
            $score += ($value - $model['means'][$j]) / $model['scales'][$j] * $model['weights'][$j + 1];
        }

        return $this->sigmoid($score);
    }

    private function sigmoid(float $value): float
    {
        return 1 / (1 + exp(-max(-35, min(35, $value))));
    }
}
