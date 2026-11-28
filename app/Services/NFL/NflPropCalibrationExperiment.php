<?php

namespace App\Services\NFL;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Offline only. Coefficients never feed the published prop scorer. */
class NflPropCalibrationExperiment
{
    public function report(array $dataset): array
    {
        $groups = [];
        foreach ($dataset['rows'] as $row) {
            if (in_array($row['market'], ['player_reception_yds', 'player_receptions'], true)) {
                $groups[$row['code_version'].':'.$row['market']][] = $row;
            }
        }
        $reports = [];
        foreach ($groups as $key => $rows) {
            // Reserve every observation from the cohort's latest week, even if coverage is partial.
            $testWeek = max(array_column($rows, 'week'));
            $testRows = array_values(array_filter($rows, fn ($r) => $r['week'] === $testWeek));
            $cutoff = min(array_map(fn ($r) => CarbonImmutable::parse($r['kickoff_at'])->getTimestamp(), $testRows));
            $train = $test = $seen = [];
            foreach ($rows as $r) {
                if (isset($seen[$r['outcome_key']])) {
                    throw new InvalidArgumentException('Duplicate outcome inside a code/market cohort.');
                }
                $seen[$r['outcome_key']] = true;
                if ((float) $r['actual'] === (float) $r['line']) {
                    continue;
                }
                if ($r['week'] === $testWeek) {
                    $test[] = $r;
                } elseif (CarbonImmutable::parse($r['kickoff_at'])->getTimestamp() < $cutoff
                    && CarbonImmutable::parse($r['labels_available_at'])->getTimestamp() < $cutoff) {
                    $train[] = $r;
                }
            }
            $trainGames = count(array_unique(array_column($train, 'game_group')));
            $testGames = count(array_unique(array_column($test, 'game_group')));
            if (array_intersect(array_column($train, 'game_group'), array_column($test, 'game_group')) !== []) {
                throw new InvalidArgumentException('Game leaked between train and holdout.');
            }
            $yes = count(array_filter($train, fn ($r) => $r['actual'] > $r['line']));
            $feasible = count($train) >= 100 && $trainGames >= 20 && min($yes, count($train) - $yes) >= 20;
            $report = ['code_market' => $key, 'model_version' => $rows[0]['model_version'],
                'training_n' => count($train), 'training_games' => $trainGames, 'training_over' => $yes,
                'test_week' => $testWeek, 'test_n' => count($test), 'test_games' => $testGames,
                'training_labels_before' => CarbonImmutable::createFromTimestampUTC($cutoff)->toIso8601String(),
                'status' => $feasible ? (count($test) >= 50 && $testGames >= 10 ? 'offline_evaluated' : 'insufficient_holdout') : 'insufficient_training',
                'fits' => [], 'metrics' => []];
            if ($feasible) {
                $base = $yes / count($train);
                $report['fits']['logistic'] = $this->fit($train, 'logistic');
                $report['fits']['beta'] = $this->fit($train, 'beta');
                $paired = array_values(array_filter($test, fn ($r) => $this->marketProbability($r) !== null));
                foreach (['raw', 'market', 'base_rate', 'logistic', 'beta'] as $method) {
                    $predictions = array_map(function ($r) use ($method, $base, $report) {
                        $p = match ($method) {
                            'raw' => $r['probability'], 'market' => $this->marketProbability($r), 'base_rate' => $base,
                            default => $this->predict($report['fits'][$method], $r['probability']),
                        };

                        return [...$r, 'prediction' => $p];
                    }, $paired);
                    $report['metrics'][$method] = $this->metrics($predictions);
                    $report['metrics'][$method]['brier_delta_vs_market_95_interval'] = $this->pairedInterval($predictions);
                }
            }
            $reports[] = $report;
        }

        return ['format' => 'nfl-prop-calibration-experiment-v1', 'dataset_hash' => hash('sha256', json_encode([
            'format' => $dataset['format'] ?? null, 'season' => $dataset['season'] ?? null, 'rows' => $dataset['rows'],
        ], JSON_THROW_ON_ERROR)),
            'policy' => ['holdout' => 'latest_observed_week_kept_whole_per_code_cohort', 'ridge' => 1.0, 'identity_prior' => true,
                'fit_min_rows' => 100, 'fit_min_games' => 20, 'fit_min_each_class' => 20,
                'holdout_min_rows' => 50, 'holdout_min_games' => 10, 'bootstrap_game_resamples' => 500,
                'bet_min_ev' => 0.05, 'bet_min_probability_edge' => 0.03],
            'groups' => $reports, 'production_promotion' => false,
            'limitations' => ['Single chronological holdout per cohort; preliminary feasibility experiment, not model selection approval.',
                'Game-cluster intervals do not establish robustness across weeks; only five prospective weeks exist.',
                'Configuration fingerprints were not frozen historically; code cohorts are never pooled.',
                'Stat discrepancies and ambiguous labels excluded; no missing outcomes imputed.']];
    }

