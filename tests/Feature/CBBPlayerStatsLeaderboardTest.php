<?php

use App\Models\CBB\Player;
use App\Models\CBB\PlayerStat;
use App\Models\User;
use Database\Factories\CbbGameFactory;
use Database\Factories\CbbTeamFactory;
use Laravel\Sanctum\Sanctum;

test('cbb leaderboard endpoint includes estimated epa fields', function () {
    $team = CbbTeamFactory::new()->create();
    $opponent = CbbTeamFactory::new()->create();
    $player = Player::query()->create([
        'team_id' => $team->id,
        'espn_id' => 'cbb-test-player-1',
        'full_name' => 'CBB EPA Test Player',
        'first_name' => 'CBB',
        'last_name' => 'Player',
    ]);

    $games = CbbGameFactory::new()->count(12)->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);

    foreach ($games as $game) {
        PlayerStat::query()->create([
            'player_id' => $player->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'minutes_played' => '30',
            'points' => 20,
            'rebounds_total' => 5,
            'assists' => 8,
            'steals' => 2,
            'blocks' => 1,
            'turnovers' => 3,
            'field_goals_made' => 8,
            'field_goals_attempted' => 15,
            'free_throws_made' => 4,
            'free_throws_attempted' => 5,
        ]);
    }

    $response = $this->getJson('/api/v2/sports/cbb/leaderboards/players');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'player_id',
                    'estimated_epa_per_game',
                    'estimated_epa_per_36',
                ],
            ],
        ]);

    $entry = collect($response->json('data'))->firstWhere('player_id', $player->id);

    expect($entry)->not->toBeNull();
    expect((float) $entry['estimated_epa_per_game'])->toBe(14.17)
        ->and((float) $entry['estimated_epa_per_36'])->toBe(17.0);
});

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

test('cbb threes per game respects season filters and missing values', function (array $made, ?float $expected) {
    $team = CbbTeamFactory::new()->create();
    $opponent = CbbTeamFactory::new()->create();
    $player = Player::query()->create([
        'team_id' => $team->id,
        'espn_id' => 'cbb-threes-player',
        'full_name' => 'Threes Test Player',
    ]);

    foreach ([...array_map(fn ($value) => [2027, 2, $value], $made), [2026, 2, 10], [2027, 3, 10]] as [$season, $seasonType, $threes]) {
        $game = CbbGameFactory::new()->create([
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'season' => $season,
            'season_type' => $seasonType,
        ]);
        PlayerStat::query()->create([
            'player_id' => $player->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'three_point_made' => $threes,
        ]);
    }

    $response = $this->getJson('/api/v2/sports/cbb/leaderboards/players?season=2027&season_type=2&min_games=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.games_played', count($made));

    $actual = $response->json('data.0.three_pointers_made_per_game');
    expect($actual === null ? null : (float) $actual)->toBe($expected);
})->with([
    'made threes and scoreless games' => [[0, 2, 3], 1.7],
    'missing game excluded from average' => [[null, 2, 3], 2.5],
    'recorded zero' => [[0, 0], 0.0],
    'all values missing' => [[null, null], null],
]);
