<?php

use App\Http\Resources\NFL\PredictionResource;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('view-prediction-spread', 'web');
    Permission::findOrCreate('view-prediction-win-probability', 'web');
});

test('hides nfl live fields when game is not live', function () {
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
        'home_score' => 27,
        'away_score' => 20,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 3.5,
        'predicted_total' => 47.5,
        'win_probability' => 0.62,
        'live_predicted_spread' => 6.5,
        'live_predicted_total' => 49.5,
        'live_win_probability' => 0.91,
        'live_seconds_remaining' => 180,
        'live_updated_at' => now(),
    ])->load('game');

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $data = PredictionResource::make($prediction)->toArray($request);

    expect($data['live_predicted_spread'])->toBeNull()
        ->and($data['live_predicted_total'])->toBeNull()
        ->and($data['live_win_probability'])->toBeNull()
        ->and($data['live_seconds_remaining'])->toBeNull()
        ->and($data['live_updated_at'])->toBeNull();
});

test('includes nfl live fields when game is live', function () {
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
        'home_score' => 21,
        'away_score' => 17,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 2.5,
        'predicted_total' => 44.5,
        'win_probability' => 0.57,
        'live_predicted_spread' => 4.5,
        'live_predicted_total' => 46.5,
        'live_win_probability' => 0.74,
        'live_seconds_remaining' => 1200,
        'live_updated_at' => now(),
    ])->load('game');

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $data = PredictionResource::make($prediction)->toArray($request);

    expect($data['live_predicted_spread'])->not->toBeNull()
        ->and($data['live_predicted_total'])->not->toBeNull()
        ->and($data['live_win_probability'])->not->toBeNull()
        ->and($data['live_seconds_remaining'])->toBe(1200)
        ->and($data['live_updated_at'])->not->toBeNull();
});
