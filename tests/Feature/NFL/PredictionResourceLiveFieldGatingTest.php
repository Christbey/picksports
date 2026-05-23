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
    Permission::findOrCreate('view-prediction-betting-value', 'web');
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

test('includes nfl prediction analysis with betting value permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-prediction-betting-value');

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'model_metadata' => [
            'analysis_layer' => [
                'applied' => true,
                'trust_score' => 71.5,
                'bet_classification' => 'no_bet_no_edge',
                'model_signal_classification' => 'strong_model_side',
                'risk_flags' => ['missing_market_edge'],
                'reason_codes' => ['strong_model_signal', 'qb_form_signal'],
                'validated_signals' => [[
                    'name' => 'qb_form_pressure_mismatch',
                    'label' => 'QB Form + Pressure Mismatch',
                    'market' => 'winner',
                    'tier' => 'strong',
                    'sample_size' => 127,
                    'winner_hit_rate' => 73.2,
                    'spread_mae' => 10.1,
                    'codes' => ['qb_form_signal', 'weak_ol_vs_blitz_heavy_defense'],
                    'note' => 'Large-sample winner signal.',
                ]],
                'best_validated_signal' => [
                    'name' => 'qb_form_pressure_mismatch',
                    'label' => 'QB Form + Pressure Mismatch',
                    'market' => 'winner',
                    'tier' => 'strong',
                    'sample_size' => 127,
                    'winner_hit_rate' => 73.2,
                    'spread_mae' => 10.1,
                    'codes' => ['qb_form_signal', 'weak_ol_vs_blitz_heavy_defense'],
                    'note' => 'Large-sample winner signal.',
                ],
                'calculated_edge' => ['spread_points' => 0],
                'analysis_confidence' => ['score' => 71.5, 'label' => 'strong'],
            ],
        ],
    ])->load('game');

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $data = PredictionResource::make($prediction)->toArray($request);

    expect($data['prediction_analysis']['trust_score'])->toBe(71.5)
        ->and($data['prediction_analysis']['model_signal_classification'])->toBe('strong_model_side')
        ->and($data['prediction_analysis']['reason_codes'])->toContain('strong_model_signal')
        ->and($data['prediction_analysis']['best_validated_signal']['winner_hit_rate'])->toBe(73.2)
        ->and($data['prediction_analysis']['validated_signals'][0]['sample_size'])->toBe(127);
});
