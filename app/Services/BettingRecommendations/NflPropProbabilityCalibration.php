<?php

namespace App\Services\BettingRecommendations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** Fixed logistic correction, fitted before a locked chronological evaluation period. */
class NflPropProbabilityCalibration
{
    public function report(array $samples, string $validationFrom): array
    {
        $cutoff = CarbonImmutable::parse($validationFrom, 'America/Chicago')->startOfDay();
        $rows = collect($samples)->unique(fn ($r) => $r['game_id'].':'.$r['player_id'].':'.$r['market']);
        $markets = [];
        foreach ($rows->groupBy('market') as $market => $group) {
            $forecastRows = $group->filter(fn ($r) => isset($r['matchup_mean'], $r['legacy_mean'], $r['recent5_mean'], $r['actual']));
            $projection = [];
            foreach (['legacy_mean', 'recent5_mean', 'matchup_mean'] as $model) {
                $projection[$model] = ['n' => $forecastRows->count(), 'mae' => $forecastRows->avg(fn ($r) => abs($r[$model] - $r['actual'])),
                    'bias' => $forecastRows->avg(fn ($r) => $r[$model] - $r['actual'])];
            }
            $valid = $group->filter(fn ($r) => $this->eligible($r));
            $pushes = $valid->filter(fn ($r) => (float) $r['actual'] === (float) $r['quote_line']);
            $decisions = $valid->reject(fn ($r) => (float) $r['actual'] === (float) $r['quote_line']);
            $train = $decisions->filter(fn ($r) => CarbonImmutable::parse($r['labels_available_at'])->lt($cutoff));
            $test = $decisions->filter(fn ($r) => CarbonImmutable::parse($r['game_start_at'])->gte($cutoff));
            $fit = $train->count() >= 100 && $train->pluck('game_id')->unique()->count() >= 20
                && $train->filter(fn ($r) => $r['actual'] > $r['quote_line'])->count() >= 20
                && $train->filter(fn ($r) => $r['actual'] < $r['quote_line'])->count() >= 20
                ? $this->fit($train->all()) : null;
            $baseRate = $train->isEmpty() ? null : $train->avg(fn ($r) => (int) ($r['actual'] > $r['quote_line']));
            $evaluated = $test->map(fn ($r) => [...$r, 'constant_probability' => $baseRate, 'market_probability' => $this->marketProbability($r), 'calibrated_probability' => $fit ? $this->predict($fit, $r['matchup_probability']) : null]);
            $metrics = [];
            foreach (['baseline_probability', 'matchup_probability', 'calibrated_probability', 'constant_probability', 'market_probability'] as $model) {
                $usable = $evaluated->filter(fn ($r) => isset($r[$model]));
                $metrics[$model] = $this->metrics($usable, $model);
            }
            $bets = [];
            $testWithPushes = $valid->filter(fn ($r) => CarbonImmutable::parse($r['game_start_at'])->gte($cutoff))
                ->map(fn ($r) => [...$r, 'calibrated_probability' => $fit ? $this->predict($fit, $r['matchup_probability']) : null]);
            foreach (['baseline_probability', 'matchup_probability', 'calibrated_probability'] as $model) {
                $bets[$model] = $this->returns($testWithPushes, $model);
            }
            $markets[$market] = ['projection_comparison' => $projection, 'quotes_eligible' => $valid->count(), 'pushes' => $pushes->count(),
                'matchup_applied' => $group->where('matchup_status', 'applied')->count(),
                'training_n' => $train->count(), 'training_games' => $train->pluck('game_id')->unique()->count(),
                'test_n' => $test->count(), 'test_games' => $test->pluck('game_id')->unique()->count(),
                'fit' => $fit, 'status' => $fit === null ? 'insufficient_training_sample' : ($test->count() < 50 || $test->pluck('game_id')->unique()->count() < 10 ? 'insufficient_test_sample' : 'retrospective_evaluated'),
                'evaluation' => $metrics, 'price_evaluation' => $bets];
        }

        return ['format' => 'nfl-prop-calibration-report-v1', 'validation_from' => $cutoff->toIso8601String(),
            'policy' => ['fit_min_rows' => 100, 'fit_min_games' => 20, 'test_min_rows' => 50, 'test_min_games' => 10,
                'label_delay_hours' => 24, 'minimum_ev' => 0.05, 'minimum_probability_edge' => 0.03,
                'promotion_requires' => 'Verified immutable prospective probabilities, independent later evaluation, sufficient per-market samples and improvement in Brier/log loss without material price-performance regression.'],
            'markets' => $markets, 'production_promotion' => false,
            'limitations' => ['Reconstructed stats are current revisions, not verified original pregame inputs.',
                'Historical injury and role snapshots are not reconstructed; this isolates the matchup effect on a neutral baseline.',
                'Calibration excludes pushes and predicts conditional non-push probability; returns settle pushes at zero.',
                'No repeated sportsbook quotes count as independent samples. Props within a game remain correlated.',
                'All markets use the same fixed calendar cutoff; test outcomes are never used for fitting or threshold selection.',
                'Prospective validation needs future outcomes; retrospective success is not deployment approval.']];
    }

