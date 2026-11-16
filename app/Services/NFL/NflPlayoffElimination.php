<?php

namespace App\Services\NFL;

class NflPlayoffElimination
{
    /** @return list<int> */
    public function eliminatedTeams(array $teams, array $games): array
    {
        $points = $remaining = array_fill_keys(array_column($teams, 'team_id'), 0);
        $unplayed = [];
        foreach ($games as $game) {
            $home = $game['home'];
            $away = $game['away'];
            if ($game['completed']) {
                $result = (int) $game['home_score'] <=> (int) $game['away_score'];
                $points[$home] += 1 + $result;
                $points[$away] += 1 - $result;
            } else {
                $remaining[$home]++;
                $remaining[$away]++;
                $unplayed[] = [$home, $away];
            }
        }

        $eliminated = [];
        foreach ($teams as $team) {
            $target = $team['team_id'];
            $maximum = $points[$target] + 2 * $remaining[$target];
            $divisions = $limits = $outsideDivision = $forcedAhead = [];
            foreach ($teams as $opponent) {
                $id = $opponent['team_id'];
                if ($id === $target || $opponent['conference'] !== $team['conference']) {
                    continue;
                }
                $divisions[$id] = $opponent['division'];
                $limits[$id] = $maximum - $points[$id];
                if ($opponent['division'] !== $team['division']) {
                    $outsideDivision[$id] = true;
                }
                if ($limits[$id] < 0) {
                    $forcedAhead[$id] = true;
                }
            }

            // Give the target every remaining win and let the opposite conference
            // win its other interconference games. Neither choice hurts its record rank.
            $gamesInConference = array_filter($unplayed, fn (array $game): bool => isset($limits[$game[0]], $limits[$game[1]]));
            if (array_diff_key($forcedAhead, $outsideDivision) === []
                && $this->blockedTeams($gamesInConference, $limits, $outsideDivision) === null) {
                continue;
            }
            $visited = [];
            if (! $this->wildCardPossible($gamesInConference, $limits, $divisions, $forcedAhead, $visited)) {
                $eliminated[] = $target;
            }
        }

        return $eliminated;
    }

    private function wildCardPossible(array $games, array $limits, array $divisions, array $ahead, array &$visited): bool
    {
        // One team ahead in each represented division can take its division berth.
        if (count($ahead) - count(array_unique(array_intersect_key($divisions, $ahead))) > 2) {
            return false;
        }
        ksort($ahead);
        $key = implode(',', array_keys($ahead));
        if (isset($visited[$key])) {
            return false;
        }
        $visited[$key] = true;

        $blocked = $this->blockedTeams($games, $limits, $ahead);
        if ($blocked === null) {
            return true;
        }

        // A residual minimum cut can only be opened by lifting a team cap on its source side.
        foreach ($blocked as $id) {
            if ($this->wildCardPossible($games, $limits, $divisions, $ahead + [$id => true], $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Allocate two half-win units per game. Integer flow allows both wins and ties.
     *
     * @return list<int>|null Teams whose caps block the flow, or null if all games fit.
     */
    private function blockedTeams(array $games, array $limits, array $uncapped): ?array
    {
        $total = 2 * count($games);
        $source = 'start';
        $sink = 'finish';
        $residual = [];
        foreach ($limits as $id => $limit) {
            $this->addEdge($residual, "team$id", $sink, isset($uncapped[$id]) ? $total : $limit);
        }
        foreach ($games as $index => [$home, $away]) {
            $node = "game$index";
            $this->addEdge($residual, $source, $node, 2);
            $this->addEdge($residual, $node, "team$home", 2);
            $this->addEdge($residual, $node, "team$away", 2);
        }
        while ($total > 0) {
            $queue = [$source];
            $parent = [$source => $source];
            for ($i = 0; $i < count($queue) && ! isset($parent[$sink]); $i++) {
                $from = $queue[$i];
                foreach ($residual[$from] as $to => $capacity) {
                    if ($capacity > 0 && ! isset($parent[$to])) {
                        $parent[$to] = $from;
                        $queue[] = $to;
                    }
                }
            }
            if (! isset($parent[$sink])) {
                return array_values(array_filter(array_keys($limits), fn (int $id): bool => isset($parent["team$id"]) && ! isset($uncapped[$id])));
            }
            $amount = $total;
            for ($to = $sink; $to !== $source; $to = $parent[$to]) {
                $amount = min($amount, $residual[$parent[$to]][$to]);
            }
            for ($to = $sink; $to !== $source; $to = $parent[$to]) {
                $from = $parent[$to];
                $residual[$from][$to] -= $amount;
                $residual[$to][$from] += $amount;
            }
            $total -= $amount;
        }

        return null;
    }

    private function addEdge(array &$residual, string $from, string $to, int $capacity): void
    {
        $residual[$from][$to] = $capacity;
        $residual[$to][$from] = 0;
    }
}
