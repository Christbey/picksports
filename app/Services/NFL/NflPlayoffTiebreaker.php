<?php

namespace App\Services\NFL;

class NflPlayoffTiebreaker
{
    private array $records = [];

    private array $combinedRanks = [];

    public function __construct(private readonly array $teams, private readonly NflPlayoffRandom $random)
    {
        foreach ($teams as $id => $team) {
            $this->records[$id] = [2 * $team['sampled_wins'] + $team['sampled_ties'], count($team['games'])];
        }
    }

    /**
     * @return array{teams:array<int,array{seed:int|null,made_playoffs:bool,division_winner:bool}>,fields:array<string,list<array<string,mixed>>>}
     */
    public function seedPlayoffs(): array
    {
        $results = [];
        $conferenceFields = [];

        foreach (['AFC', 'NFC'] as $conference) {
            $conferenceTeams = array_values(array_filter($this->teams, fn (array $team): bool => $team['conference'] === $conference));
            $divisionWinners = [];
            $divisionOrders = [];
            $playoffTeams = [];

            foreach (['East', 'North', 'South', 'West'] as $division) {
                $divisionTeams = array_values(array_filter($conferenceTeams, fn (array $team): bool => $team['division'] === $division));
                if ($divisionTeams === []) {
                    continue;
                }

                $divisionOrder = $this->divisionOrder(array_column($divisionTeams, 'team_id'));
                $divisionWinners[] = array_shift($divisionOrder);
                if ($divisionOrder !== []) {
                    $divisionOrders[] = $divisionOrder;
                }
            }

            $divisionSeeds = $this->homeFieldOrder($divisionWinners);
            $wildCards = $this->wildCards($divisionOrders, 3);
            foreach ([...$divisionSeeds, ...$wildCards] as $index => $teamId) {
                $seed = $index + 1;
                $results[$teamId] = [
                    'seed' => $seed,
                    'made_playoffs' => true,
                    'division_winner' => $index < count($divisionSeeds),
                ];
                $playoffTeams[] = ['seed' => $seed, ...$this->teams[$teamId]];
            }

            foreach ($conferenceTeams as $team) {
                $results[$team['team_id']] ??= [
                    'seed' => null,
                    'made_playoffs' => false,
                    'division_winner' => false,
                ];
            }

            $conferenceFields[$conference] = $playoffTeams;
        }

        return ['teams' => $results, 'fields' => $conferenceFields];
    }

    public function divisionOrder(array $ids): array
    {
        return $this->order($ids, withinDivision: true);
    }

    public function homeFieldOrder(array $ids): array
    {
        return $this->order($ids, withinDivision: false);
    }

    public function wildCards(array $divisionOrders, int $limit): array
    {
        $qualifiers = [];
        while ($divisionOrders !== [] && count($qualifiers) < $limit) {
            $heads = array_column($divisionOrders, 0);
            $winner = $this->winner($heads, withinDivision: false);
            $qualifiers[] = $winner;
            $division = array_search($winner, $heads, true);
            array_shift($divisionOrders[$division]);
            if ($divisionOrders[$division] === []) {
                array_splice($divisionOrders, $division, 1);
            }
        }

        return $qualifiers;
    }

    private function order(array $ids, bool $withinDivision): array
    {
        $ordered = [];
        while ($ids !== []) {
            $winner = $this->winner($ids, $withinDivision);
            $ordered[] = $winner;
            $ids = array_values(array_diff($ids, [$winner]));
        }

        return $ordered;
    }

    private function winner(array $ids, bool $withinDivision): int
    {
        $ids = $this->best($ids, fn (int $id) => $this->percentage(...$this->records[$id]));

        return count($ids) === 1 ? $ids[0] : $this->breakTie($ids, $withinDivision);
    }

    private function breakTie(array $ids, bool $withinDivision): int
    {
        $conference = $this->teams[$ids[0]]['conference'];
        $commonGames = $this->commonGames($ids);
        $headToHead = fn (int $id) => $this->gamePercentage($this->gamesAgainst($id, $ids));
        $divisionRecord = fn (int $id) => $this->gamePercentage($this->divisionGames($id));
        $conferenceRecord = fn (int $id) => $this->gamePercentage($this->conferenceGames($id));
        $commonRecord = fn (int $id) => $this->gamePercentage($commonGames[$id], $withinDivision ? 1 : 4);

        if ($withinDivision) {
            $criteria = [$headToHead, $divisionRecord, $commonRecord, $conferenceRecord];
        } else {
            $headToHead = count($ids) === 2 ? $headToHead : $this->sweep(...);
            $criteria = [$headToHead, $conferenceRecord, $commonRecord];
        }

        $criteria = [
            ...$criteria,
            fn (int $id) => $this->opponentPercentage($id, winsOnly: true),
            fn (int $id) => $this->opponentPercentage($id, winsOnly: false),
            fn (int $id) => $this->combinedRank($id, $conference),
            fn (int $id) => $this->combinedRank($id, 'NFL'),
            fn (int $id) => $this->net($withinDivision ? $commonGames[$id] : $this->conferenceGames($id), 'points'),
            fn (int $id) => $this->net($this->teams[$id]['games'], 'points'),
            fn (int $id) => $this->net($this->teams[$id]['games'], 'touchdowns'),
        ];

        foreach ($criteria as $criterion) {
            $remaining = $this->best($ids, fn (int $id) => $criterion($id, $ids));
            if (count($remaining) === 1) {
                return $remaining[0];
            }
            if (count($remaining) < count($ids)) {
                return $this->breakTie($remaining, $withinDivision);
            }
        }

        return $ids[$this->random->tiebreakIndex(count($ids))];
    }

