<?php

use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Support\CbbBracketTree;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class);
uses(RefreshDatabase::class);

test('cbb bracket tree builds seeded regional matchups and advances picked winners', function () {
    $duke = Team::factory()->create(['school' => 'Duke', 'mascot' => 'Blue Devils', 'abbreviation' => 'DUKE']);
    $siena = Team::factory()->create(['school' => 'Siena', 'mascot' => 'Saints', 'abbreviation' => 'SIE']);
    $ohioState = Team::factory()->create(['school' => 'Ohio State', 'mascot' => 'Buckeyes', 'abbreviation' => 'OSU']);
    $tcu = Team::factory()->create(['school' => 'TCU', 'mascot' => 'Horned Frogs', 'abbreviation' => 'TCU']);

    $dukeGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => 3,
        'is_ncaa_tournament' => true,
        'tournament_round' => 'round_of_64',
        'tournament_region' => 'East',
        'home_team_id' => $duke->id,
        'away_team_id' => $siena->id,
        'home_seed' => 1,
        'away_seed' => 16,
        'status' => config('cbb.statuses.scheduled'),
        'name' => 'Siena Saints at Duke Blue Devils',
    ]);

    $ohioStateGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => 3,
        'is_ncaa_tournament' => true,
        'tournament_round' => 'round_of_64',
        'tournament_region' => 'East',
        'home_team_id' => $ohioState->id,
        'away_team_id' => $tcu->id,
        'home_seed' => 8,
        'away_seed' => 9,
        'status' => config('cbb.statuses.scheduled'),
        'name' => 'TCU Horned Frogs at Ohio State Buckeyes',
    ]);

    $tree = app(CbbBracketTree::class)->build(
        Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->whereIn('id', [$dukeGame->id, $ohioStateGame->id])
            ->get(),
        [
            "game:{$dukeGame->id}" => "team:{$duke->id}",
            "game:{$ohioStateGame->id}" => "team:{$ohioState->id}",
            'East-round_of_32-0' => "team:{$duke->id}",
        ],
        2026,
    );

    $east = collect($tree['regions'])->firstWhere('name', 'East');
    $roundOf64 = collect($east['rounds'])->firstWhere('key', 'round_of_64');
    $roundOf32 = collect($east['rounds'])->firstWhere('key', 'round_of_32');

    expect($tree['scoring']['round_of_64'])->toBe(1)
        ->and($roundOf64['matchups'][0]['participants'][0]['participant']['name'])->toBe('Duke Blue Devils')
        ->and($roundOf64['matchups'][1]['participants'][0]['participant']['name'])->toBe('Ohio State Buckeyes')
        ->and($roundOf32['matchups'][0]['participants'][0]['participant']['name'])->toBe('Duke Blue Devils')
        ->and($roundOf32['matchups'][0]['participants'][1]['participant']['name'])->toBe('Ohio State Buckeyes');
});

test('cbb bracket tree uses configured fallback teams for missing seeded rows', function () {
    $tree = app(CbbBracketTree::class)->build(collect(), [], 2026);

    $west = collect($tree['regions'])->firstWhere('name', 'West');
    $roundOf64 = collect($west['rounds'])->firstWhere('key', 'round_of_64');
    $sixVsEleven = $roundOf64['matchups'][4];

    expect($sixVsEleven['participants'][0]['participant']['name'])->toBe('BYU Cougars')
        ->and($sixVsEleven['participants'][0]['participant']['seed'])->toBe(6)
        ->and($sixVsEleven['participants'][1]['participant']['seed'])->toBe(11);
});
