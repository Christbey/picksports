<?php

namespace App\Services\CFB\Predictions;

use App\Services\ML\WinProbabilityCalibrationTrainer;
use Carbon\CarbonImmutable;

class CfbMoneylineTemporalCalibration
{
    public const VERSION = 'cfb-frozen-moneyline-platt-v1';

    public function train(array $rows): array
    {
        $days = $seen = [];
        foreach ($rows as $row) {
            if (($row['pregame_safe'] ?? false) !== true || ! isset($row['game_id'])
                || isset($seen[$row['game_id']]) || ! is_numeric($row['feature_model_win_probability'] ?? null)
                || ! is_finite((float) $row['feature_model_win_probability'])
                || $row['feature_model_win_probability'] <= 0 || $row['feature_model_win_probability'] >= 1
                || ! in_array($row['target_home_win'] ?? null, [0, 1], true)) {
                return ['status' => 'insufficient_evidence', 'reason' => 'invalid_or_duplicate_game_evidence'];
            }
            try {
                $day = CarbonImmutable::parse($row['kickoff'])->utc()->toDateString();
            } catch (\Throwable) {
                return ['status' => 'insufficient_evidence', 'reason' => 'invalid_kickoff'];
            }
            $seen[$row['game_id']] = true;
            $days[$day][] = $row;
        }
        ksort($days);
        $test = $validation = [];
        while ($days !== [] && count($test) < 50) {
            $test = [...array_pop($days), ...$test];
        }
        while ($days !== [] && count($validation) < 50) {
            $validation = [...array_pop($days), ...$validation];
        }
        $train = $days === [] ? [] : array_merge(...array_values($days));
        $counts = ['train' => count($train), 'validation' => count($validation), 'test' => count($test)];
        if ($counts['train'] < 100 || $counts['validation'] < 50 || $counts['test'] < 50) {
            return ['status' => 'insufficient_evidence', 'reason' => 'minimum_unique_games_100_50_50', 'counts' => $counts];
        }
        $trainer = app(WinProbabilityCalibrationTrainer::class);
        $model = $trainer->train($train);
        $reports = [];
        foreach (['validation' => $validation, 'test' => $test] as $split => $examples) {
            $metrics = $trainer->evaluate($examples, $model);
            $bins = $improvements = [];
            foreach ($examples as $row) {
                $p = $trainer->predict($row['feature_model_win_probability'], $model);
                $bins[min(9, (int) floor($p * 10))][] = [$p, $row['target_home_win']];
                $improvements[] = ($row['feature_model_win_probability'] - $row['target_home_win']) ** 2
                    - ($p - $row['target_home_win']) ** 2;
            }
            $ece = 0;
            foreach ($bins as $bin) {
                $ece += abs(array_sum(array_column($bin, 0)) - array_sum(array_column($bin, 1))) / count($examples);
            }
            $mean = array_sum($improvements) / count($improvements);
            $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $improvements)) / (count($improvements) - 1);
            $se = sqrt($variance / count($improvements));
            $reports[$split] = [...$metrics, 'ece' => $ece, 'brier_improvement' => $mean,
                'brier_improvement_standard_error' => $se, 'brier_improvement_lower_95' => $mean - 1.96 * $se];
        }
        $passed = $model['alpha'] > 0 && collect($reports)->every(fn ($r) => $r['brier_delta'] < 0
            && $r['log_loss_delta'] < 0 && $r['ece'] <= 0.05 && $r['brier_improvement_lower_95'] > 0);

        return ['status' => 'challenger', 'model_version' => self::VERSION, ...$model,
            'counts' => $counts, 'reports' => $reports, 'validation_passed' => $passed,
            'split_policy' => 'unique_games_complete_utc_kickoff_dates_temporal_holdouts',
            'trained_through' => max(array_column($train, 'kickoff')), 'evaluated_through' => max(array_column($test, 'kickoff')),
            'promotion_policy' => 'offline_and_prospective_shadow_required_no_automatic_promotion'];
    }
}
