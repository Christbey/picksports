<?php

use App\Models\User;
use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;
use App\Models\WCBB\Team;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses()->group('wcbb', 'api');

beforeEach(function () {
    foreach ([
        'view-wcbb-predictions',
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
        'view-prediction-away-elo',
        'view-prediction-home-elo',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

it('returns a single wcbb prediction object for the game endpoint', function () {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-wcbb-predictions',
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

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 4.5,
        'predicted_total' => 141.5,
        'win_probability' => 0.64,
        'confidence_score' => 74,
        'home_elo' => 1600,
        'away_elo' => 1540,
        'home_off_eff' => 108.1,
        'home_def_eff' => 97.2,
        'away_off_eff' => 101.6,
        'away_def_eff' => 99.4,
    ]);

    $response = $this->getJson("/api/v1/wcbb/games/{$game->id}/prediction");

    $response->assertOk()
        ->assertJsonMissingPath('data.0')
        ->assertJsonPath('data.game_id', $game->id)
        ->assertJsonPath('data.home_win_probability', 0.64)
        ->assertJsonPath('data.away_win_probability', 0.36)
        ->assertJsonPath('data.confidence_level', 'medium');
});