    public function fit(array $rows): array
    {
        if (count($rows) < 2 || count(array_unique(array_map(fn ($r) => (int) ($r['actual'] > $r['quote_line']), $rows))) < 2) {
            throw new InvalidArgumentException('Calibration fitting requires both outcome classes.');
        }
        $a = 0.0;
        $b = 1.0;
        for ($iteration = 0; $iteration < 80; $iteration++) {
            $ga = $gb = 0.0;
            $haa = $hbb = 0.001;
            $hab = 0.0;
            foreach ($rows as $row) {
                $x = $this->logit($row['matchup_probability']);
                $p = $this->sigmoid($a + $b * $x);
                $error = $p - (int) ($row['actual'] > $row['quote_line']);
                $weight = max(0.000001, $p * (1 - $p));
                $ga += $error;
                $gb += $error * $x;
                $haa += $weight;
                $hab += $weight * $x;
                $hbb += $weight * $x * $x;
            }
            $gb += 0.001 * $b;
            $det = max(0.000001, $haa * $hbb - $hab ** 2);
            $da = max(-0.5, min(0.5, ($hbb * $ga - $hab * $gb) / $det));
            $db = max(-0.5, min(0.5, ($haa * $gb - $hab * $ga) / $det));
            $a = max(-10, min(10, $a - $da));
            $b = max(0, min(10, $b - $db));
            if (abs($da) + abs($db) < 0.000001) {
                break;
            }
        }

        return ['method' => 'logistic_logit_nonnegative_slope', 'intercept' => $a, 'slope' => $b, 'training_n' => count($rows), 'approved' => false];
    }

    public function predict(array $fit, float $probability): float
    {
        return $this->sigmoid($fit['intercept'] + $fit['slope'] * $this->logit($probability));
    }

    private function eligible(array $r): bool
    {
        if (! isset($r['quote_line'], $r['quote_observed_at'], $r['matchup_probability'], $r['baseline_probability'], $r['labels_available_at'], $r['history_max_game_start_at'])) {
            return false;
        }
        $kickoff = CarbonImmutable::parse($r['game_start_at']);
        $observed = CarbonImmutable::parse($r['quote_observed_at']);

        return $observed->lt($kickoff) && $observed->gte($kickoff->subHours(36))
            && CarbonImmutable::parse($r['history_max_game_start_at'])->lt($kickoff)
            && CarbonImmutable::parse($r['labels_available_at'])->gte($kickoff->addDay())
            && $r['matchup_probability'] >= 0 && $r['matchup_probability'] <= 1
            && $r['baseline_probability'] >= 0 && $r['baseline_probability'] <= 1;
    }

