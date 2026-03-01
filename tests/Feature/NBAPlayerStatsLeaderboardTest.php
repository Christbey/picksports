<?php

use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\Team;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function grantNbaPermission(User $user): void
{
    Permission::findOrCreate('view-nba-predictions', 'web');
    $user->givePermissionTo('view-nba-predictions');
}

test('leaderboard endpoint returns player season averages', function () {
    $team = Team::factory()->create();
    $opponent = Team::factory()->create();
    $player = Player::factory()->for($team)->create();

    $games = Game::factory()->count(12)->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);

    foreach ($games as $game) {
        PlayerStat::factory()->create([
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

    $response = $this->getJson('/api/v1/nba/player-stats/leaderboard');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'player_id',
                    'player' => ['id', 'full_name', 'headshot_url', 'position', 'jersey_number', 'team'],
                    'games_played',
                    'points_per_game',
                    'rebounds_per_game',
                    'assists_per_game',
                    'turnovers_per_game',
                    'steals_per_game',
                    'blocks_per_game',
                    'minutes_per_game',
                    'field_goal_percentage',
                    'three_point_percentage',
                    'free_throw_percentage',
                    'estimated_epa_per_game',
                    'estimated_epa_per_36',
                ],
            ],
        ]);

    $entry = collect($response->json('data'))->firstWhere('player_id', $player->id);
    expect($entry)->not->toBeNull()
        ->and($entry['games_played'])->toBe(12)
        ->and((float) $entry['points_per_game'])->toBe(20.0)
        ->and((float) $entry['rebounds_per_game'])->toBe(5.0)
        ->and((float) $entry['assists_per_game'])->toBe(8.0)
        ->and((float) $entry['turnovers_per_game'])->toBe(3.0)
        ->and($entry['player']['full_name'])->toBe($player->full_name);

    expect((float) $entry['estimated_epa_per_game'])->toBe(15.89)
        ->and((float) $entry['estimated_epa_per_36'])->toBe(19.07);
});

test('leaderboard excludes players with fewer than 10 games', function () {
    $team = Team::factory()->create();
    $opponent = Team::factory()->create();
    $playerFew = Player::factory()->for($team)->create();
    $playerMany = Player::factory()->for($team)->create();

    $games = Game::factory()->count(12)->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);

    // Player with only 5 games
    foreach ($games->take(5) as $game) {
        PlayerStat::factory()->create([
            'player_id' => $playerFew->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
        ]);
    }

    // Player with 12 games
    foreach ($games as $game) {
        PlayerStat::factory()->create([
            'player_id' => $playerMany->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
        ]);
    }

    $response = $this->getJson('/api/v1/nba/player-stats/leaderboard');

    $response->assertOk();
    $playerIds = collect($response->json('data'))->pluck('player_id');

    expect($playerIds)->toContain($playerMany->id)
        ->and($playerIds)->not->toContain($playerFew->id);
});

test('nba player stats page requires authentication', function () {
    $response = $this->get(route('nba-player-stats'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the nba player stats page', function () {
    $user = User::factory()->create();
    grantNbaPermission($user);
    $this->actingAs($user);
    $this->withoutVite();

    $response = $this->get(route('nba-player-stats'));
    $response->assertOk();
});
