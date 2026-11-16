<?php

use App\Services\NFL\NflPlayoffForecastReport;

it('uses only accepted simulations and averages seeds over playoff appearances', function () {
    $report = new NflPlayoffForecastReport([
        ['team_id' => 1, 'team_name' => 'First', 'conference' => 'AFC', 'division' => 'East'],
        ['team_id' => 2, 'team_name' => 'Second', 'conference' => 'AFC', 'division' => 'East'],
    ], 'test-version');
    $sampled = [
        ['team_id' => 1, 'sampled_wins' => 10, 'sampled_ties' => 1],
        ['team_id' => 2, 'sampled_wins' => 6, 'sampled_ties' => 0],
    ];
    $qualified = ['seed' => 3, 'made_playoffs' => true, 'division_winner' => true];
    $missed = ['seed' => null, 'made_playoffs' => false, 'division_winner' => false];
    $bracket = ['unmatched_games' => [], 'conference_champions' => ['AFC' => 1], 'super_bowl_champion' => 1];

    $report->record($sampled, [1 => $qualified, 2 => $missed], $bracket);
    $report->record($sampled, [1 => $missed, 2 => $missed], [
        'unmatched_games' => [],
        'conference_champions' => [],
        'super_bowl_champion' => null,
    ]);
    $report->record($sampled, [1 => $qualified, 2 => $missed], [
        ...$bracket, 'unmatched_games' => ['1:2' => []],
    ]);

    $built = $report->build(2026, null, false, 3, [], [], []);
    expect($built['summary']['simulations'])->toBe(2)
        ->and($built['summary']['attempted_simulations'])->toBe(3)
        ->and($built['teams'][0]['projected_wins'])->toBe(10.0)
        ->and($built['teams'][0]['projected_ties'])->toBe(1.0)
        ->and($built['teams'][0]['projected_seed'])->toBe(3.0)
        ->and($built['teams'][0]['make_playoffs_probability'])->toBe(0.5)
        ->and($built['teams'][0]['super_bowl_champion_probability'])->toBe(0.5)
        ->and($built['teams'][1]['projected_seed'])->toBeNull();
});

it('returns an empty forecast when all attempted brackets are incompatible', function () {
    $report = new NflPlayoffForecastReport([], 'test-version');
    $built = $report->build(2026, null, false, 100, [], [], []);

    expect($built['teams'])->toBe([])
        ->and($built['projected_bracket'])->toBe([])
        ->and($built['summary']['simulations'])->toBe(0)
        ->and($built['summary']['attempted_simulations'])->toBe(100)
        ->and($built['warnings'])->toHaveCount(1);
});

it('picks the most common field composition before choosing its seed order', function () {
    $divisions = ['East', 'North', 'South', 'West', 'East', 'North', 'West', 'South'];
    $teams = array_map(fn (int $id, string $division): array => [
        'team_id' => $id, 'team_name' => "Team {$id}", 'conference' => 'AFC', 'division' => $division,
        'sampled_wins' => 10, 'sampled_ties' => 0,
    ], range(1, 8), $divisions);
    $report = new NflPlayoffForecastReport($teams, 'test-version');
    $first = [1, 2, 3, 4, 5, 6, 7];
    $second = [2, 1, 3, 4, 5, 6, 7];
    $alternate = [1, 2, 3, 4, 5, 6, 8];
    foreach ([$first, $first, $first, $second, $second, $alternate, $alternate, $alternate, $alternate] as $field) {
        $standings = [];
        foreach ($field as $index => $id) {
            $standings[$id] = ['seed' => $index + 1, 'made_playoffs' => true, 'division_winner' => $index < 4];
        }
        $report->record($teams, $standings, [
            'unmatched_games' => [], 'conference_champions' => [], 'super_bowl_champion' => null,
        ]);
    }

    $built = $report->build(2026, null, false, 9, [], [], []);
    $bracket = $built['projected_bracket'][0];
    expect($bracket['conference'])->toBe('AFC')
        ->and(array_column($bracket['seeds'], 'seed'))->toBe(range(1, 7))
        ->and(array_column($bracket['seeds'], 'team_id'))->toBe($first)
        ->and(array_column($built['teams'], 'wild_card_probability', 'team_id')[7])->toBe(0.5556);
});
