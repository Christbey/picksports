<?php

namespace App\Services\NFL\Matchups;

use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Descriptive context only; never an additional prediction vote. */
final class NflEpaMatchupContext
{
    public function sample(array $sample, Collection $games): array
    {
        $dates = $games->only($sample['game_ids'] ?? [])->pluck('game_date')->map(fn ($date) => $date->toDateString());

        return [...$sample, 'through_date' => $dates->max()];
    }

    public function recent(array $samples, Collection $games): ?array
    {
        if (count($samples) < 3) {
            return null;
        }
        $recent = collect($samples)->sortByDesc(fn ($sample, $id) => $games->get($id)->game_date->getTimestamp())->take(3);

        return ['value' => $recent->sum('sum') / $recent->sum('count'), 'games' => 3,
            'plays' => $recent->sum('count'), 'game_ids' => $recent->keys()->all()];
    }

    public function uncertainty(array $games): ?array
    {
        if (count($games) < 3) {
            return null;
        }
        $games = array_values($games);
        $rng = new Randomizer(new Mt19937(20261024));
        $draws = [];
        for ($i = 0; $i < 1000; $i++) {
            $sum = 0;
            $count = 0;
            foreach ($games as $_) {
                $game = $games[$rng->getInt(0, count($games) - 1)];
                $sum += $game['sum'];
                $count += $game['count'];
            }
            $draws[] = $sum / $count;
        }
        sort($draws, SORT_NUMERIC);

        return ['lower' => $draws[49], 'upper' => $draws[949], 'draws' => 1000,
            'method' => 'game_cluster_bootstrap_percentile_90',
            'interpretation' => 'Descriptive sampling uncertainty from resampling whole games; not a calibrated predictive interval or rank confidence.'];
    }
}
