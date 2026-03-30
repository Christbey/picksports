<?php

use App\Actions\MLB\CalculateTeamMetrics;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use App\Models\MLB\TeamStat;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses()->group('mlb', 'team-metrics', 'api');

beforeEach(function () {
    $this->action = new CalculateTeamMetrics;

    Permission::findOrCreate('view-mlb-predictions', 'web');
});

it('stores separate mlb team metrics rows per season type', function () {
    $team = Team::factory()->create(['elo_rating' => 1500]);
    $opponent = Team::factory()->create(['elo_rating' => 1500]);

    $springGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.spring_training', 1),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-10',
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $team->id,
        'game_id' => $springGame->id,
        'runs' => 8,
        'hits' => 12,
        'at_bats' => 34,
    ]);
    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $springGame->id,
        'runs' => 4,
    ]);

    $regularGame = Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-27',
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);

    TeamStat::factory()->create([
        'team_id' => $team->id,
        'game_id' => $regularGame->id,
        'runs' => 5,
        'hits' => 9,
        'at_bats' => 33,
    ]);
    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $regularGame->id,
        'runs' => 3,
    ]);

    $springMetric = $this->action->execute($team, 2026, (string) config('mlb.season.types.spring_training', 1));
    $regularMetric = $this->action->execute($team, 2026, (string) config('mlb.season.types.regular', 2));

    expect($springMetric)->not->toBeNull()
        ->and($regularMetric)->not->toBeNull()
        ->and($springMetric->id)->not->toBe($regularMetric->id)
        ->and((string) $springMetric->season_type)->toBe((string) config('mlb.season.types.spring_training', 1))
        ->and((string) $regularMetric->season_type)->toBe((string) config('mlb.season.types.regular', 2))
        ->and(TeamMetric::query()->where('team_id', $team->id)->where('season', 2026)->count())->toBe(2);
});

it('filters mlb team metrics index by stored season type rows', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-mlb-predictions');
    Sanctum::actingAs($user);

    $team = Team::factory()->create();

    TeamMetric::query()->create([
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.spring_training', 1),
        'offensive_rating' => 110,
    ]);

    TeamMetric::query()->create([
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'offensive_rating' => 140,
    ]);

    $response = $this->getJson('/api/v1/mlb/team-metrics?season=2026&season_type=1');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.season_type', (string) config('mlb.season.types.spring_training', 1))
        ->assertJsonPath('data.0.offensive_rating', 110);
});

it('applies season-type-aware records on the mlb team metrics index', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-mlb-predictions');
    Sanctum::actingAs($user);

    $team = Team::factory()->create();
    $opponent = Team::factory()->create();

    Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.spring_training', 1),
        'status' => 'STATUS_FINAL',
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'home_score' => 8,
        'away_score' => 2,
        'game_date' => '2026-03-10',
    ]);

    Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'home_score' => 5,
        'away_score' => 3,
        'game_date' => '2026-03-27',
        'week' => 13,
    ]);

    Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'home_team_id' => $opponent->id,
        'away_team_id' => $team->id,
        'home_score' => 6,
        'away_score' => 1,
        'game_date' => '2026-03-28',
        'week' => 13,
    ]);

    TeamMetric::query()->create([
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.spring_training', 1),
        'offensive_rating' => 110,
    ]);

    TeamMetric::query()->create([
        'team_id' => $team->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'offensive_rating' => 140,
    ]);

    $response = $this->getJson('/api/v1/mlb/team-metrics?season=2026&season_type=2');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.season_type', (string) config('mlb.season.types.regular', 2))
        ->assertJsonPath('data.0.wins', 1)
        ->assertJsonPath('data.0.losses', 1);
});
