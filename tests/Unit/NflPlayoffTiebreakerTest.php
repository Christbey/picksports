<?php

use App\Services\NFL\NflPlayoffRandom;
use App\Services\NFL\NflPlayoffTiebreaker;

function nflTiebreaker(array $alignments, array $fixtures): NflPlayoffTiebreaker
{
    $teams = [];
    foreach ($alignments as $id => [$conference, $division]) {
        $teams[$id] = ['team_id' => $id, 'conference' => $conference, 'division' => $division, 'sampled_wins' => 0, 'sampled_ties' => 0, 'games' => []];
    }

    foreach ($fixtures as [$home, $away, $points, $touchdowns]) {
        $result = $points[0] <=> $points[1];
        $teams[$home]['sampled_wins'] += (int) ($result > 0);
        $teams[$away]['sampled_wins'] += (int) ($result < 0);
        $teams[$home]['sampled_ties'] += (int) ($result === 0);
        $teams[$away]['sampled_ties'] += (int) ($result === 0);
        $teams[$home]['games'][] = ['opponent' => $away, 'result' => 1 + $result, 'points' => $points, 'touchdowns' => $touchdowns];
        $teams[$away]['games'][] = [
            'opponent' => $home, 'result' => 1 - $result, 'points' => array_reverse($points),
            'touchdowns' => $touchdowns === null ? null : array_reverse($touchdowns),
        ];
    }

    return new NflPlayoffTiebreaker($teams, new NflPlayoffRandom(42));
}

it('seeds division winners before wild cards and reports teams that miss the field', function () {
    $alignments = [9 => ['NFC', 'East']];
    $fixtures = [];
    $divisions = ['East', 'North', 'South', 'West'];
    foreach (range(1, 8) as $teamId) {
        $alignments[$teamId] = ['AFC', $divisions[intdiv($teamId - 1, 2)]];
        foreach (range(1, 8) as $game) {
            $fixtures[] = [$teamId, 9, $game <= 9 - $teamId ? [21, 14] : [14, 21], null];
        }
    }

    $standings = nflTiebreaker($alignments, $fixtures)->seedPlayoffs();
    expect(array_column($standings['fields']['AFC'], 'team_id'))->toBe([1, 3, 5, 7, 2, 4, 6])
        ->and(array_column($standings['fields']['AFC'], 'seed'))->toBe(range(1, 7))
        ->and($standings['teams'][7])->toBe(['seed' => 4, 'made_playoffs' => true, 'division_winner' => true])
        ->and($standings['teams'][2])->toBe(['seed' => 5, 'made_playoffs' => true, 'division_winner' => false])
        ->and($standings['teams'][8])->toBe(['seed' => null, 'made_playoffs' => false, 'division_winner' => false]);
});

it('restarts at head to head when a three-team division tie narrows to two teams', function () {
    $breaker = nflTiebreaker([
        1 => ['AFC', 'East'], 2 => ['AFC', 'East'], 3 => ['AFC', 'East'], 4 => ['AFC', 'East'], 5 => ['NFC', 'East'],
    ], [
        [1, 2, [17, 14], null], [2, 3, [17, 14], null], [3, 1, [17, 14], null],
        [1, 4, [17, 14], null], [2, 4, [17, 14], null], [4, 3, [17, 14], null],
        [5, 1, [41, 3], null], [5, 2, [17, 14], null], [3, 5, [45, 3], null],
    ]);

    expect($breaker->divisionOrder([3, 2, 1])[0])->toBe(1);
});

it('keeps division priority for successive wild cards and applies the head-to-head sweep', function () {
    $breaker = nflTiebreaker([
        1 => ['AFC', 'East'], 2 => ['AFC', 'East'], 3 => ['AFC', 'North'], 4 => ['AFC', 'West'], 5 => ['NFC', 'East'],
    ], [
        [1, 2, [17, 14], null], [3, 1, [17, 14], null], [2, 3, [17, 14], null],
        [3, 4, [17, 14], null], [1, 4, [17, 14], null], [4, 2, [17, 14], null],
        [5, 1, [17, 14], null], [2, 5, [17, 14], null], [5, 3, [17, 14], null], [4, 5, [17, 14], null],
    ]);

    $east = $breaker->divisionOrder([2, 1]);
    expect($east)->toBe([1, 2])
        ->and($breaker->wildCards([$east, [3], [4]], 3))->toBe([3, 1, 4])
        ->and($breaker->homeFieldOrder([4, 1, 3]))->toBe([3, 1, 4]);
});

it('counts ties as half a win and uses strength of victory ahead of points', function () {
    $breaker = nflTiebreaker([
        1 => ['AFC', 'East'], 2 => ['AFC', 'North'], 3 => ['NFC', 'East'],
        4 => ['NFC', 'North'], 5 => ['NFC', 'South'], 6 => ['NFC', 'West'],
    ], [
        [1, 2, [17, 17], null],
        [1, 3, [10, 7], null], [5, 1, [31, 7], null], [3, 6, [21, 7], null],
        [2, 4, [45, 7], null], [5, 2, [14, 10], null], [6, 4, [21, 7], null],
    ]);

    expect($breaker->homeFieldOrder([2, 1]))->toBe([1, 2]);
});

it('requires four common games before comparing cross-division records', function () {
    $breaker = nflTiebreaker([
        1 => ['AFC', 'East'], 2 => ['AFC', 'North'], 3 => ['NFC', 'East'],
        4 => ['NFC', 'East'], 5 => ['NFC', 'North'], 6 => ['NFC', 'South'], 7 => ['NFC', 'West'],
    ], [
        [1, 3, [17, 14], null], [1, 4, [17, 14], null], [5, 1, [17, 14], null], [6, 1, [17, 14], null],
        [3, 2, [17, 14], null], [4, 2, [17, 14], null], [2, 5, [17, 14], null], [2, 7, [17, 14], null],
        [7, 6, [17, 14], null], [7, 5, [17, 14], null],
    ]);

    expect($breaker->homeFieldOrder([1, 2]))->toBe([2, 1]);
});

it('uses scoring ranks and then net touchdowns when earlier criteria tie', function (array $first, array $second, int $winner) {
    $breaker = nflTiebreaker([
        1 => ['AFC', 'East'], 2 => ['AFC', 'North'], 3 => ['NFC', 'East'], 4 => ['NFC', 'North'],
    ], [
        [1, 3, $first, [1, 1]], [1, 4, [14, 24], [2, 3]],
        [2, 3, $second, [3, 1]], [2, 4, [14, 24], [2, 3]],
    ]);

    expect($breaker->homeFieldOrder([1, 2])[0])->toBe($winner);
})->with([
    'points rank takes priority' => [[27, 10], [24, 10], 1],
    'net touchdowns break equal points' => [[24, 10], [24, 10], 2],
]);