    public function fit(array $rows, string $method): array
    {
        if (! in_array($method, ['logistic', 'beta'], true) || count($rows) < 2
            || count(array_unique(array_map(fn ($r) => (int) ($r['actual'] > $r['line']), $rows))) < 2) {
            throw new InvalidArgumentException('Fit requires a supported method and both outcome classes.');
        }
        $prior = $method === 'beta' ? [0.0, 1.0, 1.0] : [0.0, 1.0];
        $weights = $prior;
        $objective = function ($w) use ($rows, $method, $prior) {
            $loss = 0.0;
            foreach ($rows as $r) {
                $x = $this->design($r['probability'], $method);
                $z = array_sum(array_map(fn ($a, $b) => $a * $b, $w, $x));
                $y = (int) ($r['actual'] > $r['line']);
                $loss += max($z, 0) - $y * $z + log(1 + exp(-abs($z)));
            }
            foreach ($w as $j => $v) {
                $loss += 0.5 * ($v - $prior[$j]) ** 2;
            }

            return $loss;
        };
        for ($iteration = 0; $iteration < 100; $iteration++) {
            $n = count($weights);
            $gradient = array_map(fn ($w, $p) => $w - $p, $weights, $prior);
            $hessian = array_fill(0, $n, array_fill(0, $n, 0.0));
            for ($j = 0; $j < $n; $j++) {
                $hessian[$j][$j] = 1.0;
            }
            foreach ($rows as $r) {
                $x = $this->design($r['probability'], $method);
                $p = $this->predict(['method' => $method, 'weights' => $weights], $r['probability']);
                $y = (int) ($r['actual'] > $r['line']);
                for ($j = 0; $j < $n; $j++) {
                    $gradient[$j] += ($p - $y) * $x[$j];
                    for ($k = 0; $k < $n; $k++) {
                        $hessian[$j][$k] += $p * (1 - $p) * $x[$j] * $x[$k];
                    }
                }
            }
            $step = $this->solve($hessian, $gradient);
            $old = $objective($weights);
            $accepted = false;
            for ($scale = 1.0; $scale >= 0.00001; $scale /= 2) {
                $candidate = array_map(fn ($w, $s) => $w - $scale * $s, $weights, $step);
                for ($j = 1; $j < $n; $j++) {
                    $candidate[$j] = max(0.0, $candidate[$j]);
                }
                if ($objective($candidate) <= $old) {
                    $change = max(array_map(fn ($a, $b) => abs($a - $b), $candidate, $weights));
                    $weights = $candidate;
                    $accepted = true;
                    break;
                }
            }
            if (! $accepted || $change < 0.0000001) {
                break;
            }
        }

        return ['method' => $method, 'weights' => $weights, 'ridge' => 1.0, 'training_n' => count($rows), 'approved' => false];
    }

    public function predict(array $fit, float $p): float
    {
        $x = $this->design($p, $fit['method']);
        $z = array_sum(array_map(fn ($a, $b) => $a * $b, $x, $fit['weights']));

        return 1 / (1 + exp(-max(-30, min(30, $z))));
    }

    private function design(float $p, string $method): array
    {
        if (! is_finite($p) || $p < 0 || $p > 1) {
            throw new InvalidArgumentException('Probability outside [0,1].');
        }
        $p = max(0.000001, min(0.999999, $p));

        return $method === 'beta' ? [1.0, log($p), -log(1 - $p)] : [1.0, log($p / (1 - $p))];
    }

