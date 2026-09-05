<?php

use App\Http\Resources\NBA\PredictionResource;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('view-prediction-spread', 'web');
    Permission::findOrCreate('view-prediction-win-probability', 'web');
});

test('hides nba live fields when game is not live', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-prediction-spread');
    $user->givePermissionTo('view-prediction-win-probability');

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'period' => 4,
        'home_score' => 112,
        'away_score' => 104,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 4.5,
        'predicted_total' => 224.5,
        'win_probability' => 0.63,
        'live_predicted_spread' => 8.0,
        'live_predicted_total' => 229.5,
        'live_win_probability' => 0.89,
        'live_seconds_remaining' => 96,
        'live_updated_at' => now(),
    ])->load('game');

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $data = resolvePreparedPredictionResource(PredictionResource::class, $prediction, 'nba', $request);

    expect($data['home_win_probability'])->toBe(0.63)
        ->and($data['away_win_probability'])->toBe(0.37)
        ->and($data['live_predicted_spread'])->toBeNull()
        ->and($data['live_predicted_total'])->toBeNull()
        ->and($data['live_win_probability'])->toBeNull()
        ->and($data['live_seconds_remaining'])->toBeNull()
        ->and($data['live_updated_at'])->toBeNull();
});

test('includes nba live fields when game is live', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-prediction-spread');
    $user->givePermissionTo('view-prediction-win-probability');

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 3,
        'game_clock' => '05:00',
        'home_score' => 97,
        'away_score' => 90,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 2.5,
        'predicted_total' => 219.5,
        'win_probability' => 0.58,
        'live_predicted_spread' => 6.5,
        'live_predicted_total' => 226.0,
        'live_win_probability' => 0.76,
        'live_seconds_remaining' => 1020,
        'live_updated_at' => now(),
    ])->load('game');

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $data = resolvePreparedPredictionResource(PredictionResource::class, $prediction, 'nba', $request);

    expect($data['home_win_probability'])->toBe(0.58)
        ->and($data['away_win_probability'])->toBe(0.42)
        ->and($data['live_predicted_spread'])->toBe(6.5)
        ->and($data['live_predicted_total'])->toBe(226.0)
        ->and($data['live_win_probability'])->toBe(0.76)
        ->and($data['live_seconds_remaining'])->toBe(1020)
        ->and($data['live_updated_at'])->not->toBeNull();
});
