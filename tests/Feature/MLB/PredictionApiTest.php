<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses()->group('mlb', 'api');

beforeEach(function () {
    foreach ([
        'view-mlb-predictions',
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
        'view-prediction-away-elo',
        'view-prediction-home-elo',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

it('returns a single mlb prediction object for the game endpoint', function () {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-mlb-predictions',
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
        'view-prediction-away-elo',
        'view-prediction-home-elo',
    ]);
    Sanctum::actingAs($user);

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'home_team_elo' => 1510,
        'away_team_elo' => 1495,
        'home_pitcher_elo' => 1505,
        'away_pitcher_elo' => 1490,
        'home_combined_elo' => 1508.5,
        'away_combined_elo' => 1493.5,
        'predicted_spread' => 1.5,
        'predicted_total' => 8.0,
        'win_probability' => 0.61,
        'confidence_score' => 61,
    ]);

    $response = $this->getJson("/api/v1/mlb/games/{$game->id}/prediction");

    $response->assertOk()
        ->assertJsonMissingPath('data.0')
        ->assertJsonPath('data.game_id', $game->id)
        ->assertJsonPath('data.home_win_probability', 0.61)
        ->assertJsonPath('data.away_win_probability', 0.39)
        ->assertJsonPath('data.predicted_spread', 1.5)
        ->assertJsonPath('data.predicted_total', 8);
});
