<?php

namespace App\Services\CFB\Predictions;

/** Empirical integer-margin distribution: pushes are a separate outcome, never half a loss. */
class CfbSpreadResidualCalibration
{
    public const VERSION = 'cfb-spread-residual-v1';

    public function probabilities(array $residuals, float $homeMargin, string $side, float $line): array
    {
        if ($residuals === [] || ! in_array($side, ['home', 'away'], true)) {
            throw new \InvalidArgumentException('A fitted distribution and valid side are required.');
        }
        $win = $push = 0;
        foreach ($residuals as $residual) {
            $margin = round($homeMargin + (float) $residual);
            $settlement = ($side === 'home' ? $margin : -$margin) + $line;
            $win += $settlement > 0 ? 1 : 0;
            $push += abs($settlement) < 0.000001 ? 1 : 0;
        }
        $n = count($residuals);

        return ['cover_probability' => $win / (float) $n, 'push_probability' => $push / (float) $n,
            'loss_probability' => ($n - $win - $push) / (float) $n];
    }

    public function price(array $probabilities, float $americanPrice): array
    {
        if (! is_finite($americanPrice) || abs($americanPrice) < 100) {
            throw new \InvalidArgumentException('A valid executable American price is required.');
        }
        $profit = $americanPrice > 0 ? $americanPrice / 100 : 100 / abs($americanPrice);
        $ev = $probabilities['cover_probability'] * $profit - $probabilities['loss_probability'];

        return [...$probabilities, 'american_price' => $americanPrice, 'net_win_profit_per_unit' => $profit,
            'expected_value_per_unit' => $ev, 'positive_expected_value' => $ev > 0,
            'break_even_non_push_probability' => 1 / (1 + $profit)];
    }

