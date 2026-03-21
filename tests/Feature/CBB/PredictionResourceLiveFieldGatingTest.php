<?php

use App\Http\Resources\CBB\PredictionResource;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('view-prediction-spread', 'web');
    Permission::findOrCreate('view-prediction-win-probability', 'web');
});

test('hides cbb live fields when game is not live', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-prediction-spread');
    $user->givePermissionTo('view-prediction-win-probability');

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'period' => 2,
        'home_score' => 78,
        'away_score' => 70,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 3.5,
        'predicted_total' => 147.5,
        'win_probability' => 0.62,
        'live_predicted_spread' => 7.5,
        'live_predicted_total' => 151.5,
        'live_win_probability' => 0.88,
        'live_seconds_remaining' => 180,
        'live_updated_at' => now(),
    ])->load('game');

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $data = PredictionResource::make($prediction)->toArray($request);

    expect($data['home_win_probability'])->toBe(0.62)
        ->and($data['away_win_probability'])->toBe(0.38)
        ->and($data['live_predicted_spread'])->toBeNull()
        ->and($data['live_predicted_total'])->toBeNull()
        ->and($data['live_win_probability'])->toBeNull()
        ->and($data['live_seconds_remaining'])->toBeNull()
        ->and($data['live_updated_at'])->toBeNull();
});

test('includes cbb live fields when game is live', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-prediction-spread');
    $user->givePermissionTo('view-prediction-win-probability');

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '04:11',
        'home_score' => 81,
        'away_score' => 71,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 1.5,
        'predicted_total' => 141.5,
        'win_probability' => 0.54,
        'live_predicted_spread' => 10.0,
        'live_predicted_total' => 165.7,
        'live_win_probability' => 0.81,
        'live_seconds_remaining' => 251,
        'live_updated_at' => now(),
    ])->load('game');

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $data = PredictionResource::make($prediction)->toArray($request);

    expect($data['home_win_probability'])->toBe(0.54)
        ->and($data['away_win_probability'])->toBe(0.46)
        ->and($data['live_predicted_spread'])->toBe(10.0)
        ->and($data['live_predicted_total'])->toBe(165.7)
        ->and($data['live_win_probability'])->toBe(0.81)
        ->and($data['live_seconds_remaining'])->toBe(251)
        ->and($data['live_updated_at'])->not->toBeNull();
});