    private function best(array $ids, callable $measure): array
    {
        $scores = [];
        foreach ($ids as $id) {
            $score = $measure($id);
            if ($score === null) {
                return $ids;
            }
            $scores[$id] = $score;
        }

        $best = max($scores);

        return array_keys(array_filter($scores, fn ($score): bool => $score === $best));
    }

    private function percentage(int $result, int $games): ?float
    {
        return $games > 0 ? $result / (2 * $games) : null;
    }

    private function gamePercentage(array $games, int $minimum = 1): ?float
    {
        return count($games) >= $minimum ? $this->percentage(array_sum(array_column($games, 'result')), count($games)) : null;
    }

    private function gamesAgainst(int $id, array $opponents): array
    {
        return array_filter($this->teams[$id]['games'], fn (array $game): bool => in_array($game['opponent'], $opponents, true));
    }

    private function conferenceGames(int $id): array
    {
        return array_filter($this->teams[$id]['games'], fn (array $game): bool => $this->teams[$game['opponent']]['conference'] === $this->teams[$id]['conference']);
    }

    private function divisionGames(int $id): array
    {
        return array_filter($this->conferenceGames($id), fn (array $game): bool => $this->teams[$game['opponent']]['division'] === $this->teams[$id]['division']);
    }

    private function commonGames(array $ids): array
    {
        $opponents = array_map(fn (int $id) => array_unique(array_column($this->teams[$id]['games'], 'opponent')), $ids);
        $shared = array_intersect(...$opponents);

        return array_combine($ids, array_map(fn (int $id) => $this->gamesAgainst($id, $shared), $ids));
    }

    private function sweep(int $id, array $ids): int
    {
        $wins = $losses = [];
        foreach ($this->gamesAgainst($id, $ids) as $game) {
            if ($game['result'] === 2) {
                $wins[$game['opponent']] = true;
            } elseif ($game['result'] === 0) {
                $losses[$game['opponent']] = true;
            }
        }

        return (int) (count($wins) === count($ids) - 1) - (int) (count($losses) === count($ids) - 1);
    }

    private function opponentPercentage(int $id, bool $winsOnly): ?float
    {
        $results = $games = 0;
        foreach ($this->teams[$id]['games'] as $game) {
            if (! $winsOnly || $game['result'] === 2) {
                [$opponentResults, $opponentGames] = $this->records[$game['opponent']];
                $results += $opponentResults;
                $games += $opponentGames;
            }
        }

        return $this->percentage($results, $games);
    }

    private function net(array $games, string $stat): ?int
    {
        if ($games === []) {
            return null;
        }

        $net = 0;
        foreach ($games as $game) {
            if ($game[$stat] === null) {
                return null;
            }
            $net += $game[$stat][0] - $game[$stat][1];
        }

        return $net;
    }

    private function combinedRank(int $id, string $scope): ?int
    {
        if (! array_key_exists($scope, $this->combinedRanks)) {
            $this->combinedRanks[$scope] = $this->rankPoints($scope);
        }

        return $this->combinedRanks[$scope][$id] ?? null;
    }

    private function rankPoints(string $scope): array
    {
        $points = [];
        foreach ($this->teams as $id => $team) {
            if ($scope !== 'NFL' && $team['conference'] !== $scope) {
                continue;
            }

            $for = $against = 0;
            foreach ($team['games'] as $game) {
                if ($game['points'] === null) {
                    return [];
                }
                $for += $game['points'][0];
                $against += $game['points'][1];
            }
            $points[$id] = [$for, $against];
        }

        return array_map(function (array $teamPoints) use ($points): int {
            $rank = 2;
            foreach ($points as $otherPoints) {
                $rank += (int) ($otherPoints[0] > $teamPoints[0]) + (int) ($otherPoints[1] < $teamPoints[1]);
            }

            return -$rank;
        }, $points);
    }
}