    private function metrics(Collection $rows, string $field): array
    {
        $bins = [];
        foreach ($rows as $r) {
            $p = max(0.000001, min(0.999999, $r[$field]));
            $y = (int) ($r['actual'] > $r['quote_line']);
            $i = min(9, (int) floor($p * 10));
            $bins[$i] ??= ['n' => 0, 'predicted_sum' => 0.0, 'wins' => 0];
            $bins[$i]['n']++;
            $bins[$i]['predicted_sum'] += $p;
            $bins[$i]['wins'] += $y;
        }
        ksort($bins);
        $ece = 0.0;
        foreach ($bins as &$bin) {
            $bin['predicted'] = $bin['predicted_sum'] / $bin['n'];
            $bin['actual'] = $bin['wins'] / $bin['n'];
            $ece += $bin['n'] * abs($bin['predicted'] - $bin['actual']);
            unset($bin['predicted_sum']);
        }
        unset($bin);

        return ['n' => $rows->count(), 'brier' => $rows->avg(fn ($r) => ($r[$field] - (int) ($r['actual'] > $r['quote_line'])) ** 2),
            'log_loss' => $rows->avg(fn ($r) => -log(max(0.000001, min(0.999999, $r['actual'] > $r['quote_line'] ? $r[$field] : 1 - $r[$field])))),
            'ece' => $rows->isNotEmpty() ? $ece / $rows->count() : null, 'reliability_bins' => array_values($bins)];
    }

    private function returns(Collection $rows, string $field): array
    {
        $bets = $wins = $losses = $pushes = 0;
        $units = $peak = $drawdown = 0.0;
        $weeks = [];
        foreach ($rows->sortBy('game_start_at') as $r) {
            if (! isset($r[$field])) {
                continue;
            }
            $choices = [];
            foreach (['over' => $r[$field], 'under' => 1 - $r[$field]] as $side => $p) {
                $odds = $r[$side.'_price'] ?? null;
                if (! is_numeric($odds) || abs($odds) < 100) {
                    continue;
                }
                $payout = $odds > 0 ? $odds / 100 : 100 / abs($odds);
                $ev = (1 - ($r['push_probability'] ?? 0)) * ($p * $payout - (1 - $p));
                if ($ev >= 0.05 && $p - 1 / (1 + $payout) >= 0.03) {
                    $choices[] = compact('side', 'payout', 'ev');
                }
            }
            if ($choices === []) {
                continue;
            }
            usort($choices, fn ($a, $b) => $b['ev'] <=> $a['ev']);
            $choice = $choices[0];
            $push = (float) $r['actual'] === (float) $r['quote_line'];
            $win = ! $push && (($r['actual'] > $r['quote_line']) === ($choice['side'] === 'over'));
            $profit = $push ? 0 : ($win ? $choice['payout'] : -1);
            $bets++;
            $wins += (int) $win;
            $pushes += (int) $push;
            $losses += (int) (! $push && ! $win);
            $units += $profit;
            $peak = max($peak, $units);
            $drawdown = max($drawdown, $peak - $units);
            $week = CarbonImmutable::parse($r['game_start_at'])->setTimezone('America/Chicago')->startOfWeek()->toDateString();
            $weeks[$week] = ($weeks[$week] ?? 0) + $profit;
        }

        return ['bets' => $bets, 'wins' => $wins, 'losses' => $losses, 'pushes' => $pushes,
            'units' => $units, 'roi' => $bets ? $units / $bets : null, 'max_drawdown_units' => $drawdown, 'weekly_units' => $weeks];
    }

    private function marketProbability(array $row): ?float
    {
        $values = [];
        foreach (['over_price', 'under_price'] as $side) {
            $price = $row[$side] ?? null;
            if (! is_numeric($price) || abs($price) < 100) {
                return null;
            }
            $values[] = $price > 0 ? 100 / ($price + 100) : abs($price) / (abs($price) + 100);
        }

        return $values[0] / array_sum($values);
    }

    private function logit(float $p): float
    {
        $p = max(0.000001, min(0.999999, $p));

        return log($p / (1 - $p));
    }

    private function sigmoid(float $x): float
    {
        return 1 / (1 + exp(-max(-30, min(30, $x))));
    }
}