    public function train(array $rows): array
    {
        if (collect($rows)->contains(fn ($r) => ($r['pregame_safe'] ?? false) !== true)) {
            return ['status' => 'insufficient_evidence', 'reason' => 'unverified_pregame_rows'];
        }
        $seen = [];
        foreach ($rows as $row) {
            if (isset($row['game_id']) && isset($seen[$row['game_id']])) {
                return ['status' => 'insufficient_evidence', 'reason' => 'duplicate_game_evidence'];
            }
            if (isset($row['game_id'])) {
                $seen[$row['game_id']] = true;
            }
            foreach (['model_margin', 'actual_margin', 'home_line'] as $field) {
                if (! is_numeric($row[$field] ?? null) || ! is_finite((float) $row[$field])) {
                    return ['status' => 'insufficient_evidence', 'reason' => 'invalid_settlement_evidence'];
                }
            }
            if (abs($row['actual_margin'] - round($row['actual_margin'])) > 0.000001
                || abs($row['home_line'] * 2 - round($row['home_line'] * 2)) > 0.000001) {
                return ['status' => 'insufficient_evidence', 'reason' => 'invalid_settlement_evidence'];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['kickoff'], $b['kickoff']));
        // Keep complete kickoff dates together; a same-day game cannot train another game that day.
        $days = [];
        foreach ($rows as $row) {
            $days[substr($row['kickoff'], 0, 10)][] = $row;
        }
        ksort($days);
        $test = $validation = [];
        while ($days !== [] && count($test) < 200) {
            $test = [...array_pop($days), ...$test];
        }
        while ($days !== [] && count($validation) < 200) {
            $validation = [...array_pop($days), ...$validation];
        }
        $train = $days === [] ? [] : array_merge(...array_values($days));
        $counts = ['train' => count($train), 'validation' => count($validation), 'test' => count($test)];
        if ($counts['train'] < 500 || $counts['validation'] < 200 || $counts['test'] < 200) {
            return ['status' => 'insufficient_evidence', 'reason' => 'minimum_samples_500_200_200', 'counts' => $counts];
        }
        if (max(array_column($train, 'kickoff')) >= min(array_column($validation, 'kickoff'))
            || max(array_column($validation, 'kickoff')) >= min(array_column($test, 'kickoff'))) {
            return ['status' => 'insufficient_evidence', 'reason' => 'overlapping_temporal_windows'];
        }
        $residuals = array_map(fn ($r) => $r['actual_margin'] - $r['model_margin'], $train);
        $reports = ['validation' => $this->evaluate($residuals, $validation), 'test' => $this->evaluate($residuals, $test)];
        $passed = collect($reports)->every(fn ($r) => $r['brier'] <= 0.5 && $r['cover_ece'] <= 0.05);

        $buckets = [];
        $allowed = [];
        foreach (['under20', '20to28', '28to35', '35plus'] as $bucket) {
            $bucketTrain = array_values(array_filter($train, fn ($r) => $this->lineBucket($r['home_line']) === $bucket));
            $bucketValidation = array_values(array_filter($validation, fn ($r) => $this->lineBucket($r['home_line']) === $bucket));
            $bucketTest = array_values(array_filter($test, fn ($r) => $this->lineBucket($r['home_line']) === $bucket));
            $enough = count($bucketTrain) >= 100 && count($bucketValidation) >= 50 && count($bucketTest) >= 50;
            $bucketReports = $enough ? ['validation' => $this->evaluate($residuals, $bucketValidation),
                'test' => $this->evaluate($residuals, $bucketTest)] : [];
            $bucketPassed = $enough && collect($bucketReports)->every(fn ($r) => $r['brier'] <= 0.5 && $r['cover_ece'] <= 0.05);
            $buckets[$bucket] = ['counts' => ['train' => count($bucketTrain), 'validation' => count($bucketValidation),
                'test' => count($bucketTest)], 'validated' => $bucketPassed, 'reports' => $bucketReports];
            if ($bucketPassed) {
                $allowed[] = $bucket;
            }
        }

        return ['status' => 'challenger', 'model_version' => self::VERSION, 'validation_passed' => $passed,
            'counts' => $counts, 'reports' => $reports, 'residuals' => $residuals,
            'line_bucket_reports' => $buckets, 'validated_line_buckets' => $allowed,
            'season_counts' => array_count_values(array_column($rows, 'season')),
            'split_policy' => 'complete_kickoff_dates_temporal_holdouts',
            'trained_through' => max(array_column($train, 'kickoff')), 'evaluated_through' => max(array_column($test, 'kickoff')),
            'model_margin_range' => [min(array_column($train, 'model_margin')), max(array_column($train, 'model_margin'))],
            'promotion_policy' => 'manual_review_and_prospective_shadow_required_no_automatic_promotion'];
    }

    public function lineBucket(float $line): string
    {
        return match (true) {
            abs($line) < 20 => 'under20', abs($line) < 28 => '20to28', abs($line) < 35 => '28to35', default => '35plus',
        };
    }

    private function evaluate(array $residuals, array $rows): array
    {
        $brier = 0;
        $bins = [];
        foreach ($rows as $row) {
            $p = $this->probabilities($residuals, $row['model_margin'], 'home', $row['home_line']);
            $settlement = $row['actual_margin'] + $row['home_line'];
            $actual = [$settlement > 0 ? 1 : 0, $settlement == 0 ? 1 : 0, $settlement < 0 ? 1 : 0];
            foreach (array_values($p) as $i => $probability) {
                $brier += ($probability - $actual[$i]) ** 2;
            }
            $bin = min(9, (int) floor($p['cover_probability'] * 10));
            $bins[$bin][] = [$p['cover_probability'], $actual[0]];
        }
        $ece = 0;
        foreach ($bins as $bin) {
            $ece += abs(array_sum(array_column($bin, 0)) - array_sum(array_column($bin, 1))) / count($rows);
        }

        return ['n' => count($rows), 'brier' => $brier / count($rows), 'cover_ece' => $ece];
    }
}