    private function solve(array $a, array $b): array
    {
        $n = count($b);
        for ($i = 0; $i < $n; $i++) {
            $pivot = $i;
            for ($j = $i + 1; $j < $n; $j++) {
                if (abs($a[$j][$i]) > abs($a[$pivot][$i])) {
                    $pivot = $j;
                }
            }
            [$a[$i], $a[$pivot]] = [$a[$pivot], $a[$i]];
            [$b[$i], $b[$pivot]] = [$b[$pivot], $b[$i]];
            $d = $a[$i][$i];
            if (abs($d) < 1e-12) {
                throw new InvalidArgumentException('Singular fit.');
            }
            for ($k = 0; $k < $n; $k++) {
                $a[$i][$k] /= $d;
            }
            $b[$i] /= $d;
            for ($j = 0; $j < $n; $j++) {
                if ($j === $i) {
                    continue;
                }
                $factor = $a[$j][$i];
                for ($k = 0; $k < $n; $k++) {
                    $a[$j][$k] -= $factor * $a[$i][$k];
                }
                $b[$j] -= $factor * $b[$i];
            }
        }

        return $b;
    }

    private function marketProbability(array $r): ?float
    {
        foreach (['over_price', 'under_price'] as $field) {
            if (! is_numeric($r[$field]) || abs($r[$field]) < 100) {
                return null;
            }
        }
        $implied = fn ($o) => $o > 0 ? 100 / ($o + 100) : -$o / (-$o + 100);
        $over = $implied($r['over_price']);

        return $over / ($over + $implied($r['under_price']));
    }

    private function metrics(array $rows): array
    {
        $brier = $loss = $predicted = $wins = $profit = 0.0;
        $bets = 0;
        foreach ($rows as $r) {
            $p = max(0.000001, min(0.999999, $r['prediction']));
            $y = (int) ($r['actual'] > $r['line']);
            $brier += ($p - $y) ** 2;
            $loss -= log($y ? $p : 1 - $p);
            $predicted += $p;
            $wins += $y;
            $choices = [];
            foreach (['over' => $p, 'under' => 1 - $p] as $side => $probability) {
                $o = $r[$side.'_price'];
                $payout = $o > 0 ? $o / 100 : 100 / -$o;
                $ev = (1 - $r['push_probability']) * ($probability * $payout - (1 - $probability));
                if ($ev >= 0.05 && $probability - 1 / (1 + $payout) >= 0.03) {
                    $choices[] = ['ev' => $ev, 'profit' => (($side === 'over') === (bool) $y) ? $payout : -1];
                }
            }
            if ($choices !== []) {
                usort($choices, fn ($a, $b) => $b['ev'] <=> $a['ev']);
                $bets++;
                $profit += $choices[0]['profit'];
            }
        }
        $n = count($rows);

        return ['n' => $n, 'brier' => $n ? $brier / $n : null, 'log_loss' => $n ? $loss / $n : null,
            'mean_probability' => $n ? $predicted / $n : null, 'actual_over_rate' => $n ? $wins / $n : null,
            'nonpush_bets' => $bets, 'nonpush_units' => $profit, 'nonpush_roi' => $bets ? $profit / $bets : null];
    }

    private function pairedInterval(array $rows): ?array
    {
        $games = [];
        foreach ($rows as $r) {
            $y = (int) ($r['actual'] > $r['line']);
            $games[$r['game_group']][] = ($r['prediction'] - $y) ** 2 - ($this->marketProbability($r) - $y) ** 2;
        }
        $games = array_values($games);
        if (count($games) < 2) {
            return null;
        }
        $rng = new Randomizer(new Mt19937(20261125));
        $values = [];
        for ($b = 0; $b < 500; $b++) {
            $sum = $n = 0;
            for ($j = 0; $j < count($games); $j++) {
                $sample = $games[$rng->getInt(0, count($games) - 1)];
                $sum += array_sum($sample);
                $n += count($sample);
            }
            $values[] = $sum / $n;
        }
        sort($values);

        return ['low' => $values[12], 'high' => $values[487], 'unit' => 'game_cluster', 'resamples' => 500];
    }
}
