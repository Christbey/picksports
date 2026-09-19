<?php

namespace App\Services\CFB\Signals;

/** Fixed-penalty ridge residual model, selected once on a chronological held-out block. */
class CfbFootballSignalJointModel
{
    public function fit(array $rows, string $market, float $cap): array
    {
        $rows = collect($rows)->filter(fn ($r) => isset($r['game_id'], $r['starts_at']) && is_numeric($r['residuals'][$market] ?? null))
            ->unique('game_id')->sortBy('starts_at')->values()->all();
        $split = (int) floor(count($rows) * .7);
        $test = array_slice($rows, $split);
        $boundary = substr($test[0]['starts_at'] ?? '', 0, 10);
        $train = array_values(array_filter(array_slice($rows, 0, $split), fn ($r) => substr($r['starts_at'], 0, 10) < $boundary));
        $result = ['method' => 'ridge_joint_residual_v1', 'penalty' => 100.0, 'training_games' => count($train),
            'validation_games' => count($test), 'status' => 'insufficient_evidence', 'coefficients' => [], 'feature_samples' => []];
        if (count($train) < 100 || count($test) < 50) {
            return $result;
        }
        $counts = [];
        foreach ($train as $row) {
            foreach ($row['features'][$market] ?? [] as $id => $value) {
                if (is_numeric($value) && abs($value) > 0) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        }
        // Rare features stay unweighted; joint validation replaces 200 standalone hypothesis tests.
        $ids = array_keys(array_filter($counts, fn ($n) => $n >= 20));
        sort($ids);
        $index = array_flip($ids);
        $d = count($ids);
        if ($d === 0) {
            return $result;
        }
        $a = array_fill(0, $d, array_fill(0, $d, 0.0));
        $b = array_fill(0, $d, 0.0);
        foreach ($train as $row) {
            $x = array_intersect_key($row['features'][$market] ?? [], $index);
            foreach ($x as $left => $value) {
                $i = $index[$left];
                $b[$i] += $value * $row['residuals'][$market];
                foreach ($x as $right => $other) {
                    $a[$i][$index[$right]] += $value * $other;
                }
            }
        }
        for ($i = 0; $i < $d; $i++) {
            $a[$i][$i] += 100.0;
        }
        // Positive ridge diagonal makes the normal equations positive definite.
        $l = array_fill(0, $d, array_fill(0, $d, 0.0));
        for ($i = 0; $i < $d; $i++) {
            for ($j = 0; $j <= $i; $j++) {
                $sum = $a[$i][$j];
                for ($k = 0; $k < $j; $k++) {
                    $sum -= $l[$i][$k] * $l[$j][$k];
                }
                $l[$i][$j] = $i === $j ? sqrt(max($sum, 1e-12)) : $sum / $l[$j][$j];
            }
        }
        $z = $coef = array_fill(0, $d, 0.0);
        for ($i = 0; $i < $d; $i++) {
            $sum = $b[$i];
            for ($k = 0; $k < $i; $k++) {
                $sum -= $l[$i][$k] * $z[$k];
            }
            $z[$i] = $sum / $l[$i][$i];
        }
        for ($i = $d - 1; $i >= 0; $i--) {
            $sum = $z[$i];
            for ($k = $i + 1; $k < $d; $k++) {
                $sum -= $l[$k][$i] * $coef[$k];
            }
            $coef[$i] = $sum / $l[$i][$i];
        }
        $coefficients = array_combine($ids, array_map(fn ($v) => round($v, 6), $coef));
        $lifts = $baseErrors = $adjustedErrors = [];
        foreach ($test as $row) {
            $correction = 0.0;
            foreach ($row['features'][$market] ?? [] as $id => $value) {
                $correction += $value * ($coefficients[$id] ?? 0.0);
            }
            $correction = round(max(-$cap, min($cap, $correction)), 1);
            $error = abs($row['residuals'][$market]);
            $adjusted = abs($row['residuals'][$market] - $correction);
            $baseErrors[] = $error;
            $adjustedErrors[] = $adjusted;
            $lifts[] = $error - $adjusted;
        }
        $n = count($test);
        $lift = array_sum($lifts) / $n;
        $se = sqrt(array_sum(array_map(fn ($v) => ($v - $lift) ** 2, $lifts)) / ($n - 1) / $n);

        return [...$result, 'coefficients' => $coefficients, 'feature_samples' => $counts,
            'status' => $lift > 0 && $lift > 1.96 * $se ? 'validated_joint_residual' : 'no_validation_improvement',
            'validation_baseline_mae' => round(array_sum($baseErrors) / $n, 6), 'validation_adjusted_mae' => round(array_sum($adjustedErrors) / $n, 6),
            'validation_mae_improvement' => round($lift, 6), 'validation_standard_error' => round($se, 6),
            'validation_scope' => 'whole_capped_model_not_individual_rule_significance'];
    }

    public static function features(array $inputs, array $configuration): array
    {
        $features = ['spread' => [], 'total' => []];
        foreach (['home', 'away'] as $side) {
            $team = CfbFootballSignalCatalog::features($inputs, $side, $configuration['feature_policy'] ?? 'observed_only');
            foreach ($configuration['catalog'] as $id => $rule) {
                if (CfbFootballSignalCatalog::evaluate($rule, $team) === true) {
                    $market = $rule['market'];
                    // Totals average two team orientations; spreads use home minus away.
                    $features[$market][$id] = ($features[$market][$id] ?? 0.0) + ($market === 'total' ? 0.5 : ($side === 'home' ? 1 : -1));
                }
            }
        }

        return $features;
    }
}
