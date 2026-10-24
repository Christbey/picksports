<?php

namespace App\Services\NFL\Simulation;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

class ScoreDistributionSimulator
{
    public const VERSION = 'nfl-score-distribution-v1';

    public function run(float $margin, float $total, array $residuals, array $markets, int $iterations, int $seed, ?array $provisional = null): array
    {
        if (! is_finite($margin) || ! is_finite($total) || $total < abs($margin) || $total > 200
            || $iterations < 1000 || $iterations > 50000 || $seed < 0 || $seed > 2147483647) {
            throw new InvalidArgumentException('Invalid score means, iteration count or seed.');
        }
        if ($residuals === [] && $provisional === null) {
            throw new InvalidArgumentException('Historical residuals or explicit provisional assumptions are required.');
        }
        if (! array_is_list($residuals)) {
            throw new InvalidArgumentException('Residuals must be a list.');
        }
        foreach ($residuals as $pair) {
            if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2 || ! is_numeric($pair[0]) || ! is_numeric($pair[1]) || ! is_finite((float) $pair[0]) || ! is_finite((float) $pair[1]) || abs($pair[0]) > 200 || abs($pair[1]) > 200) {
                throw new InvalidArgumentException('Residuals must be finite home/away pairs.');
            }
        }
        if ($provisional && (count($provisional) !== 3 || ! is_finite($provisional[0]) || ! is_finite($provisional[1]) || ! is_finite($provisional[2])
            || $provisional[0] <= 0 || $provisional[1] <= 0 || $provisional[0] > 100 || $provisional[1] > 100 || abs($provisional[2]) >= 1)) {
            throw new InvalidArgumentException('Invalid provisional standard deviations or correlation.');
        }
        foreach ($markets as $kind => $quotes) {
            if (! in_array($kind, ['spreads', 'totals'], true)) {
                throw new InvalidArgumentException('Unsupported market.');
            }
            foreach ($quotes as $q) {
                if (! is_numeric($q['line'] ?? null) || ! is_finite((float) $q['line']) || floor($q['line'] * 2) !== (float) ($q['line'] * 2)
                    || ($kind === 'totals' && $q['line'] < 0)
                    || ! in_array($q['side'] ?? null, $kind === 'spreads' ? ['home', 'away'] : ['over', 'under'], true)) {
                    throw new InvalidArgumentException('Expected a valid integer/half-point quote and side.');
                }
            }
        }
        $rng = new Randomizer(new Mt19937($seed));
        $homeMean = ($total + $margin) / 2;
        $awayMean = ($total - $margin) / 2;
        $centerHome = $residuals ? array_sum(array_column($residuals, 0)) / count($residuals) : 0;
        $centerAway = $residuals ? array_sum(array_column($residuals, 1)) / count($residuals) : 0;
        $values = ['home_score' => [], 'away_score' => [], 'margin' => [], 'total' => []];
        $wins = ['home' => 0, 'away' => 0, 'tie' => 0];
        $counts = [];
        $adjusted = 0;
        for ($i = 0; $i < $iterations; $i++) {
            if ($residuals) {
                $pair = $residuals[$rng->getInt(0, count($residuals) - 1)];
                [$dh, $da] = [$pair[0] - $centerHome, $pair[1] - $centerAway];
            } else {
                [$z1, $z2] = $this->normalPair($rng);
                $dh = $provisional[0] * $z1;
                $da = $provisional[1] * ($provisional[2] * $z1 + sqrt(1 - $provisional[2] ** 2) * $z2);
            }
            $h = $this->score($homeMean + $dh);
            $a = $this->score($awayMean + $da);
            $adjusted += (int) ($homeMean + $dh < 0 || $awayMean + $da < 0 || round($homeMean + $dh) === 1.0 || round($awayMean + $da) === 1.0);
            $m = $h - $a;
            $t = $h + $a;
            $values['home_score'][] = $h;
            $values['away_score'][] = $a;
            $values['margin'][] = $m;
            $values['total'][] = $t;
            $wins[$m > 0 ? 'home' : ($m < 0 ? 'away' : 'tie')]++;
            foreach ($markets as $kind => $quotes) {
                foreach ($quotes as $k => $q) {
                    $delta = self::settlementDelta($kind, $q, $m, $t);
                    $outcome = $delta > 0 ? 'win' : ($delta < 0 ? 'loss' : 'push');
                    $counts[$kind][$k] ??= ['win' => 0, 'loss' => 0, 'push' => 0];
                    $counts[$kind][$k][$outcome]++;
                }
            }
        }
        $distributions = [];
        foreach ($values as $key => $samples) {
            sort($samples, SORT_NUMERIC);
            $distributions[$key] = ['mean' => array_sum($samples) / $iterations];
            foreach ([5, 25, 50, 75, 95] as $p) {
                $distributions[$key]['p'.$p] = $samples[(int) floor(($iterations - 1) * $p / 100)];
            }
        }
        $settlements = [];
        foreach ($markets as $kind => $quotes) {
            foreach ($quotes as $k => $quote) {
                $settlements[] = ['market' => $kind, 'quote' => $quote, 'frequencies' => array_map(fn ($n): float => $n / $iterations, $counts[$kind][$k])];
            }
        }

        return ['engine_version' => self::VERSION, 'iterations' => $iterations, 'seed' => $seed,
            'method' => $residuals ? 'centered_paired_residual_bootstrap' : 'provisional_correlated_normal',
            'calibrated' => false, 'bet_eligible' => false, 'target_means' => ['home' => $homeMean, 'away' => $awayMean],
            'history_samples' => count($residuals), 'provisional_assumptions' => $residuals ? null : $provisional,
            'score_adjustment_frequency' => $adjusted / $iterations, 'distributions' => $distributions,
            'outright' => array_map(fn ($n): float => $n / $iterations, $wins), 'markets' => $settlements,
            'limitations' => ['Uncalibrated shadow frequencies; no betting approval.', 'Final-score approximation includes historical overtime; no extra overtime simulation.',
                'Integer rounding, zero clipping and mapping score one to two can shift the target means.', 'Finite historical samples do not describe every tail outcome.']];
    }

    public static function settlementDelta(string $kind, array $quote, int $margin, int $total): float
    {
        return $kind === 'spreads'
            ? ($quote['side'] === 'home' ? $margin : -$margin) + (float) $quote['line']
            : ($quote['side'] === 'over' ? $total - (float) $quote['line'] : (float) $quote['line'] - $total);
    }

    private function score(float $value): int
    {
        $score = max(0, (int) round($value));

        return $score === 1 ? 2 : $score;
    }

    private function normalPair(Randomizer $rng): array
    {
        $u = $rng->getInt(1, 2147483646) / 2147483647;
        $v = $rng->getInt(1, 2147483646) / 2147483647;
        $radius = sqrt(-2 * log($u));

        return [$radius * cos(2 * M_PI * $v), $radius * sin(2 * M_PI * $v)];
    }
}
