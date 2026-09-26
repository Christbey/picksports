<?php

namespace App\Services\CFB\Scoring;

use Carbon\CarbonImmutable;
use DomainException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

class CfbPossessionScorer
{
    private function predict(array $model, array $x): float
    {
        $value = $model['coefficients']['intercept'] ?? 0;
        foreach ($x as $key => $v) {
            $value += $v * ($model['coefficients'][$key] ?? 0);
        }

        return $value;
    }

    public function simulate(array $artifact, array $game, int $draws = 50000, int $seed = 7, bool $benchmark = false): CfbScoreDistribution
    {
        if (($artifact['schema'] ?? '') !== 'cfb-possession-v1' || $draws < 100 || $draws > 1000000) {
            throw new DomainException('Invalid CFB artifact or draw count');
        }
        if (CarbonImmutable::parse($artifact['trained_before'])->gte(CarbonImmutable::parse($game['kickoff']))) {
            throw new DomainException('Artifact includes target period');
        }
        $rng = new Randomizer(new Xoshiro256StarStar($seed));
        $models = $artifact['models'];
        $pace = [];
        $outcomes = [];
        $means = [];
        $adjust = ['home' => 0.0, 'away' => 0.0];
        if (! empty($artifact['signal_model'])) {
            $effect = [];
            foreach (['spread', 'total'] as $market) {
                $x = array_filter($game['signals'][$market] ?? [], fn ($key) => ! ($game['signal_missing'][$key] ?? true), ARRAY_FILTER_USE_KEY);
                $effect[$market] = $this->predict($artifact['signal_model'][$market], $x);
            }
            $adjust = ['home' => ($effect['total'] + $effect['spread']) / 2, 'away' => ($effect['total'] - $effect['spread']) / 2];
        }
        foreach (['home' => 'away', 'away' => 'home'] as $side => $other) {
            $id = (string) $game[$side.'_id'];
            $opponent = (string) $game[$other.'_id'];
            if (! isset($artifact['effective_drives'][$id])) {
                throw new DomainException('Opponent has no connected observed history');
            }
            $pace[$side] = max(1, $this->predict($models['pace'], ['team:'.$id => 1]));
            $x = ['offense:'.$id => 1, 'defense:'.$opponent => 1, 'home' => (float) ($side === 'home' && ! ($game['neutral'] ?? false))];
            $efficiency = [];
            foreach ($artifact['efficiency_keys'] ?? ['pass', 'rush'] as $key) {
                $efficiency[$key.'_eff'] = $this->predict($models[$key], $x);
            }
            $x += $efficiency;
            $mean = $this->predict($models['drive'], $x) + $adjust[$side] / $pace[$side];
            if ($benchmark) {
                $h = $game['fpi']['home'] ?? null;
                $a = $game['fpi']['away'] ?? null;
                if (! is_numeric($h) || ! is_numeric($a) || ! isset($models['fpi_margin'], $models['fpi_total'])) {
                    throw new DomainException('Missing original FPI benchmark evidence');
                }
                $fx = ['difference' => $h - $a, 'absolute_difference' => abs($h - $a), 'home' => (float) ! ($game['neutral'] ?? false)];
                $margin = $this->predict($models['fpi_margin'], $fx);
                $total = $this->predict($models['fpi_total'], $fx);
                $mean = ($total + ($side === 'home' ? $margin : -$margin)) / 2 / $pace[$side];
            }
            $means[$side] = $mean;
            $outcomes[$side] = $this->tilt($artifact['outcomes'], $mean);
        }
        $patterns = [];
        foreach ($artifact['patterns'] as $patternIndex => $pattern) {
            $counts = array_count_values($pattern);
            $weight = -.5 / max(1, $artifact['pace_variance']) * ((($counts['home'] ?? 0) - $pace['home']) ** 2 + (($counts['away'] ?? 0) - $pace['away']) ** 2);
            $patterns[] = ['pattern' => $pattern, 'periods' => $artifact['pattern_periods'][$patternIndex] ?? null, 'probability' => $weight];
        }
        $largest = max(array_column($patterns, 'probability'));
        foreach ($patterns as &$pattern) {
            $pattern['probability'] = exp($pattern['probability'] - $largest);
        }
        unset($pattern);
        $patterns = $this->cdf($patterns);
        $ot = [];
        foreach ($artifact['overtime'] as $observation) {
            $state = CfbOvertimeRules::state($observation['season'], $observation['round']);
            if (! CfbOvertimeRules::validOutcome($state, $observation['points'], $observation['opponent_points'])) {
                throw new DomainException('Invalid observed overtime outcome');
            }
            $ot[json_encode($state)][] = $observation;
        }
        $scores = [];
        $dynamic = [];
        for ($i = 0; $i < $draws; $i++) {
            $points = ['home' => 0, 'away' => 0];
            $selected = $this->pick($rng, $patterns);
            $pattern = $selected['pattern'];
            foreach ($pattern as $index => $side) {
                $other = $side === 'home' ? 'away' : 'home';
                $late = $selected['periods'] ? $selected['periods'][$index] === 4 : $index >= .75 * count($pattern);
                $margin = $points[$side] - $points[$other];
                $key = $side.':'.($late ? $margin : 0).':'.(int) $late;
                if (! isset($dynamic[$key])) {
                    $correction = $late && ! $benchmark ? max(0, $margin) / 7 * ($models['drive']['coefficients']['late_lead'] ?? 0)
                        + max(0, -$margin) / 7 * ($models['drive']['coefficients']['late_trail'] ?? 0) : 0;
                    $dynamic[$key] = $this->tilt($artifact['outcomes'], $means[$side] + $correction);
                }
                $result = $this->pick($rng, $dynamic[$key]);
                $other = $side === 'home' ? 'away' : 'home';
                $points[$side] += $result['points'];
                $points[$other] += $result['opponent_points'];
            }
            $round = 1;
            while ($points['home'] === $points['away']) {
                if ($round > 100) {
                    throw new DomainException('Degenerate overtime artifact');
                }
                $choices = $ot[json_encode(CfbOvertimeRules::state($game['season'], $round))] ?? [];
                if (! $choices) {
                    throw new DomainException('No observed overtime outcomes for applicable rule state');
                }
                $first = $round % 2 ? 'home' : 'away';
                foreach ([$first, $first === 'home' ? 'away' : 'home'] as $side) {
                    $result = $choices[$rng->getInt(0, count($choices) - 1)];
                    $other = $side === 'home' ? 'away' : 'home';
                    $points[$side] += $result['points'];
                    $points[$other] += $result['opponent_points'];
                    if ($result['opponent_points'] && ! $result['points']) {
                        break;
                    }
                }
                $round++;
            }
            $key = $points['home'].':'.$points['away'];
            $scores[$key] ??= [...$points, 'probability' => 0];
            $scores[$key]['probability'] += 1 / $draws;
        }
        $weight = (float) ($artifact['fpi_weight'] ?? 0.0);
        if (! $benchmark && $weight > 0) {
            if ($weight > 1) {
                throw new DomainException('Invalid fitted FPI blend');
            }
            $reference = $this->simulate($artifact, $game, $draws, $seed + 101, true);
            foreach ($scores as &$row) {
                $row['probability'] *= 1 - $weight;
            }
            unset($row);
            foreach ($reference->scores as $row) {
                $key = $row['home'].':'.$row['away'];
                $scores[$key] ??= ['home' => $row['home'], 'away' => $row['away'], 'probability' => 0];
                $scores[$key]['probability'] += $weight * $row['probability'];
            }
        }
        $temperature = $benchmark ? 1.0 : (float) ($artifact['calibration_temperature'] ?? 1.0);
        if ($temperature < .5 || $temperature > 2) {
            throw new DomainException('Invalid calibration temperature');
        }
        $sum = 0.0;
        foreach ($scores as &$row) {
            $row['probability'] **= 1 / $temperature;
            $sum += $row['probability'];
        }
        unset($row);
        foreach ($scores as &$row) {
            $row['probability'] /= $sum;
        }
        unset($row);
        ksort($scores);

        return new CfbScoreDistribution(array_values($scores), $draws, $temperature !== 1.0);
    }

