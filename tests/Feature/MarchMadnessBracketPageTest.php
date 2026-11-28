<?php

use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;
use App\Support\CbbBracketTree;
use Inertia\Testing\AssertableInertia as Assert;

test('march madness bracket page renders', function () {
    $homeTeam = Team::factory()->create([
        'school' => 'Kansas',
        'mascot' => 'Jayhawks',
        'abbreviation' => 'KU',
    ]);

    $awayTeam = Team::factory()->create([
        'school' => 'Houston',
        'mascot' => 'Cougars',
        'abbreviation' => 'HOU',
    ]);

    Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => config('cbb.statuses.scheduled'),
        'game_date' => now()->addDays(2),
        'season' => 2026,
        'season_type' => 3,
        'is_ncaa_tournament' => true,
        'tournament_id' => 22,
        'tournament_note' => "NCAA Men's Basketball Championship - East Region - 1st Round",
        'tournament_round' => 'round_of_64',
        'tournament_region' => 'East',
        'home_seed' => 1,
        'away_seed' => 2,
        'name' => 'Houston Cougars at Kansas Jayhawks',
    ]);

    $this->withoutVite();

    $this->get('/march-madness-bracket?season=2026')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MarchMadnessBracket')
            ->has('regions', 1)
        );
});

test('march madness bracket page renders tournament games with unresolved teams', function () {
    Game::factory()->create([
        'home_team_id' => null,
        'away_team_id' => null,
        'home_team_display_name' => 'Winner of First Four',
        'away_team_display_name' => 'Duke Blue Devils',
        'home_team_abbreviation' => 'TBD',
        'away_team_abbreviation' => 'DUKE',
        'status' => config('cbb.statuses.scheduled'),
        'game_date' => now()->addDays(2),
        'season' => 2026,
        'season_type' => 3,
        'is_ncaa_tournament' => true,
        'tournament_id' => 22,
        'tournament_note' => "NCAA Men's Basketball Championship - East Region - 1st Round",
        'tournament_round' => 'round_of_64',
        'tournament_region' => 'East',
        'home_seed' => 16,
        'away_seed' => 1,
        'name' => 'Winner of First Four at Duke Blue Devils',
    ]);

    $this->withoutVite();

    $this->get('/march-madness-bracket?season=2026')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MarchMadnessBracket')
            ->has('regions', 1)
            ->where('regions.0.rounds.0.games.0.homeTeam.name', 'Winner of First Four')
            ->where('regions.0.rounds.0.games.0.awayTeam.name', 'Duke Blue Devils')
        );
});

test('bracket probability transport preserves unknown and genuine zero without inventing the opposite side', function (?float $probability) {
    config(['cbb.season.default' => 2026]);
    $game = Game::factory()->create(['season' => 2026, 'season_type' => 3, 'is_ncaa_tournament' => true,
        'tournament_region' => 'East', 'tournament_round' => 'round_of_64', 'home_seed' => 1, 'away_seed' => 16]);
    Prediction::factory()->create(['game_id' => $game->id, 'win_probability' => $probability]);
    $expected = $probability === null ? null : ['homeWinProbability' => $probability, 'awayWinProbability' => round(1 - $probability, 3)];
    $this->withoutVite()->get('/march-madness-bracket')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('regions.0.rounds.0.games.0.prediction', json_decode(json_encode($expected), true)));
    $tree = app(CbbBracketTree::class)->build(collect([$game]), [], 2026);
    expect($tree['regions'][0]['rounds'][0]['matchups'][0]['game']['prediction'])->toBe($expected);
})->with(['missing' => [null], 'observed zero' => [0.0], 'observed valid' => [0.625]]);
