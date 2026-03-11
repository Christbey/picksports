<?php

use App\Http\Resources\CBB\PredictionResource as CbbPredictionResource;
use App\Http\Resources\MLB\PredictionResource as MlbPredictionResource;
use App\Http\Resources\NBA\PredictionResource as NbaPredictionResource;
use App\Models\CBB\Prediction as CbbPrediction;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\NBA\Prediction as NbaPrediction;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach ([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
        'view-prediction-away-elo',
        'view-prediction-home-elo',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

function authorizedPredictionRequest(): Request
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
        'view-prediction-away-elo',
        'view-prediction-home-elo',
    ]);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return $request;
}

test('nba prediction resource exposes shared summary fields for ios clients', function () {
    $prediction = (new NbaPrediction())->forceFill([
        'id' => 1,
        'game_id' => 12,
        'predicted_spread' => 4.5,
        'predicted_total' => 228.5,
        'win_probability' => 0.64,
        'confidence_score' => 74,
        'away_elo' => 1540,
        'home_elo' => 1610,
        'away_off_eff' => 112.1,
        'away_def_eff' => 109.9,
        'home_off_eff' => 115.2,
        'home_def_eff' => 108.4,
    ]);

    $data = NbaPredictionResource::make($prediction)->toArray(authorizedPredictionRequest());

    expect($data)->toMatchArray([
        'id' => 1,
        'game_id' => 12,
        'predicted_spread' => 4.5,
        'predicted_total' => 228.5,
        'win_probability' => 0.64,
        'home_win_probability' => 0.64,
        'away_win_probability' => 0.36,
        'confidence_score' => 74.0,
        'confidence_level' => 'medium',
    ]);
});

test('cbb prediction resource exposes shared home away probability aliases', function () {
    $prediction = (new CbbPrediction())->forceFill([
        'id' => 2,
        'game_id' => 22,
        'predicted_spread' => 2.5,
        'predicted_total' => 147.5,
        'win_probability' => 0.58,
        'confidence_score' => 81,
        'away_elo' => 1510,
        'home_elo' => 1555,
        'away_off_eff' => 111.2,
        'away_def_eff' => 102.4,
        'home_off_eff' => 113.8,
        'home_def_eff' => 101.7,
    ]);

    $data = CbbPredictionResource::make($prediction)->toArray(authorizedPredictionRequest());

    expect($data)->toMatchArray([
        'predicted_spread' => 2.5,
        'predicted_total' => 147.5,
        'win_probability' => 0.58,
        'home_win_probability' => 0.58,
        'confidence_level' => 'high',
    ]);
    expect($data['away_win_probability'])->toBeFloat()
        ->and($data['away_win_probability'])->toEqualWithDelta(0.42, 0.000001);
});

test('mlb prediction resource preserves canonical prediction fields and elo splits', function () {
    $prediction = (new MlbPrediction())->forceFill([
        'id' => 3,
        'game_id' => 33,
        'predicted_spread' => 1.5,
        'predicted_total' => 8.5,
        'win_probability' => 0.55,
        'confidence_score' => 62,
        'away_team_elo' => 1490,
        'away_pitcher_elo' => 1520,
        'away_combined_elo' => 1505,
        'home_team_elo' => 1510,
        'home_pitcher_elo' => 1540,
        'home_combined_elo' => 1525,
    ]);

    $data = MlbPredictionResource::make($prediction)->toArray(authorizedPredictionRequest());

    expect($data)->toMatchArray([
        'predicted_spread' => 1.5,
        'predicted_total' => 8.5,
        'win_probability' => 0.55,
        'confidence_score' => 62.0,
        'away_team_elo' => 1490.0,
        'home_team_elo' => 1510.0,
    ]);
});