    private function tilt(array $outcomes, float $target): array
    {
        if (! $outcomes) {
            throw new DomainException('No observed drive outcomes');
        }
        $min = min(array_column($outcomes, 'points'));
        $max = max(array_column($outcomes, 'points'));
        $target = $max > $min ? max($min + .0001, min($max - .0001, $target)) : $min;
        $low = -20;
        $high = 20;
        for ($iteration = 0; $iteration < 60; $iteration++) {
            $mid = ($low + $high) / 2;
            $logs = [];
            foreach ($outcomes as $o) {
                if ($o['weight'] <= 0) {
                    throw new DomainException('Invalid outcome weight');
                } $logs[] = log($o['weight']) + $mid * $o['points'];
            }
            $largest = max($logs);
            $weights = array_map(fn ($v) => exp($v - $largest), $logs);
            $sum = array_sum($weights);
            $mean = 0;
            foreach ($outcomes as $k => $o) {
                $mean += $o['points'] * $weights[$k] / $sum;
            }
            if ($mean < $target) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }
        foreach ($outcomes as $k => &$o) {
            $o['probability'] = $weights[$k] / $sum;
        }
        unset($o);

        return $this->cdf($outcomes);
    }

    private function cdf(array $rows): array
    {
        $sum = array_sum(array_column($rows, 'probability'));
        if ($sum <= 0) {
            throw new DomainException('No probability mass');
        }
        $running = 0;
        foreach ($rows as &$row) {
            $running += $row['probability'] / $sum;
            $row['cdf'] = $running;
        }
        unset($row);

        return $rows;
    }

    private function pick(Randomizer $rng, array $rows): array
    {
        $u = $rng->getInt(0, 2147483646) / 2147483647;
        $lo = 0;
        $hi = count($rows) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($u < $rows[$mid]['cdf']) {
                $hi = $mid;
            } else {
                $lo = $mid + 1;
            }
        }

        return $rows[$lo];
    }
}
