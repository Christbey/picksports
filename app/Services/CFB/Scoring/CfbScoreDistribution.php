<?php

namespace App\Services\CFB\Scoring;

use InvalidArgumentException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

class CfbScoreDistribution
{
    public function __construct(public readonly array $scores, public readonly int $draws, public readonly bool $reweighted = false)
    {
        if ($draws < 1 || ! $scores || abs(array_sum(array_column($scores, 'probability')) - 1) > 1e-6) {
            throw new InvalidArgumentException('Invalid score distribution');
        }
        foreach ($scores as $row) {
            if ($row['home'] < 0 || $row['away'] < 0 || $row['home'] === $row['away'] || $row['probability'] < 0) {
                throw new InvalidArgumentException('Invalid final score outcome');
            }
        }
    }

    public function summary(): array
    {
        $home = $away = $win = 0;
        foreach ($this->scores as $r) {
            $home += $r['home'] * $r['probability'];
            $away += $r['away'] * $r['probability'];
            if ($r['home'] > $r['away']) {
                $win += $r['probability'];
            }
        }

        return ['home_points' => $home, 'away_points' => $away, 'home_margin' => $home - $away, 'total' => $home + $away, 'home_win_probability' => $win,
            'draws' => $this->draws, 'probability_standard_error_max' => $this->reweighted ? null : sqrt(.25 / $this->draws), 'status' => 'uncalibrated_model_probability'];
    }

    public function market(string $market, string $side, float $line = 0, ?string $participant = null): array
    {
        if (! in_array($market, ['moneyline', 'spread', 'total', 'team_total'], true)) {
            throw new InvalidArgumentException('Unsupported market');
        }
        if (in_array($market, ['moneyline', 'spread'], true) && ! in_array($side, ['home', 'away'], true)) {
            throw new InvalidArgumentException('Invalid team side');
        }
        if (in_array($market, ['total', 'team_total'], true) && ! in_array($side, ['over', 'under'], true)) {
            throw new InvalidArgumentException('Invalid total side');
        }
        if ($market === 'team_total' && ! in_array($participant, ['home', 'away'], true)) {
            throw new InvalidArgumentException('Team total requires a participant');
        }
        $win = $push = $loss = 0;
        foreach ($this->scores as $r) {
            $margin = $r['home'] - $r['away'];
            $value = match ($market) {
                'moneyline' => ($side === 'home' ? $margin : -$margin),
                'spread' => ($side === 'home' ? $margin : -$margin) + $line,
                'total' => ($r['home'] + $r['away'] - $line) * ($side === 'over' ? 1 : -1),
                'team_total' => ($r[$participant] - $line) * ($side === 'over' ? 1 : -1),
            };
            if (abs($value) < 1e-8) {
                $push += $r['probability'];
            } elseif ($value > 0) {
                $win += $r['probability'];
            } else {
                $loss += $r['probability'];
            }
        }

        return ['win' => $win, 'push' => $push, 'loss' => $loss, 'standard_error' => $this->reweighted ? null : sqrt($win * (1 - $win) / $this->draws)];
    }

    public function energyScore(int $home, int $away, int $seed = 71): float
    {
        $first = 0.0;
        $cdf = [];
        $mass = 0.0;
        foreach ($this->scores as $row) {
            $first += $row['probability'] * hypot($row['home'] - $home, $row['away'] - $away);
            $mass += $row['probability'];
            $cdf[] = $mass;
        }
        $rng = new Randomizer(new Xoshiro256StarStar($seed));
        $pick = function () use ($rng, $cdf) {
            $u = $rng->getInt(0, 2147483646) / 2147483647;
            $lo = 0;
            $hi = count($cdf) - 1;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($u < $cdf[$mid]) {
                    $hi = $mid;
                } else {
                    $lo = $mid + 1;
                }
            }

            return $this->scores[$lo];
        };
        $second = 0.0;
        for ($i = 0; $i < 2000; $i++) {
            $a = $pick();
            $b = $pick();
            $second += hypot($a['home'] - $b['home'], $a['away'] - $b['away']);
        }

        return $first - .5 * $second / 2000;
    }

    public function priced(string $market, string $side, float $line, int $price, ?string $participant = null): array
    {
        if (abs($price) < 100) {
            throw new InvalidArgumentException('Invalid American odds');
        }
        $p = $this->market($market, $side, $line, $participant);

        return [...$p, 'ev_per_unit' => $p['win'] * ($price > 0 ? $price / 100 : 100 / abs($price)) - $p['loss'], 'recommendation_status' => 'requires_calibration_and_release'];
    }
}
